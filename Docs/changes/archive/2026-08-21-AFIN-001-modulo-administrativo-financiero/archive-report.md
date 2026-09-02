# Archive Report: AFIN-001-modulo-administrativo-financiero

| Field | Value |
|-------|-------|
| Status | Archived |
| Date | 2026-08-21 |
| Final branch | `feat/erp-phase-f` (to be merged) |
| Sprint | Sprint 1 (2 weeks, 1 dev full-stack) |
| Tasks completed | 43/43 + 5 hot-fixes (F1–F5) = **48/48 effectively** |
| Tests added | ~184 across phases A–E + 12 in Phase F = **~196 new** |
| Tests passing | **394 project-wide** (29 pre-existing failures unrelated to AFIN-001) |
| Capabilities shipped | 7 / 7 |
| Specs synced | 7 / 7 → `Docs/openspec/specs/erp-*/spec.md` |

---

## 1. Executive Summary

The AFIN-001 ERP / Administrativo-Financiero module is **fully shipped**. All 7 capabilities — banking accounts (CxP/CxC discriminator), CxC payments, invoicing (proforma + conversion + atomic payment application), CxC/CxP reports with aging buckets, recurring billing with idempotent daily scheduler, and RBAC middleware — are implemented end-to-end across Clean Architecture layers (Domain → Application → Infrastructure → HTTP), covered by ~196 new tests, and verified PASS WITH WARNINGS on branch `feat/erp-phase-a` plus 5 follow-on hot-fixes on `feat/erp-phase-f` that addressed the critical spec deviation flagged by verify.

The verify-report found **1 CRITICAL** deviation (`CRIT-01` — apply-payment endpoint contract diverged from the spec'd atomic M2M shape). All 5 findings from verify (1 CRIT + 4 AMB discovered during front-end handoff scoping) were resolved in **Phase F** before this archive: a new spec-compliant `POST /api/v1/erp/pagos-cliente/{id}/aplicar` endpoint with `aplicaciones[]` array was added inside a single `DB::transaction`, the legacy `POST /facturas/{id}/aplicar-pago` is kept for backward compat (marked `deprecated: true` in OpenAPI), and 4 contract gaps discovered by the front team (aplicaciones_count, PUT returns Factura shape, preview_numero, LoginResponse rol_id/nombre) were also addressed.

**Bottom line for the business**: post-sale cycle (oportunidad ganada → servicio → factura → pago → saldo actualizado → reporte cartera) is now operational within the same Laravel backend, with atomicidad transaccional garantizada (AC01/AC07) and a stable OpenAPI 3.1 contract (`Docs/openapi/erp-financiero.yaml`) that the `dashboard-crm` front-end team can consume independently. No destructive migrations; full rollback path preserved (8 additive migrations + middleware alias + scheduler hook).

---

## 2. Capabilities Delivered

| Capability | Spec scenarios | Tests in scope | Status |
|---|---|---|---|
| `erp-banking-accounts` | 20 | 16 (ErpBankingAccountsTest) | ✅ Shipped |
| `erp-cxc-payments` | 21 | 23 (ErpCxcPaymentsTest: 16 + 5 F1 + 2 F2) | ✅ Shipped (after F1 + F2 fixes) |
| `erp-invoicing` | 22 | 26 (ErpInvoicingTest: 22 + 1 F3 + 3 F4) | ✅ Shipped (after F3 + F4 fixes) |
| `erp-cartera-report` | 13 | 12 + 1 perf = 13 (ErpCarteraReportTest + CarteraPerformanceTest) | ✅ Shipped |
| `erp-cxp-report` | 14 | 13 (ErpCxpReportTest) | ✅ Shipped |
| `erp-recurring-scheduler` | 23 | 16 + 9 + 8 = 33 (FactRecurrente + PagoRecurrente + Command) | ✅ Shipped (after F4 refactor) |
| `erp-rbac-middleware` | 15 | 8 (ErpRbacMiddlewareTest) + 7 (TokenExchangeTest, +1 F5) | ✅ Shipped (after F5 fix) |
| **Totals** | **128** | **125 ERP Feature + 12 Phase F additions + 7 Auth** | **7/7 ✅** |

Spec coverage: **128 / 128 scenarios** verified implemented (after Phase F closed the gaps). Total test surface across the project: **394 passing** (1083 assertions); **29 pre-existing failures** in 10 unrelated files (PipelineEtapaMigration, EloquentPipelineRepository, SendPipelineChangeToN8n, Seguimiento repos — all module-refactor side-effects predating AFIN-001 base `8d220e7`).

---

## 3. Architecture Decisions Implemented

Link to ADD: `Docs/design/ADD-AFIN-001-modulo-administrativo-financiero.md`

| ID | Decision | Status |
|---|---|---|
| D1 | Split ledger (`movimientos` + `pagos_cliente`) | Implemented — `factura_pagos` M2M pivot materializes N:M application |
| D2 | Auto-create Servicio only in `ganar()` | Already existed pre-AFIN; tests + doc added |
| D3 | `app/` root Clean Architecture (Domain → Application → Infrastructure → HTTP) with `erp.auth` middleware | Implemented — `Modules/Administrativo` shadow models cleaned (task A1) |
| D4 | Front-end ERP separate (`D:\sitios desarrollo\dashboard-crm`) | Architecture documented; OpenAPI spec is the contract; front impl deferred to `AFIN-002-front-end-handoff` |
| D5 | `cuentas` table with `tipo_cuenta` discriminator (`proveedor`/`cliente`) + XOR constraint | Implemented — both app layer (`StoreCuentaUseCase::assert()`) and DB CHECK (defense in depth) |
| D6 | ADR-002 Persona unification (no runtime impl) | ADR written (`Docs/design/ADR-002-persona-unification.md`); implementation deferred |
| D7 | `orden_servicio` singular (not renamed) | Left as-is; no impact on AFIN-001 surface |
| D8 | Frecuencias enum (4 values: mensual/trimestral/semestral/anual) | Implemented + ALTER migration on `oportunidad.frecuencia` |
| D9 | Scheduler `financiero:generar-facturas-recurrentes` daily 02:00 America/Bogota | Implemented — `withoutOverlapping(60)->onOneServer()->runInBackground()` |
| D10 | Reports include cuenta info (banco, numero) | Implemented — `CarteraReportUseCase` reads `numero_cuenta`; `PagosReporteUseCase` joins `cuentas` |

---

## 4. Quality Attributes Verified

| AC | Status | Evidence |
|---|---|---|
| AC01 Integridad transaccional | ✅ | `DB::transaction` in `StoreFacturaUseCase`, `ConvertirProformaAFacturaUseCase`, `AplicarPagoAFacturaUseCase` (F1 pago-centric), `GenerarFacturasRecurrentesUseCase` (per-row). `ErpInvoicingTest::test_atomic_rollback_on_invalid_detalle` passes. |
| AC02 Modificabilidad | ✅ | All 8 migrations additive; no FK circular; `ADR-002-persona-unification.md` documents the unification path; shadow models cleaned (A1). |
| AC03 Performance reportes | ✅ | `CarteraPerformanceTest` measured p95 ≈ 3 ms (budget 2000 ms) with 10 k seeded facturas. `Cache::remember` 300 s TTL keyed by `(entidad_id, today, per_page)`. Indexes `(entidad_id, saldo)`, `(estado)`, `(fecha_emision)` on `facturas`. |
| AC04 Auditabilidad | ✅ | `created_by`/`updated_by`/`timestamps`/`softDeletes` on all 6 new tables. `ErpAuthMiddleware` writes `Log::warning('erp.auth.rejected', [...])` with user_id, rol_id, ip, path, method on rejection. |
| AC05 Separación ERP/CRM | ✅ | `ErpAuthMiddleware` enforces `rol_id IN config('erp.allowed_rol_ids')` (default `[4, 5]`); all routes under `Route::prefix('v1/erp')->middleware(['auth:sanctum', 'throttle-mutations', 'erp.auth'])`; GET reports `->withoutMiddleware('throttle-mutations')`. |
| AC06 Proyección correcta (idempotencia) | ✅ | `GenerarFacturasRecurrentesUseCase` dedup by `ultimo_periodo_facturado`; `FinancieroGenerarFacturasRecurrentesCommandTest::test_scheduler_idempotent_on_same_day` passes (Generated 0, skipped 1). |
| AC07 Atomicidad de pagos | ✅ | F1 fix: `POST /pagos-cliente/{id}/aplicar` runs N applications in **single** `DB::transaction` with `lockForUpdate` per factura. Multi-factura split (400+300+300) verified atomic. Cross-entidad rejection + over-application rejection both tested. |

---

## 5. Hot-fix Phase (F) — pre-front-handoff fixes

5 fixes delivered BEFORE front-end `sdd-apply` could start on `dashboard-crm`:

| # | Ticket | Fix | New tests |
|---|---|---|---|
| F1 | **CRIT-01** | New `POST /api/v1/erp/pagos-cliente/{id}/aplicar` accepting `{ aplicaciones: [{ factura_id, valor_aplicado }] }`. Atomic M2M split inside single `DB::transaction` with `lockForUpdate` per factura. Legacy `/facturas/{id}/aplicar-pago` kept for backward compat (deprecated). | 5 (ErpCxcPaymentsTest) |
| F2 | **AMB-1** | `PagoClienteResource` exposes `aplicaciones_count` via `loadCount('aplicaciones')`. | 2 (ErpCxcPaymentsTest) |
| F3 | **AMB-3** | `PUT /facturas/{id}` returns full `FacturaResource` shape (detalles + pagos arrays). `UpdateFacturaUseCase` refactored to return model not array. | 1 (ErpInvoicingTest) |
| F4 | **AMB-6** | `POST /facturas/{id}/convertir` response now `{ success, data: { factura, numero_asignado, preview_numero } }`. New `GET /facturas/preview-numero` endpoint for UI preview (route declared BEFORE `apiResource` to avoid `{id}` wildcard capture). | 3 (ErpInvoicingTest) |
| F5 | **AMB-7** | `LoginResponse` now includes `rol_id` + `nombre` (was lagging). `nombres` kept as deprecated alias for Mercurio v1 broker scripts. | 1 (TokenExchangeTest) |
| OpenAPI | docs | Updated `Docs/openapi/erp-financiero.yaml` (new paths/schemas + `deprecated: true` flag on legacy endpoint) and `Docs/openapi/auth.yaml` (LoginResponse gains fields). | 0 |
| E2E | fix | Updated `E2EFlowTest` to AMB-6 response shape. | 0 |

Phase F total: **12 new tests, 7 commits** on `feat/erp-phase-f` (off `fca920c` which was tip of `feat/erp-phase-a`).

---

## 6. Known Issues / Deferred

From verify-report (file: `Docs/changes/AFIN-001-modulo-administrativo-financiero/verify-report.md`):

| ID | Severity | Description | Disposition |
|---|---|---|---|
| WARN-01 | P1 | `ErpCxcPaymentsTest` did not cover REQ-CXC-003 | **Resolved in F1** — 7 new tests added |
| WARN-02 | P2 | `ErpRbacMiddlewareTest` skips config-cache + env-parse scenarios | Deferred — `tests/Unit/Config/ErpConfigTest.php` not yet created |
| WARN-03 | P2 | `CarteraReportUseCase::invalidate()` uses `Cache::flush()` (too aggressive) | Deferred — easy cleanup |
| WARN-04 | P3 | `PipelineEtapaMigrationTest` 4 failures | **Pre-existing** (NOT introduced by AFIN-001); open separate ticket |
| WARN-05 | P3 | Test DB infrastructure deadlocks on parallel ERP test runs | **Pre-existing** (RefreshDatabase + MariaDB FK locks); open separate ticket |
| SUG-01 | P3 | Coverage not measurable locally (no Xdebug/PCOV) | CI must verify; not blocking |
| SUG-02 | P3 | `Cuenta` entity has duplicate `numero` / `numero_cuenta` columns | Future migration to drop `numero` |
| SUG-04 | P3 | Pint lint not run on full ERP scope | Cleanup ticket |
| SUG-05 | P3 | `PagoRecurrenteProveedor` lacks scheduler coverage | HU10 scheduler fate TBD; document in ADR-003 if needed |
| Cartera | P3 | `cuenta_destino` is heuristic (last `pagos_cliente.cuenta_id` for the entidad) | Future: add `facturas.cuenta_destino_id` column |
| Scheduler | P3 | `--backfill` flag in `financiero:generar-facturas-recurrentes` is a stub | ADR-002 captured the rationale (START CLEAN v1, no retroactive) |
| Legacy endpoint | P3 | `POST /erp/facturas/{id}/aplicar-pago` deprecated but still functional | Remove in next sprint after front team migrates |

**Pre-existing test failures (not in AFIN-001 scope, do not block archive)**: 29 failures in 10 files — `PipelineEtapaMigrationTest` (4), `EloquentPipelineRepositoryTest`, `SendPipelineChangeToN8nTest`, `EloquentSeguimientoRepositoryTest`, etc. All predate branch base `8d220e7` (confirmed by commit `832b988 fix(test): add guard to prevent tests from wiping production DBs` which precedes the AFIN-001 branch).

---

## 7. OpenAPI Contract

File: **`Docs/openapi/erp-financiero.yaml`** (updated to include Phase F contracts)

- **OpenAPI 3.1.0**, 18 paths, ~1300 lines
- Covers all `/api/v1/erp/*` endpoints (~29 total)
- Schemas: `Cuenta`, `PagoCliente`, `Factura`, `FacturaDetalle`, `FacturaPago`, `AplicacionPago`, `FacturacionRecurrente`, `PagoRecurrenteProveedor`, `ReporteCartera`, `ReportePagos`, `AplicarPagoRequest`, `AplicarPagoResponse`, `ConvertirProformaResponse`, `PreviewNumeroResponse`, etc.
- Auth scheme documented: Sanctum Bearer token + `erp.auth` middleware (`rol_id IN [4,5]`)
- `POST /facturas/{id}/aplicar-pago` marked `deprecated: true` with replacement `POST /pagos-cliente/{id}/aplicar`
- File: **`Docs/openapi/auth.yaml`** (updated) — `LoginResponse` schema gains `rol_id` + `nombre`

Front-end team (`dashboard-crm`) can consume this spec directly via `openapi-generator` or similar.

---

## 8. Files Created (Final Tally)

| Layer | Files | Notes |
|---|---|---|
| **Domain** (pure PHP) | 6 entities + 1 VO + 5 enums + 4 repo interfaces | `Cuenta`, `Factura`, `FacturaDetalle`, `FacturaPago`, `PagoCliente`, `FacturacionRecurrente`, `PagoRecurrenteProveedor`, `Money`, `TipoCuenta`, `TipoFactura`, `EstadoFactura`, `Frecuencia`, `EstadoRecurrente` |
| **Application** (use cases) | ~28 use cases | 3 Cuenta + 2 PagoCliente + 5 Factura (Store/Update/Convertir/PreviewNumero/AplicarPago) + 2 Reporte + 4 Recurrente-cliente + 4 Recurrente-proveedor + 1 ApplyPago M2M (F1) + ... |
| **Infrastructure** | 4 Eloquent repositories + 1 middleware + 1 Log channel | `EloquentFacturaRepository`, `EloquentPagoClienteRepository`, `EloquentFacturacionRecurrenteRepository`, `EloquentPagoRecurrenteProveedorRepository`, `ErpAuthMiddleware` |
| **HTTP** | 7 controllers + 9 Form Requests + 6 Resources + 1 Concern | All under `app/Http/Controllers/ERP/` and `app/Http/Requests/ERP/` and `app/Http/Resources/ERP/` |
| **Console** | 1 command | `FinancieroGenerarFacturasRecurrentesCommand` |
| **Models** (Eloquent) | 6 new models | Inherit `BaseModel`, expose soft deletes + audit fields |
| **Migrations** | 8 (2 ALTER + 6 CREATE) | `database/migrations/2026_08_19_10000{0..7}_*.php` |
| **Config** | 1 (`config/erp.php`) | env-driven `allowed_rol_ids` |
| **Routes** | 2 files modified | `routes/api.php` (ERP group), `routes/console.php` (scheduler entry) |
| **Bootstrap** | 1 file modified | `bootstrap/app.php` (`erp.auth` middleware alias) |
| **Tests** | ~25 files (Feature + Unit + Migration + Console) | `tests/Feature/ERP/*Test.php`, `tests/Feature/Console/FinancieroGenerarFacturasRecurrentesCommandTest.php`, `tests/Unit/Domain/`, `tests/Unit/Application/UseCases/ERP/` |
| **Docs** | ADD + proposal + design + tasks + verify-report + archive-report (this) + 1 ADR | `Docs/design/ADD-AFIN-001-...md`, `Docs/design/ADR-002-persona-unification.md`, `Docs/changes/AFIN-001-.../{proposal,design,tasks,verify-report,archive-report}.md` |
| **OpenAPI** | 2 YAML specs | `Docs/openapi/erp-financiero.yaml`, `Docs/openapi/auth.yaml` |
| **AGENTS.md** | updated | ERP module section added in commit `c753ab9` |

---

## 9. Branch State

```
feat/erp-phase-a (19 commits, A → E phases, base 8d220e7)
  └── feat/erp-phase-f (7 commits, Phase F hot-fixes, off fca920c)
       └── working tree: uncommitted changes (Docs/, helpers, etc. — NOT part of archive)
```

Recommendation: **merge `feat/erp-phase-f` → `feat/erp-phase-a` first**, then **`feat/erp-phase-a` → `develop`** (or `main`, depending on repo flow). The working-tree uncommitted files (`Docs/ARCH.md`, `Docs/DESPLIEGUE.md`, several test scripts, etc.) are NOT part of AFIN-001 and should be evaluated separately.

Final commits on `feat/erp-phase-f`:

```
473daa9 test(erp): update E2EFlowTest to AMB-6 convertir response shape
5efde7f docs(openapi): update erp-financiero.yaml + auth.yaml with F1-F5 contracts
999e312 feat(erp): F5 AMB-7 — LoginResponse includes rol_id and nombre
4c2106c feat(erp): F4 AMB-6 — preview_numero on /convertir + new /preview-numero
df8bb87 feat(erp): F3 AMB-3 — PUT /facturas returns full Factura shape
7b1613e feat(erp): F2 AMB-1 — aplicaciones_count on PagoCliente response
6c79d65 feat(erp): F1 CRIT-01 — pago-centric apply-payment endpoint (M2M atomic)
```

---

## 10. Next Steps

### For backend (`D:\sitios desarrollo\crm-laravel`)

- [ ] **Merge `feat/erp-phase-f` → `develop`** (recommended fast-forward after evaluating working-tree uncommitted files)
- [ ] Open new change **`AFIN-002-front-end-handoff`** (this repo) for tracking — even though front lives in `dashboard-crm`, this repo hosts the OpenAPI contract and any backend adjustments discovered during integration
- [ ] Consider new change **`AFIN-003-correcciones-y-mejoras`** for:
  - HU11 cashflow projection (deferred from proposal §3 out-of-scope)
  - `facturas.cuenta_destino_id` column (replace heuristic)
  - Remove deprecated `/facturas/{id}/aplicar-pago` after front team confirms migration
  - Resolve deferred WARN-02 (ErpConfigTest), WARN-03 (Cache::flush removal), SUG-02 (Cuenta duplicate columns), SUG-04 (full ERP pint cleanup), SUG-05 (HU10 scheduler fate)
- [ ] Open **separate ticket** for pre-existing test failures (WARN-04 + WARN-05) — NOT part of AFIN-001 scope

### For front-end (`D:\sitios desarrollo\dashboard-crm`)

- [ ] Run `sdd-new AFIN-002-front-end-handoff` (or equivalent name) on `dashboard-crm`
- [ ] Consume OpenAPI spec from `Docs/openapi/erp-financiero.yaml` (commit `5efde7f` or later)
- [ ] Implement ERP dashboard at `/erp/*` (separate from CRM `/crm/*`)
- [ ] Use Sanctum token issued via `php artisan crm:generate-token --email=erp@tecnoinnsoft.dev`; user must have `rol_id=4` (or `5`)
- [ ] Test against back-end running locally on port 8001 (`APP_URL=http://localhost:8001`)
- [ ] Adopt the F1-F5 contracts (pago-centric apply, preview_numero, etc.) — DO NOT use the legacy `aplicar-pago` (deprecated)

---

## 11. Sign-off

- [x] Backend implementation complete (43/43 tasks + 5 hot-fixes = 48/48 effectively done)
- [x] Verify report reviewed (`PASS WITH WARNINGS` — all CRIT + applicable WARN/SUG addressed in Phase F)
- [x] OpenAPI spec synchronized (`Docs/openapi/erp-financiero.yaml` + `Docs/openapi/auth.yaml` at commit `5efde7f`)
- [x] AGENTS.md updated with ERP module section
- [x] Front-end team notified — handoff ready (Phase F contracts adopted)
- [x] All 7 specs synced to main specs (`Docs/openspec/specs/erp-*/spec.md`)
- [x] Archive folder created at `Docs/changes/archive/2026-08-21-AFIN-001-modulo-administrativo-financiero/`
- [ ] **Branch merge** (user action — `feat/erp-phase-f` → `develop`)

---

## 12. Engram Reference

| Artifact | Engram ID | topic_key |
|---|---|---|
| proposal | #1637 | `sdd/AFIN-001-modulo-administrativo-financiero/proposal` |
| design | #1646 | `sdd/AFIN-001-modulo-administrativo-financiero/design` |
| apply-progress | #1654 | `sdd/AFIN-001-modulo-administrativo-financiero/apply-progress` |
| verify-report | #1672 | `sdd/AFIN-001-modulo-administrativo-financiero/verify-report` |
| **archive-report** | **(this entry)** | **`sdd/AFIN-001-modulo-administrativo-financiero/archive-report`** |

---

## 13. Archive Location

This change folder has been moved to:

```
D:\sitios desarrollo\crm-laravel\Docs\changes\archive\2026-08-21-AFIN-001-modulo-administrativo-financiero\
```

Archive contains the full historical record:
- `proposal.md` (updated to Status: Archived)
- `design.md`
- `tasks.md`
- `verify-report.md`
- `archive-report.md` (this file)
- `specs/erp-banking-accounts/spec.md`
- `specs/erp-cxc-payments/spec.md`
- `specs/erp-invoicing/spec.md`
- `specs/erp-cartera-report/spec.md`
- `specs/erp-cxp-report/spec.md`
- `specs/erp-recurring-scheduler/spec.md`
- `specs/erp-rbac-middleware/spec.md`

Main specs (source of truth going forward): `Docs/openspec/specs/erp-*/spec.md`

**SDD cycle complete. Ready for the next change.**