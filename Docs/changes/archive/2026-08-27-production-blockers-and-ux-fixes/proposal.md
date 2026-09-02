# Proposal: Production Blockers + UX Fixes

> Note: This file is a skeleton reconstructed from context after accidental deletion 2026-08-27. Full content not recoverable from git; production evidence cited below.

| Field | Value |
|-------|-------|
| Status | **Archived (resolved)** |
| Date | 2026-08-27 |
| Items in scope | 13 (2 Critical backend, 7 UX frontend, 3 PDF, 1 Cleanup) |
| Items verified formally | 3 (PDF C1+C2+C3 only) |
| Items confirmed by user | 13/13 |
| Affected repos | crm-laravel + dashboard-crm |

---

## Intent

The change resolves 13 production-blocking items in 4 batches. Critical backend fixes first (issue #15 cross-semestre code, #14 DetalleOportunidad PUT/DELETE), then 7 UX frontend defects (#1 modal z-index, #2 ContactoFormModal apellidos, #3 entity→CRM nav, #9 panel scroll leak, #16 calendar card onClick, #17 mobile filter overlap, #18 textareas not resizable), then 3 PDF fixes (#10 contact fallback, #12 subtotal+IVA total, #13 paragraph spacing), then infra cleanup (#5 tecnoinnsoft_crm rename).

---

## Approach (batching strategy)

Batches are ordered by **risk**: critical backend blockers first to unblock go-live; UX defects next because they erode daily use; PDF third because they impact sales collateral; cleanup last because it is non-functional.

| Batch | Items | Type | PRs |
|-------|-------|------|-----|
| **A — Critical backend** | #15 cross-semestre, #14 DetalleOportunidad PUT/DELETE | Backend | A1, A2 |
| **B — Frontend UX** | #1 modal z-index, #2 ContactoFormModal, #3 entity→CRM nav, #9 panel scroll leak, #16 calendar card onClick, #17 mobile filter overlap, #18 textareas not resizable | Frontend | B1 (#1+#9), B2 (#2), B3 (#3), B4 (#16), B5 (#17), B6 (#18) |
| **C — PDF fixes** | #10 contact fallback, #12 total = subtotal+IVA, #13 paragraph spacing | Backend | C1, C2, C3 |
| **D — Cleanup** | #5 `tecnoinnsoft_crm` rename | Infra | D1 |

### Cross-cutting concerns

- **#1 + #9** are shipped as a single PR **B1** because both rely on the same `SlidePanel` portal/`useModalOverlay` refactor (`dashboard-crm/src/components/SlidePanel.tsx`). Splitting them would have required two separate refactors of the same component.
- **#2** does NOT extract a shared contact form between `ContactoFormModal.tsx` and `DetalleContactoModal.tsx` — this is **intentional divergence**. The two modals serve different validation domains (entity-scoped vs opportunity-scoped); DRY-ing them would couple those domains and complicate testing.
- **#12** uses a **verify-before-fix** SQL audit (`Docs/detalle_oportunidad.csv`) before applying the fix. Without that audit, the IVA heuristic could break for records where `iva=19` means 19% (percentage) vs. `iva=19000` means $19,000 (absolute money).

---

## Out of Scope

- Filesystem move to `minerva/` (separate operation).
- Items #4, #11, phpMyAdmin — already resolved in earlier work; not part of this change.
- Ongoing Minerva rename follow-ups — tracked separately.

---

## Test Strategy

| Layer | Approach |
|-------|----------|
| Backend | PHPUnit with `RefreshDatabase` + Sanctum token pattern (per `tests/Feature/API/PlanControllerTest.php`) |
| Frontend | **No test framework installed** — Vitest setup explicitly deferred (out of scope) |
| PDF | `Bar::dompdf` (Barryvdh\DomPDF) + text-extraction snapshot tests on PDF output |

---

## Production Evidence

| Item | Path |
|------|------|
| #15 cross-semestre regex | `app/Application/UseCases/Oportunidad/OportunidadCsvImportUseCase.php:188` regex `/^GC-\d{2}-\d{4}-\d{3}$/` |
| C1+C2+C3 PDF builder | `app/Http/Controllers/API/CotizacionController.php::buildPdfData()` + `resolveClientContact()` |
| C1 fix commit | `f9c9b10 fix(pdf): cotizacion contact fallback when contacto_id is null (C1)` |
| C2 fix commit | `6607368 fix(pdf): cotizacion total = subtotal + IVA (was showing subtotal only) (C2)` |
| C3 fix commit | `ef371cd fix(pdf): cotizacion compact line-height on observations/payment/aclarations/guarantees (C3)` |
| #1+#9 SlidePanel refactor | `dashboard-crm/src/components/SlidePanel.tsx` |
| #2 ContactoFormModal | `dashboard-crm/src/components/ContactoFormModal.tsx` |
| #3 entity→CRM nav | `dashboard-crm/src/pages/CRMPage.tsx:787` reads `searchParams.get('entidad_id')` |
| PDF route | `routes/api.php:260` `GET /api/v1/oportunidades/{id}/pdf` |
| Cotización data route | `routes/api.php:257` `GET /api/v1/oportunidades/{id}/cotizacion-data` |

---

## Success Criteria

Tied to specific acceptance criteria per item:

- **#15**: regex validates `GC-SS-YYYY-NNN` (semester digits required); rejects `GC-1-2026-001` and `GC-01-26-001`.
- **#14**: PUT and DELETE on `/detalles-oportunidad/{id}` return 200 (not 500) for valid payloads.
- **#1**: modal z-index > slide panel z-index; no occlusion in nested flows.
- **#2**: `apellidos` field present in `ContactoFormModal` and submitted with `store()`.
- **#3**: `entidad_id` query param opens that entidad's pipeline view in CRM.
- **#9**: scroll leak from slide panel back to main page is eliminated.
- **#16**: clicking a calendar card opens the seguimiento modal.
- **#17**: filter sidebar does not overlap content on viewport widths ≤ 768px.
- **#18**: `<textarea>` elements have `resize: vertical` CSS.
- **#10 (C1)**: when `contacto_id IS NULL`, "Atención:" line shows entity's most-recently-updated contact (not `—`); if entity has zero contacts, the block disappears gracefully.
- **#12 (C2)**: detalle with `cantidad=1, vr_unitario=100000, iva=19` (19%) yields Subtotal $100,000 / IVA $19,000 / Total $119,000. Absolute-value IVA path: `iva=5000` yields IVA $5,000.
- **#13 (C3)**: multi-line observations in Observaciones / Forma de Pago / Notas Aclaratorias / Garantía render with reasonable vertical spacing, not exaggerated blank space.
- **#5**: `tecnoinnsoft_crm` rename applied; no broken references.