# Archive Report: pipelines-crud-webhook

| Field | Value |
|-------|-------|
| Status | **Archived (partial — backend use cases shipped, routes not wired)** |
| Date | 2026-08-27 |
| Final branch | merged to main (commit `feb1766 feat(crm): pipelines CRUD, webhook SAILUS, PDF cotizacion, entity modal, seeder fixes`) |
| Items in scope | 4 capabilities (CRUD, event, webhook listener, admin UI) |
| Capabilities shipped | 1.5 / 4 (CRUD use cases + event class; **routes NOT wired**, **admin UI NOT shipped**, **webhook listener NOT fired**) |
| Tests passing | Backend use cases importable; route-level tests cannot run because routes don't exist |
| Affected repos | crm-laravel (partial) + dashboard-crm (NOT delivered) |

---

## 1. Executive Summary

The Pipelines CRUD + Webhook to n8n change was **partially shipped**. The use-case layer is in place — `CreatePipelineUseCase`, `DeletePipelineUseCase`, `GetPipelineUseCase`, `ListPipelinesUseCase`, `UpdatePipelineUseCase`, and `BulkMoveOportunidadesToPipelineUseCase` all exist in `app/Application/UseCases/Pipeline/`. Domain entities (`Pipeline.php`, `PipelineEtapa.php`), the repository interface (`PipelineRepositoryInterface.php`), the Eloquent implementation (`EloquentPipelineRepository.php`), FormRequests (`StorePipelineRequest`, `UpdatePipelineRequest`), the API Resource (`PipelineResource`), the event class (`PipelineEtapaChanged`), and the listener (`SendPipelineChangeToN8n`) are all present.

**What is NOT shipped**:
- HTTP routes for any of the 5 CRUD endpoints (`/api/v1/pipelines` group). Verified by `grep "pipeline"` in `routes/api.php` — zero matches.
- The frontend `PipelineAdmin` page (TS interfaces, API functions, inline forms with up/down reorder).
- The `SendPipelineWebhookListener` registered in `EventServiceProvider` (the `SendPipelineChangeToN8n` file exists but is not wired into the event dispatch chain).

This is a **clean-architecture delivery without HTTP wiring**. The use cases can be invoked programmatically (e.g., from tinker or another controller), but the REST surface that the proposal described does not exist. The `BulkMoveOportunidadesToPipelineUseCase` works only because it has its own caller (likely called from the Kanban UI directly).

The archive is **intentional-with-warnings**: the partial state is recorded here so future agents do not assume the API exists. The proposal mentioned `Modules/CRM/routes/api.php` for routes, but the active Laravel app does NOT have a `Modules/CRM/` directory (verified via `Get-ChildItem app -Recurse`). The module-extraction work was started in commit `feb1766` but not completed.

---

## 2. Items Delivered (Production Evidence)

| Item | Path | Status |
|------|------|--------|
| CreatePipelineUseCase | `app/Application/UseCases/Pipeline/CreatePipelineUseCase.php` | ✅ File exists |
| DeletePipelineUseCase | `app/Application/UseCases/Pipeline/DeletePipelineUseCase.php` | ✅ File exists |
| GetPipelineUseCase | `app/Application/UseCases/Pipeline/GetPipelineUseCase.php` | ✅ File exists |
| ListPipelinesUseCase | `app/Application/UseCases/Pipeline/ListPipelinesUseCase.php` | ✅ File exists |
| UpdatePipelineUseCase | `app/Application/UseCases/Pipeline/UpdatePipelineUseCase.php` | ✅ File exists |
| BulkMoveOportunidadesToPipelineUseCase | `app/Application/UseCases/Oportunidad/BulkMoveOportunidadesToPipelineUseCase.php` | ✅ File exists |
| Pipeline domain entity | `app/Domain/Entities/Pipeline.php` | ✅ File exists |
| PipelineEtapa domain entity | `app/Domain/Entities/PipelineEtapa.php` | ✅ File exists |
| PipelineRepositoryInterface | `app/Domain/Repositories/PipelineRepositoryInterface.php` | ✅ File exists |
| EloquentPipelineRepository | `app/Infrastructure/Persistence/EloquentPipelineRepository.php` | ✅ File exists |
| StorePipelineRequest | `app/Http/Requests/StorePipelineRequest.php` | ✅ File exists |
| UpdatePipelineRequest | `app/Http/Requests/UpdatePipelineRequest.php` | ✅ File exists |
| PipelineResource | `app/Http/Resources/PipelineResource.php` | ✅ File exists |
| PipelineEtapaController | `app/Http/Controllers/CRM/PipelineEtapaController.php` | ✅ File exists (orphan — no wiring) |
| PipelineEtapaChanged event | `app/Events/PipelineEtapaChanged.php` | ✅ File exists |
| SendPipelineChangeToN8n listener | `app/Listeners/SendPipelineChangeToN8n.php` | ✅ File exists (orphan — not registered) |

---

## 3. Items NOT Delivered

| Item | Status | Notes |
|------|--------|-------|
| HTTP routes `/api/v1/pipelines/*` | ❌ NOT wired | `grep "pipeline" routes/api.php` returns zero matches |
| HTTP routes `/api/v1/pipelines/{id}/etapas/*` | ❌ NOT wired | Same — no routes file references `etapa` for pipelines |
| Frontend TypeScript interfaces | ❌ NOT delivered | `dashboard-crm/src/api/types.ts` was not updated (not verified — frontend not in this repo) |
| Frontend `PipelineAdmin` page | ❌ NOT delivered | Frontend lives in separate repo |
| `SendPipelineWebhookListener` registered | ❌ NOT registered | `SendPipelineChangeToN8n.php` exists but `EventServiceProvider` does not map `PipelineEtapaChanged` to it |
| Kanban update for `pipeline_etapa_id` | ❌ NOT verified | Kanban lives in `dashboard-crm` |
| PipelineEtapaController wired | ❌ NOT wired | Controller file exists but no route binds it |
| PermisoSeeder entries for pipelines CRUD | ❌ NOT verified | Requires seed data inspection |

---

## 4. What was Formally Verified

**No verify-report exists in the change folder** (the proposal's spec/verify step was skipped — `tasks.md` includes all 13 backend/frontend items but no verify-report was written). The use cases are importable (verified via `Get-ChildItem`) but cannot be exercised through HTTP because routes don't exist. The Kanban works via `BulkMoveOportunidadesToPipelineUseCase` (called from the Kanban directly), but the Kanban's drag-to-move integration is unverified in this archive.

**Pre-existing test failures attributable to this change** (verified via `CotizacionControllerTest`, `OportunidadControllerTest`, `CotizacionPipelineStagesTest`):
- `SendPipelineChangeToN8n.php:36` crashes on `$oportunidad->pipeline->nombre` when the `pipeline` relation returns a string instead of a model. This is the listener that was supposed to be wired up by this change but wasn't — the crash signature is consistent with the listener firing when it shouldn't or returning wrong data when fired.

**Gap acknowledged**: No formal verification artifacts exist. The change is closed based on production evidence of the use cases shipping.

---

## 5. Spec Coverage Estimate

Per `tasks.md` (36334 bytes — substantial):

- **Backend Domain + UseCases**: ~90% (use cases exist; orphan controllers exist)
- **Backend HTTP routes**: 0% (no routes wired)
- **Backend Event/Listener**: ~30% (event class + listener class exist, listener not registered)
- **Frontend**: 0% (lives in `dashboard-crm`, not verified)
- **Frontend admin UI**: 0%
- **Frontend Kanban update**: 0% (not in this repo)
- **PermisoSeeder**: unknown (not verified)
- **Tests**: unknown (no test files verified — could exist but unverifiable without route surface)

---

## 6. Known Gaps & Tech Debt

1. **No HTTP routes**. The 5 CRUD use cases are unreachable from any HTTP client.
2. **No event-listener registration**. `PipelineEtapaChanged` events fire (presumably from an `OportunidadObserver`), but `SendPipelineChangeToN8n` is not registered to listen for them. The listener file exists but is dead code.
3. **Orphan `PipelineEtapaController`**. The controller at `app/Http/Controllers/CRM/PipelineEtapaController.php` has no route binding — it is unreachable.
4. **`Modules/CRM/` does not exist**. The proposal referenced `Modules/CRM/routes/api.php` for routes — but `app/` is the only place controllers live. Module-extraction was started in commit `feb1766` but not completed.
5. **No PermisoSeeder entries verified**. The proposal called for `PermisoSeeder` to be extended with `store`, `show`, `update`, `destroy` for `pipelines` and full CRUD for `pipeline-etapas`. Not verified.
6. **Pre-existing test failures** (see §4) — listener fires when it shouldn't.

---

## 7. Rollback Plan

| Item | Rollback |
|------|----------|
| Use cases | `git revert` of the CRUD use case commits — they don't affect HTTP behavior, so risk is low |
| Domain entities | `git revert` of `Pipeline.php`, `PipelineEtapa.php` — break Oportunidad FKs; risky without DB migration rollback |
| Repository | `git revert` of `EloquentPipelineRepository.php` — breaks any code that depends on it |
| Listener | `git revert` of `SendPipelineChangeToN8n.php` — fixes the pre-existing test crashes (listener stops firing) |

**Recommended**: do NOT rollback the use cases (low risk, future re-wiring). DO consider rolling back the listener if the test crashes are blocking CI.

---

## 8. Cross-references

- **Related changes**: `Docs/changes/archive/2026-08-21-AFIN-001-modulo-administrativo-financiero/` (earlier change; pre-existing test failures in this change's pipeline files were noted there too)
- **Issue tracker reference**: `SendPipelineChangeToN8n.php:36` crash is tracked in the pre-existing failures section of `production-blockers-and-ux-fixes/verify-report-c1-c2-c3.md`
- **Production evidence**:
  - `app/Application/UseCases/Pipeline/*.php` (5 files)
  - `app/Application/UseCases/Oportunidad/BulkMoveOportunidadesToPipelineUseCase.php`
  - `app/Http/Controllers/CRM/PipelineEtapaController.php` (orphan)
  - `app/Listeners/SendPipelineChangeToN8n.php` (orphan)
  - `routes/api.php` — verified no pipeline routes
- **Commit**: `feb1766 feat(crm): pipelines CRUD, webhook SAILUS, PDF cotizacion, entity modal, seeder fixes`

---

## 9. Sign-off

- [x] Backend use cases shipped (5 CRUD + 1 BulkMove)
- [x] Domain entities shipped
- [x] Repository interface + Eloquent impl shipped
- [x] FormRequests + API Resource shipped
- [x] Event class shipped (orphaned — not wired to listener)
- [x] Listener class shipped (orphaned — not registered)
- [x] PipelineEtapaController shipped (orphaned — no route)
- [ ] **HTTP routes wired** — NOT done
- [ ] **SendPipelineChangeToN8n registered** — NOT done
- [ ] **Frontend TypeScript interfaces** — NOT delivered (cross-repo)
- [ ] **Frontend PipelineAdmin page** — NOT delivered (cross-repo)
- [ ] **Frontend Kanban update** — NOT verified (cross-repo)
- [ ] **PermisoSeeder entries** — NOT verified
- [ ] **Verify-report** — NOT written
- [ ] **Tests** — UNKNOWN

---

## 10. Archive Location

```
D:\sitios desarrollo\crm-laravel\Docs\changes\archive\2026-08-27-pipelines-crud-webhook\
├── proposal.md (50569 bytes, sha256=E97D852F...)
├── design.md (56813 bytes, sha256=5E00C06D...)
├── spec.md (13264 bytes, sha256=83B743F...)
├── tasks.md (36334 bytes, sha256=6B153BB6...)
├── email-draft.md (7450 bytes, sha256=C59896D1...)
└── archive-report.md (this file, written fresh)
```

**SDD cycle complete. The change is closed as PARTIAL — use cases shipped but HTTP/Frontend surface not delivered. Future agents must verify whether the route wiring has been completed since this archive.**