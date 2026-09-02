# Archive Report: production-blockers-and-ux-fixes

| Field | Value |
|-------|-------|
| Status | **Archived (resolved)** |
| Date | 2026-08-27 |
| Final branch | merged to main (verify-report cites commits `f9c9b10`, `6607368`, `ef371cd`) |
| Items in scope | 13 (2 Critical backend, 7 UX frontend, 3 PDF, 1 Cleanup) |
| Items verified formally | 3 (PDF C1+C2+C3 only) |
| Items confirmed by user | 13/13 |
| Affected repos | crm-laravel + dashboard-crm |

> Note: This archive-report was written fresh on 2026-08-27 after a parent-side Move/Remove mishap lost the original 5-file change folder (`proposal.md`, `design.md`, `explore.md`, `tasks.md`, `verify-report-c1-c2-c3.md`). The 5 files were NEVER committed to git. The archive folder `2026-08-27-production-blockers-and-ux-fixes` was recreated with skeletons reconstructed from this writer's context + production evidence (commits, source paths, route paths). The reconstruction is best-effort; full original content is unrecoverable.

---

## 1. Executive Summary

The change resolved 13 production-blocking items in 4 batches. Strategy was **risk-ordered**: critical backend fixes first (issues #15 cross-semestre code, #14 DetalleOportunidad PUT/DELETE) to unblock go-live; 7 UX frontend defects next (#1 modal z-index, #2 ContactoFormModal apellidos, #3 entity→CRM nav, #9 panel scroll leak, #16 calendar card onClick, #17 mobile filter overlap, #18 textareas not resizable) because they erode daily use; 3 PDF fixes third (#10 contact fallback C1, #12 subtotal+IVA total C2, #13 paragraph spacing C3) because they impact sales collateral; infra cleanup last (#5 `tecnoinnsoft_crm` rename).

The change shipped 11 PRs across the 4 batches. The single cross-cutting PR was **B1 (#1 + #9)** which combined the SlidePanel portal refactor + `useModalOverlay` hook because both relied on the same component changes — splitting would have required two refactors of the same component.

Backend items (#14, #15) were verified by PHPUnit Feature tests with `RefreshDatabase` + Sanctum token (per `tests/Feature/API/PlanControllerTest.php` pattern). PDF items C1, C2, C3 were verified by `Bar::dompdf` snapshot tests on extracted text plus `cotizacion-data` JSON endpoint inspection. Frontend items were verified by user in daily use (no test framework installed — Vitest setup explicitly deferred as out-of-scope by design).

---

## 2. Items Delivered

| # | Item | Severity | Layer | Status | Evidence |
|---|------|----------|-------|--------|----------|
| 1 | Modal z-index in nested flows | High UX | Frontend | ✅ Done | `dashboard-crm/src/components/SlidePanel.tsx` (portal z-index 1000) |
| 2 | `ContactoFormModal` missing `apellidos` | High UX | Frontend | ✅ Done | `dashboard-crm/src/components/ContactoFormModal.tsx` |
| 3 | entity→CRM nav via `searchParams.get('entidad_id')` | Medium UX | Frontend | ✅ Done | `dashboard-crm/src/pages/CRMPage.tsx:787` |
| 5 | `tecnoinnsoft_crm` rename | Low | Infra | ✅ Done | Repo-wide grep clean |
| 9 | Slide-panel scroll leak | High UX | Frontend | ✅ Done | `SlidePanel.tsx` + `useModalOverlay` hook |
| 10 (C1) | PDF contact fallback | High | PDF | ✅ Done | commit `f9c9b10`; `CotizacionController::resolveClientContact()` at L319-342 |
| 12 (C2) | PDF Total = Subtotal + IVA | Critical | PDF | ✅ Done | commit `6607368`; `CotizacionController::buildPdfData()` at L188-213 |
| 13 (C3) | PDF compact paragraph spacing | Medium | PDF | ✅ Done | commit `ef371cd`; PDF Blade template |
| 14 | DetalleOportunidad PUT/DELETE 500 | Critical | Backend | ✅ Done | `routes/api.php:252-253` (`PUT/DELETE /detalles-oportunidad/{id}`) |
| 15 | Cross-semestre code regex | Critical | Backend | ✅ Done | `app/Application/UseCases/Oportunidad/OportunidadCsvImportUseCase.php:188` regex `/^GC-\d{2}-\d{4}-\d{3}$/` |
| 16 | Calendar card onClick | Medium UX | Frontend | ✅ Done | Calendar component onClick handler |
| 17 | Mobile filter sidebar overlap | Medium UX | Frontend | ✅ Done | Sidebar breakpoint `md` (768px) |
| 18 | Textareas not resizable | Low UX | Frontend | ✅ Done | Global stylesheet `resize: vertical` |

(Items #4 and #11 already resolved in earlier work — excluded from this change.)

---

## 3. What was Formally Verified

PDF fixes **C1**, **C2**, **C3** were formally verified per `verify-report-c1-c2-c3.md`:

- **C1**: when `contacto_id IS NULL`, "Atención:" line shows entity's most-recently-updated contact (not `—`). Edge case (entity has zero contacts) handled gracefully.
- **C2**: detalle with `cantidad=1, vr_unitario=100000, iva=19` (19%) yields Subtotal $100,000 / IVA $19,000 / Total $119,000. Absolute-value path: `iva=5000` yields IVA $5,000. Heuristic: `iva <= 100` → percentage; `iva > 100` → money.
- **C3**: multi-line observations in `Observaciones` / `Forma de Pago` / `Notas Aclaratorias` / `Garantia` render with reasonable vertical spacing, not exaggerated blank space.

PDF route: `GET /api/v1/oportunidades/{id}/pdf` (`routes/api.php:260`). Fast JSON inspection via `GET /api/v1/oportunidades/{id}/cotizacion-data` (`routes/api.php:257`).

---

## 4. Pre-existing Failures (NOT introduced by this change)

The following tests fail in the project but predate this change:

1. **`enviar rejects non borrador`** (`CotizacionControllerTest`) — `SendPipelineChangeToN8n.php:36` crashes on `$oportunidad->pipeline->nombre` when the `pipeline` relation returns a string instead of a model.
2. **`it updates an oportunidad`** (`OportunidadControllerTest`) — same root cause; 500 vs 200.
3. **`it can change estado to ganada`** (`OportunidadControllerTest`) — same root cause; 500 vs 200.
4. **3 tests in `CotizacionPipelineStagesTest`** — same root cause.

**Tracked under**: separate issue filed for `SendPipelineChangeToN8n` listener crash. **NOT** introduced by this change — confirmed by git log showing these failures predated commits `f9c9b10`, `6607368`, `ef371cd`.

---

## 5. Out-of-scope

Per `proposal.md` §"Out of Scope":

- Filesystem move to `minerva/` (separate operation).
- Items #4, #11, phpMyAdmin — already resolved in earlier work; not part of this change.
- Ongoing Minerva rename follow-ups — tracked separately.

---

## 6. Known Gaps & Tech Debt

- **Only PDF had a formal verify-report.** Backend items #14, #15 were verified by PHPUnit Feature tests (not formally re-run during this archive window). Frontend items #1, #2, #3, #9, #16, #17, #18 were verified by user in daily use (no Vitest framework installed; explicitly deferred as out-of-scope by design).
- **Frontend test framework deferred.** Vitest setup is tracked separately; not in this change.
- **The original 5-file change folder was lost.** This archive-report and the 5 reconstructed skeletons were assembled post-hoc from this writer's context + production evidence. Full original content is unrecoverable.

---

## 7. Rollback Plan

| Item | Rollback |
|------|----------|
| #15 | `git revert` of the regex-tightening commit |
| #14 | `git revert` of the DetalleOportunidad FormRequest fix |
| #1 + #9 | `git revert` of the SlidePanel portal refactor |
| #2, #3, #16, #17, #18 | `git revert` of the respective frontend PRs |
| #10 (C1) | `git revert f9c9b10` |
| #12 (C2) | `git revert 6607368` |
| #13 (C3) | `git revert ef371cd` |
| #5 | Restore old `tecnoinnsoft_crm` references in code, docs, and env files |

---

## 8. Cross-references

- **verify-report** (this archive): `verify-report-c1-c2-c3.md`
- **PDF builder source**: `app/Http/Controllers/API/CotizacionController.php` (343 lines; `buildPdfData()` + `resolveClientContact()`)
- **Cross-semestre regex source**: `app/Application/UseCases/Oportunidad/OportunidadCsvImportUseCase.php:188`
- **SlidePanel source**: `dashboard-crm/src/components/SlidePanel.tsx`
- **ContactoFormModal source**: `dashboard-crm/src/components/ContactoFormModal.tsx`
- **CRMPage source**: `dashboard-crm/src/pages/CRMPage.tsx` (entity→CRM nav at L787)
- **Related changes**: `Docs/changes/gestion-contactos-oportunidades/` (parent-side archived; not part of this change)
- **Touched files sampling**: `app/Application/UseCases/Oportunidad/OportunidadCsvImportUseCase.php`, `app/Http/Controllers/API/CotizacionController.php`, `app/Http/Controllers/API/DetalleOportunidadController.php`, `routes/api.php`, `resources/views/pdf/cotizacion.blade.php`, `dashboard-crm/src/components/SlidePanel.tsx`, `dashboard-crm/src/components/ContactoFormModal.tsx`, `dashboard-crm/src/pages/CRMPage.tsx`

---

## 9. Sign-off

- [x] All 13 items shipped
- [x] 11 PRs merged (3 of them with PDF fixes cited by SHA)
- [x] User confirmed 13/13 items working in daily use
- [x] PDF verify-report PASS for C1, C2, C3
- [x] Pre-existing failures attributed to root cause (NOT introduced)
- [ ] **Original change folder lost** — this archive contains reconstructed skeletons + a fresh archive-report

---

## 10. Archive Location

```
D:\sitios desarrollo\crm-laravel\Docs\changes\archive\2026-08-27-production-blockers-and-ux-fixes\
├── proposal.md (reconstructed skeleton)
├── design.md (reconstructed skeleton)
├── explore.md (reconstructed skeleton)
├── tasks.md (reconstructed skeleton)
├── verify-report-c1-c2-c3.md (reconstructed skeleton — full content provided in original prompt; archived as-is)
└── archive-report.md (this file, written fresh)
```

**SDD cycle complete. The change is closed; future agents consult this archive for context on the 13 items.**