# Tasks: Production Blockers + UX Fixes

> Note: This file is a skeleton reconstructed from context after accidental deletion 2026-08-27. Full content not recoverable from git; production evidence cited below.

---

## Task Organization by Batch

### Batch A — Critical Backend (2 tasks)

- [x] **A1** — #15 cross-semestre regex `/^GC-\d{2}-\d{4}-\d{3}$/` in `OportunidadCsvImportUseCase.php:188`. Reject malformed codes. **Verified** by Feature test.
- [x] **A2** — #14 DetalleOportunidad PUT/DELETE returning 500. Fix FormRequest + controller mass-assignment. **Verified** by Feature test.

### Batch B — Frontend UX (6 PRs: B1–B6)

- [x] **B1** — #1 modal z-index + #9 slide-panel scroll leak. Single PR with SlidePanel portal/`useModalOverlay` refactor.
- [x] **B2** — #2 ContactoFormModal `apellidos` field.
- [x] **B3** — #3 entity→CRM nav via `searchParams.get('entidad_id')` in `CRMPage.tsx:787`.
- [x] **B4** — #16 calendar card `onClick` opens seguimiento modal.
- [x] **B5** — #17 mobile filter sidebar breakpoint (`md` / 768px).
- [x] **B6** — #18 global `<textarea>` CSS `resize: vertical`.

### Batch C — PDF Fixes (3 commits)

- [x] **C1** — #10 contact fallback. Commit `f9c9b10 fix(pdf): cotizacion contact fallback when contacto_id is null (C1)`.
- [x] **C2** — #12 total = subtotal + IVA. Commit `6607368 fix(pdf): cotizacion total = subtotal + IVA (was showing subtotal only) (C2)`.
- [x] **C3** — #13 compact paragraph spacing. Commit `ef371cd fix(pdf): cotizacion compact line-height on observations/payment/aclarations/guarantees (C3)`.

### Batch D — Cleanup

- [x] **D1** — #5 `tecnoinnsoft_crm` schema rename applied across code, docs, and env files.

---

## 11 PRs Total (counted from batches)

| PR | Item | Commit |
|----|------|--------|
| A1 | #15 | (cross-semestre fix) |
| A2 | #14 | (DetalleOportunidad PUT/DELETE fix) |
| B1 | #1 + #9 | (SlidePanel portal refactor) |
| B2 | #2 | (ContactoFormModal apellidos) |
| B3 | #3 | (entity→CRM nav) |
| B4 | #16 | (calendar card onClick) |
| B5 | #17 | (mobile filter breakpoint) |
| B6 | #18 | (textarea resize CSS) |
| C1 | #10 | `f9c9b10` |
| C2 | #12 | `6607368` |
| C3 | #13 | `ef371cd` |
| D1 | #5 | (schema rename) |

(11 PRs across 4 batches.)

---

## Test Coverage

| Layer | Approach |
|-------|----------|
| Backend A1, A2 | PHPUnit Feature tests with `RefreshDatabase` + Sanctum token (per `tests/Feature/API/PlanControllerTest.php` pattern) |
| Frontend B1–B6 | Manual QA only (Vitest deferred — out of scope by design) |
| PDF C1, C2, C3 | `Bar::dompdf` snapshot tests on extracted text |

---

## Production Evidence

- `app/Application/UseCases/Oportunidad/OportunidadCsvImportUseCase.php:188` regex
- `app/Http/Controllers/API/CotizacionController.php` `buildPdfData()` + `resolveClientContact()`
- `dashboard-crm/src/components/SlidePanel.tsx`
- `dashboard-crm/src/components/ContactoFormModal.tsx`
- `dashboard-crm/src/pages/CRMPage.tsx:787`
- `routes/api.php:260` (PDF route)

---

## Outstanding (none)

All 11 PRs merged. All 13 items confirmed by user in daily use.