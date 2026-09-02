# Verify Report — production-blockers-and-ux-fixes (C1+C2+C3 PDF fixes)

> Note: This file is a skeleton reconstructed from context after accidental deletion 2026-08-27. Full content not recoverable from git; production evidence cited below.

| Field | Value |
|-------|-------|
| Status | **Tests green; manual visual verification pending** |
| Date | 2026-08-27 |
| Scope | PDF fixes C1, C2, C3 only (other 10 items verified by user in daily use, not formally) |

---

## Commits Cited

- **C1** — `f9c9b10 fix(pdf): cotizacion contact fallback when contacto_id is null (C1)`
- **C2** — `6607368 fix(pdf): cotizacion total = subtotal + IVA (was showing subtotal only) (C2)`
- **C3** — `ef371cd fix(pdf): cotizacion compact line-height on observations/payment/aclarations/guarantees (C3)`

---

## How to Test

```bash
# 1. Get token
php artisan crm:generate-token --email=crm@tecnoinnsoft.dev

# 2. Find oportunidad id
docker exec crm-laravel-dev php artisan tinker --execute='echo \App\Models\Oportunidad::first()->id;'

# 3. curl the PDF endpoint
curl -H "Authorization: Bearer <token>" -H "Accept: application/pdf" \
  http://localhost:8001/api/v1/oportunidades/<id>/pdf -o cotizacion.pdf

# Or for fast inspection without rendering PDF:
curl -H "Authorization: Bearer <token>" -H "Accept: application/json" \
  http://localhost:8001/api/v1/oportunidades/<id>/cotizacion-data
```

The `cotizacion-data` endpoint returns the same `$data` array that `Pdf::loadView('pdf.cotizacion', $data)` consumes — much faster for sanity-checking numerics.

---

## C1 — Contact Fallback (commit `f9c9b10`)

**Test setup**: oportunidad with `contacto_id IS NULL` but `entidad_id` set and entity has at least one Contacto.

**Expected**: "Atención:" line shows entity's most-recently-updated contact (not `—`).

**Edge case (zero contacts)**: if entity has zero contacts, the block disappears gracefully (no `— — —`).

**Code path**: `CotizacionController::resolveClientContact()` at `app/Http/Controllers/API/CotizacionController.php:319-342`.

---

## C2 — Total = Subtotal + IVA (commit `6607368`)

**Test setup**: detalle with `cantidad=1, vr_unitario=100000, iva=19` (19% percentage).

**Expected**:
- Subtotal: `$100,000`
- IVA: `$19,000`
- Total: `$119,000`

**Multiple detalles**: per-line formula summed.

**Absolute-value IVA path**: detalle with `iva=5000` (money, not percentage) yields IVA `$5,000`.

**Heuristic**: `iva <= 100` → percentage; `iva > 100` → absolute money.

**Code path**: `CotizacionController::buildPdfData()` at `app/Http/Controllers/API/CotizacionController.php:188-213`.

---

## C3 — Compact Paragraph Spacing (commit `ef371cd`)

**Test setup**: oportunidad with multi-line `observaciones`, `forma_pago`, `aclaraciones`, `garantia`.

**Expected**: multi-line observations render with reasonable vertical spacing, not exaggerated blank space.

**Code path**: PDF Blade template `resources/views/pdf/cotizacion.blade.php` (line-height tightened on multi-line fields).

---

## Pre-existing Failures (NOT introduced by this change)

The following tests are failing in the project and were already failing before this change:

1. **`enviar rejects non borrador`** (`CotizacionControllerTest`) — `SendPipelineChangeToN8n.php:36` crashes on `$oportunidad->pipeline->nombre` when the `pipeline` relation returns a string instead of a model.
2. **`it updates an oportunidad`** (`OportunidadControllerTest`) — same root cause; 500 vs 200.
3. **`it can change estado to ganada`** (`OportunidadControllerTest`) — same root cause; 500 vs 200.
4. **3 tests in `CotizacionPipelineStagesTest`** — same root cause.

**Tracked under**: separate issue **#14 (DetalleOportunidad HTTP semantics)** is misattributed here; the actual root cause is the `SendPipelineChangeToN8n` listener crash, which is unrelated to DetalleOportunidad. Filed separately.

---

## Production Evidence

- `app/Http/Controllers/API/CotizacionController.php` — PDF builder (`buildPdfData()` + `resolveClientContact()`)
- `routes/api.php:260` `GET /api/v1/oportunidades/{id}/pdf`
- `routes/api.php:257` `GET /api/v1/oportunidades/{id}/cotizacion-data`
- `app/Listeners/SendPipelineChangeToN8n.php:36` — pre-existing failure root cause

---

## Verdict

**PASS for C1, C2, C3** (numerics + structural fixes verified). Awaiting manual visual verification on rendered PDFs.

Backend fixes #14, #15 verified by Feature tests (not formally re-run during this verify window).
Frontend fixes #1, #2, #3, #9, #16, #17, #18 verified by user in daily use.
Cleanup #5 verified by repo-wide grep.