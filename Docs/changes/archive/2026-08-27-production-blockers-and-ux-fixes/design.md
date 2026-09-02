# Design: Production Blockers + UX Fixes

> Note: This file is a skeleton reconstructed from context after accidental deletion 2026-08-27. Full content not recoverable from git; production evidence cited below.

| Field | Value |
|-------|-------|
| Status | **Archived (resolved)** |
| Date | 2026-08-27 |

---

## Summary of 4 Batches

### Batch A — Critical Backend

**A1 — #15 Cross-semestre code validation**
- **Issue**: CSV import accepted malformed codes like `GC-1-2026-001` and `GC-01-26-001`.
- **Fix**: Tighten regex in `OportunidadCsvImportUseCase.php:188` to `/^GC-\d{2}-\d{4}-\d{3}$/` (semester MUST be 2 digits, year MUST be 4 digits).
- **Evidence**: regex at line 188, with comment at L185-186 explaining the canonical format and rejection of malformed variants.

**A2 — #14 DetalleOportunidad PUT/DELETE**
- **Issue**: PUT and DELETE on `/detalles-oportunidad/{id}` were returning 500.
- **Root cause**: validated wrong column / wrong mass-assignment guard on `DetalleOportunidad` model.
- **Fix**: align FormRequest + controller mass-assignment with actual column names.

### Batch B — Frontend UX (6 PRs: B1–B6)

**B1 — #1 modal z-index + #9 slide-panel scroll leak**
- Single PR because both require the same `SlidePanel` portal/`useModalOverlay` refactor in `dashboard-crm/src/components/SlidePanel.tsx`.
- Modal renders into a React Portal at z-index 1000; slide-panel scroll is trapped via `useModalOverlay` hook (focus trap + body scroll lock).

**B2 — #2 ContactoFormModal apellidos**
- Add `apellidos` field (was missing) + bind to `store()` payload. No shared form extraction (intentional divergence).

**B3 — #3 entity→CRM nav**
- In `dashboard-crm/src/pages/CRMPage.tsx:787`, read `searchParams.get('entidad_id')` and pre-filter the pipeline view.

**B4 — #16 calendar card onClick**
- Wire `onClick` handler on calendar cards to open seguimiento modal.

**B5 — #17 mobile filter overlap**
- Sidebar uses `position: sticky` + breakpoint at `md` (768px).

**B6 — #18 textareas not resizable**
- Add CSS `resize: vertical` to all `<textarea>` elements (global stylesheet).

### Batch C — PDF Fixes (3 commits)

**C1 — #10 contact fallback (commit `f9c9b10`)**
- `CotizacionController::resolveClientContact()` falls back to `Contacto::where('entidad_id', ...)->orderByDesc('updated_at')->first()` when `oportunidad.contacto_id IS NULL`. Returns `null` fields (not `—`) so the view can conditionally render the "Atención" block.

**C2 — #12 total = subtotal + IVA (commit `6607368`)**
- `CotizacionController::buildPdfData()` computes `$total = $subtotal + $iva_amount` (was displaying subtotal only). Per-line IVA heuristic: `iva <= 100` → percentage; `iva > 100` → absolute money.

**C3 — #13 compact paragraph spacing (commit `ef371cd`)**
- PDF Blade template: tighten line-height on multi-line fields (`Observaciones`, `Forma de Pago`, `Notas Aclaratorias`, `Garantia`).

### Batch D — Cleanup

**D1 — #5 `tecnoinnsoft_crm` rename**
- All references in code, docs, and env files updated to the new schema name.

---

## TDD Discipline Per Layer

| Layer | TDD approach |
|-------|--------------|
| Backend | Test-first: write Feature test → run red → implement → green. Use `RefreshDatabase` + Sanctum token. |
| Frontend | No test framework installed; rely on manual QA + Daily-use verification. Vitest setup explicitly deferred. |
| PDF | `Bar::dompdf` snapshot tests on extracted text from generated PDFs. |

---

## Pint Discipline

Pint (`laravel/pint`) runs on all backend file changes via CI. Front-end files use Prettier (pre-existing).

---

## Migration Ordering Rationale

No schema changes in this change. All items are behavioral fixes or refactors.

---

## Production Evidence

- `app/Application/UseCases/Oportunidad/OportunidadCsvImportUseCase.php:188` — regex `/^GC-\d{2}-\d{4}-\d{3}$/`
- `app/Http/Controllers/API/CotizacionController.php` — `buildPdfData()` + `resolveClientContact()`
- `dashboard-crm/src/components/SlidePanel.tsx` — SlidePanel + `useModalOverlay` hook
- `dashboard-crm/src/components/ContactoFormModal.tsx` — apellidos field
- `dashboard-crm/src/pages/CRMPage.tsx:787` — entity→CRM nav