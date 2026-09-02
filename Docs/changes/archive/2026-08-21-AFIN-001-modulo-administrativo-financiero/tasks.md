# Tasks: AFIN-001-modulo-administrativo-financiero

**Change**: AFIN-001
**Status**: Draft
**Sprint**: Sprint 1 (10 working days, 1 dev full-stack)
**Capabilities**: 7 — `erp-banking-accounts`, `erp-cxc-payments`, `erp-invoicing`, `erp-cartera-report`, `erp-cxp-report`, `erp-recurring-scheduler`, `erp-rbac-middleware`
**Total tasks**: 43
**Total TDD tests expected**: ~118 (across all phases)

---

## How to read this document

Tasks are grouped by phase (A → B → C → D → E) and ordered by dependency. Each task has:
- **Type**: `test` (RED), `feat` (GREEN), `refactor`, `chore`, `docs`
- **Depends on**: prerequisite task IDs (e.g. `B1` means "task B1 must be merged first")
- **Effort**: in hours (h), broken down per task
- **Branch**: feature branch prefix following `branch-pr` convention (`feat/<slug>`, `test/<slug>`, `chore/<slug>`, `docs/<slug>`)
- **Acceptance**: tests passing + `composer test` green + conventional commit on the named branch

> **TDD discipline**: For every `feat` task that ships a use case, endpoint, or migration, the corresponding `test` task (or the first test in its cluster) MUST be created and run **RED** before any `feat` task is started. Tests in this change use the canonical pattern from `tests/Feature/API/RbacMiddlewareTest.php`:
> ```php
> $user = Usuario::create([..., 'rol_id' => 4]);
> $token = $user->createToken('test-token')->plainTextToken;
> $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson(...);
> ```

> **Open decisions** flagged with **[DECISION]** below are resolved with the defaults stated in the launch prompt. User may override during apply phase; defaults are safe.

> **Test naming**: follow spec filenames exactly — `ErpBankingAccountsTest`, `ErpCxcPaymentsTest`, `ErpInvoicingTest`, `ErpCarteraReportTest`, `ErpCxpReportTest`, `ErpRecurringSchedulerTest`, `ErpRbacMiddlewareTest`. All under `tests/Feature/ERP/`.

---

## Phase A — Foundations (Days 1-2, ~10h)

### A1 — Clean up `Modules/Administrativo` shadow models
- **Type**: chore
- **Effort**: 0.5h
- **Files**: delete `Modules/Administrativo/app/Models/{Colaborador,Cuenta,DetalleServicio,LugarEntidad,Movimiento,OrdenServicio,Proveedor,Servicio}.php` (8 files)
- **Acceptance**:
  - `grep -r "Modules\\\\Administrativo\\\\Models" app/ routes/` returns 0 hits in production code (tests may keep them temporarily)
  - `app/Models/Cuenta.php` and `app/Models/Movimiento.php` are **extended to be self-contained** (no longer `extends \Modules\Administrativo\Models\*`) — they keep the same `protected $table`, `$fillable`, relationships copied over
  - `Modules/Administrativo/module.json` retained so nwidart still recognises the module; the `app/Models/` subdir removed
  - `composer test` green (no test must import the old path)
- **Depends on**: —
- **Branch**: `chore/clean-administrativo-module`

### A2 — Create `config/erp.php` and register `erp.auth` alias in `bootstrap/app.php`
- **Type**: chore
- **Effort**: 0.5h
- **Files**:
  - `config/erp.php` (new) — returns `['allowed_rol_ids' => array_map('intval', explode(',', env('ERP_ALLOWED_ROL_IDS', '4,5')))]`
  - `bootstrap/app.php` (modify `$middleware->alias([...])` to add `'erp.auth' => \App\Infrastructure\Auth\ErpAuthMiddleware::class`)
- **Acceptance**:
  - `php artisan config:clear && php artisan tinker --execute="dump(config('erp.allowed_rol_ids'))"` prints `[4, 5]`
  - `php artisan route:list` runs without errors
- **Depends on**: A1
- **Branch**: `feat/erp-auth-middleware`

### A3 — Write `ErpAuthMiddlewareTest` (RED) covering 8 scenarios
- **Type**: test
- **Effort**: 1h
- **File**: `tests/Feature/ERP/ErpRbacMiddlewareTest.php` (new)
- **Tests (8, from `erp-rbac-middleware` spec)**:
  - `test_allows_rol_4` — passes (200)
  - `test_allows_rol_5` — passes (200)
  - `test_rejects_rol_1` — 403 + error message
  - `test_rejects_rol_3_with_403` — 403 + audit log assertion
  - `test_unauthenticated_returns_401` — no Bearer header → 401
  - `test_expired_token_returns_401_without_audit_log` — invalid token → 401, no `erp.auth.rejected` log
  - `test_default_allowed_roles_when_env_unset` — `config('erp.allowed_rol_ids') === [4, 5]`
  - `test_env_overrides_config` — `config(['erp.allowed_rol_ids' => [4,5,7]])` → rol_id=7 passes
- **Acceptance**: tests compile and run (FAIL because `ErpAuthMiddleware` does not exist yet) — this is the RED state.
- **Depends on**: A2
- **Branch**: `feat/erp-auth-middleware`

### A4 — Implement `ErpAuthMiddleware` (GREEN) + route registration helpers
- **Type**: feat
- **Effort**: 1.5h
- **Files**:
  - `app/Infrastructure/Auth/ErpAuthMiddleware.php` (new) — reads `config('erp.allowed_rol_ids')`, returns 403 JSON envelope + writes `Log::warning('erp.auth.rejected', [...])` on rejection
  - `app/Providers/AppServiceProvider.php` (extend) — add 4 new repository bindings (Factura, PagoCliente, FacturacionRecurrente, PagoRecurrenteProveedor) per design §3
- **Acceptance**:
  - All 8 tests in A3 pass
  - `php artisan test --filter=ErpRbacMiddleware` is green
  - `Log::shouldReceive('warning')->once()->with('erp.auth.rejected', \Mockery::on(fn ($ctx) => isset($ctx['rol_id'])))` works in test
- **Depends on**: A3
- **Branch**: `feat/erp-auth-middleware`

### A5 — ADR-002 on Persona unification (deferred implementation)
- **Type**: docs
- **Effort**: 1h
- **File**: `Docs/design/ADR-002-persona-unification.md` (new)
- **Content**: Status "Proposed — deferred to next sprint"; context (Persona vs Entidad duplication), decision (keep both with explicit mapping table), consequences (no migration in sprint 1), references (proposal §3, design §1.1)
- **Acceptance**: file exists; PR links it from proposal §7 "References"
- **Depends on**: —
- **Branch**: `docs/adr-persona-unification`

### A6 — Document HU01+HU02 with `OportunidadGanarTest` extensions
- **Type**: test
- **Effort**: 1h
- **File**: `tests/Feature/API/OportunidadGanarTest.php` (extend — `winning_oportunidad_creates_servicio` exists at line 45)
- **Tests (2 new)**:
  - `test_winning_oportunidad_marks_entidad_as_cliente` — after `PUT .../oportunidades/{id}` with `estado=Ganada`, `entidad.estado === 'Cliente'`
  - `test_winning_oportunidad_sets_cliente_desde_on_first_win` — for entidad with `cliente_desde IS NULL`, post-win has `cliente_desde = now()`. Second win does NOT overwrite existing `cliente_desde` (regression guard).
- **Acceptance**: both tests green; existing `winning_oportunidad_creates_servicio` still passes
- **Depends on**: —
- **Branch**: `test/document-hu01-hu02`

### A7 — Create domain enums + Money value object
- **Type**: feat
- **Effort**: 1h
- **Files** (all new, pure PHP, no Eloquent):
  - `app/Domain/Enums/TipoCuenta.php` — backed: `Proveedor='proveedor'`, `Cliente='cliente'`
  - `app/Domain/Enums/TipoFactura.php` — backed: `Proforma='proforma'`, `Factura='factura'`
  - `app/Domain/Enums/EstadoFactura.php` — backed: `Borrador`, `Emitida`, `Pagada`, `Anulada`
  - `app/Domain/Enums/Frecuencia.php` — backed: `Mensual`, `Trimestral`, `Semestral`, `Anual`
  - `app/Domain/Enums/EstadoRecurrente.php` — backed: `Activa`, `Pausada`, `Cancelada`
  - `app/Domain/ValueObjects/Money.php` — `add/subtract/greaterThan/isZero/isZeroOrNegative/format()`; takes `(float $amount, string $currency='COP')`
- **Acceptance**: `php -l` clean on each; `composer test` still green
- **Depends on**: —
- **Branch**: `feat/erp-domain-foundations`

### A8 — Run Phase A checkpoint: `composer test` green, merge A1..A7
- **Type**: chore
- **Effort**: 0.5h
- **Acceptance**: `composer test` exits 0; 7 PRs opened; AGENTS.md "Gotchas" updated with ERP module entry
- **Depends on**: A1..A7
- **Branch**: `chore/phase-a-checkpoint`

---

## Phase B — Cuentas y Pagos (Days 3-5, ~28h)

### B1 — Migration: add `entidad_id` + `tipo_cuenta` to `cuentas`
- **Type**: test + feat
- **Effort**: 1.5h
- **Files**:
  - `database/migrations/2026_08_19_100000_add_entidad_id_and_tipo_cuenta_to_cuentas_table.php` (new) — adds `entidad_id` (FK `entidad`, nullable), `tipo_cuenta ENUM('proveedor','cliente') DEFAULT 'proveedor'`, INDEX `idx_cuentas_entidad`, CHECK constraint `(proveedor_id IS NOT NULL) XOR (entidad_id IS NOT NULL)`. All wrapped in `Schema::hasColumn()` guards for idempotency. `down()` reverses all.
  - `tests/Feature/Migration/CuentasTipoCuentaMigrationTest.php` (new) — `#[Test] public function migration_adds_columns_and_index()`, `#[Test] public function migration_is_idempotent_on_re_run()`
- **Acceptance**: `php artisan migrate:fresh` and `migrate:rollback` clean; both tests green
- **Depends on**: A8
- **Branch**: `feat/cuentas-tipo-cuenta`

### B2 — Write `ErpBankingAccountsTest` covering 15 scenarios from spec (RED)
- **Type**: test
- **Effort**: 2h
- **File**: `tests/Feature/ERP/ErpBankingAccountsTest.php` (new)
- **Tests (15, all from `erp-banking-accounts` spec REQ-BAN-001/002/003/004)**:
  - 5 from REQ-BAN-001: create proveedor/cliente happy, reject both FKs, reject missing FK, reject tipo_cuenta mismatch, reject unknown proveedor
  - 4 from REQ-BAN-002: list paginated, filter by tipo_cuenta, filter by proveedor_id, reject invalid tipo_cuenta value
  - 5 from REQ-BAN-003: update mutable fields, reject discriminator change, show 404, delete unreferenced, delete blocked by FK
  - 4 from REQ-BAN-004: allow rol 4, allow rol 5, reject rol 3 with 403, reject unauthenticated with 401
  - Helper trait `ErpAuthTestHelpers` (private trait or top of file) with `erpHeaders(int $rolId = 4): array`
- **Acceptance**: all 15 tests compile and FAIL (RED) — controllers don't exist yet
- **Depends on**: A4 (middleware), B1 (migration)
- **Branch**: `feat/erp-cuentas-crud`

### B3 — Implement Cuenta domain entity + Store/Update/Destroy use cases (GREEN)
- **Type**: feat
- **Effort**: 2.5h
- **Files** (all new):
  - `app/Domain/Entities/Cuenta.php` — pure PHP entity with `assert()` enforcing XOR (see design §2.1)
  - `app/Domain/Exceptions/InvalidCuentaException.php` — thrown by `assert()`
  - `app/Domain/Repositories/CuentaRepositoryInterface.php` (modify existing) — add `paginateWithFilters(array $filters, int $perPage)` returning `LengthAwarePaginator`
  - `app/Infrastructure/Persistence/EloquentCuentaRepository.php` (modify existing) — implement paginate + add `withEntidad()` / `withProveedor()` eager-load
  - `app/Application/UseCases/ERP/Cuenta/StoreCuentaUseCase.php` — calls entity `assert()` before persisting
  - `app/Application/UseCases/ERP/Cuenta/UpdateCuentaUseCase.php` — reject changes to discriminator fields (422)
  - `app/Application/UseCases/ERP/Cuenta/DestroyCuentaUseCase.php` — relies on QueryException 23000 → 422 mapping in `bootstrap/app.php` for FK blocks
- **Acceptance**: 15 tests from B2 pass; `php artisan test --filter=ErpBankingAccounts` is green
- **Depends on**: B2
- **Branch**: `feat/erp-cuentas-crud`

### B4 — HTTP layer for `/api/v1/erp/cuentas*`
- **Type**: feat
- **Effort**: 1.5h
- **Files** (all new):
  - `app/Http/Requests/ERP/CuentaRequest.php` — rules per design §5.3 with custom rule `xor:proveedor_id,entidad_id`
  - `app/Http/Resources/ERP/CuentaResource.php` — shape per design §5.4
  - `app/Http/Controllers/ERP/CuentaController.php` — thin: `__construct(Store,Update,Destroy,IndexCuentaUseCase,ShowCuentaUseCase)`, methods 3-5 lines each, uses `ApiResponse` trait
  - `routes/api.php` (extend) — add `Route::prefix('v1/erp')->middleware(['auth:sanctum','throttle-mutations','erp.auth'])->group(...)` with `Route::apiResource('cuentas', ...)` (5 routes)
- **Acceptance**:
  - All 15 tests still pass
  - `php artisan route:list --columns=method,uri,middleware | grep cuentas` shows 5 routes with `erp.auth` in middleware column
- **Depends on**: B3
- **Branch**: `feat/erp-cuentas-crud`

### B5 — Migration: create `pagos_cliente` table
- **Type**: test + feat
- **Effort**: 1h
- **Files**:
  - `database/migrations/2026_08_19_100001_create_pagos_cliente_table.php` (new) — columns per design §8 row 3: `id`, `entidad_id` (FK `entidad` RESTRICT), `servicio_id` (FK nullable), `cuenta_id` (FK nullable), `fecha`, `valor DECIMAL(15,2)`, `referencia`, `observaciones`, `created_by`, `created_at`, `updated_at`, `deleted_at`. INDEX `(entidad_id, fecha)`.
  - `tests/Feature/Migration/PagosClienteMigrationTest.php` (new) — `#[Test] public function table_has_required_columns()`, `#[Test] public function fk_constraints_enforced()`
- **Acceptance**: `migrate:fresh` clean; both tests green
- **Depends on**: B1
- **Branch**: `feat/erp-cxc-pagos-cliente`

### B6 — Write `ErpCxcPaymentsTest` covering 19 scenarios from spec (RED)
- **Type**: test
- **Effort**: 2.5h
- **File**: `tests/Feature/ERP/ErpCxcPaymentsTest.php` (new)
- **Tests (19, from `erp-cxc-payments` spec)**:
  - 5 from REQ-CXC-001: create happy, reject zero/negative valor, reject missing entidad_id, reject unknown entidad, reject unknown cuenta
  - 5 from REQ-CXC-002: list default pagination, filter by entidad_id, filter by date range, get single, get 404
  - 7 from REQ-CXC-003: apply to single invoice (saldo updates), apply split across N invoices, partial application (estado stays Emitida), reject sum > pago valor, reject duplicate application, atomic rollback mid-batch (one FK fails), reject cross-entidad application
  - 4 from REQ-CXC-004: update observaciones on unapplied pago, reject valor change after applications, soft-delete unapplied pago, block delete with applications
- **Acceptance**: 19 tests compile and FAIL (RED)
- **Depends on**: B5
- **Branch**: `feat/erp-cxc-pagos-cliente`

### B7 — Implement PagoCliente domain + CRUD use cases + AplicarPagoAFactura use case (GREEN)
- **Type**: feat
- **Effort**: 3.5h
- **Files** (all new):
  - `app/Domain/Entities/PagoCliente.php` — pure PHP, `valor > 0` invariant, `saldoDisponible()` method
  - `app/Domain/Repositories/PagoClienteRepositoryInterface.php`
  - `app/Infrastructure/Persistence/EloquentPagoClienteRepository.php` — with `withEntidad()`, `withCuenta()` eager-loads
  - `app/Application/UseCases/ERP/PagoCliente/StorePagoClienteUseCase.php`
  - `app/Application/UseCases/ERP/PagoCliente/UpdatePagoClienteUseCase.php` — guards `valor` change when applications exist
  - `app/Application/UseCases/ERP/PagoCliente/DestroyPagoClienteUseCase.php` — guards delete when applications exist (422)
  - `app/Application/UseCases/ERP/PagoCliente/ListPagosClienteUseCase.php`
  - `app/Application/UseCases/ERP/PagoCliente/AplicarPagoAFacturaUseCase.php` — `DB::transaction`, validates sum <= pago.valor, rejects cross-entidad, rejects duplicate (factura_pagos unique constraint), updates saldo via `FacturaRepositoryInterface::updateSaldo($id, Money $delta, string $estado)`
- **Acceptance**: 19 tests from B6 pass
- **Depends on**: B6
- **Branch**: `feat/erp-cxc-pagos-cliente`

### B8 — HTTP layer for `/api/v1/erp/pagos-cliente*`
- **Type**: feat
- **Effort**: 1h
- **Files** (all new):
  - `app/Http/Requests/ERP/PagoClienteRequest.php` — rules per design §5.3
  - `app/Http/Requests/ERP/AplicarPagoRequest.php` — `pago_cliente_id`, `factura_id`, `valor > 0`
  - `app/Http/Resources/ERP/PagoClienteResource.php`
  - `app/Http/Controllers/ERP/PagoClienteController.php` — CRUD + `aplicar(int $id, AplicarPagoRequest)` method
  - `routes/api.php` (extend) — `Route::apiResource('pagos-cliente', ...)->only(['index','store','show','update','destroy'])` + `Route::post('pagos-cliente/{id}/aplicar', ...)->name('erp.pagos-cliente.aplicar')`
- **Acceptance**: 19 tests pass; `route:list` shows 6 pagos-cliente routes
- **Depends on**: B7
- **Branch**: `feat/erp-cxc-pagos-cliente`

### B9 — Add `scopeForProveedor` to `Movimiento` model + write unit test
- **Type**: test + feat
- **Effort**: 1h
- **Files**:
  - `app/Models/Movimiento.php` (modify — add `scopeForProveedor(int $proveedorId)` that adds `where('proveedor_id', $proveedorId)->where('valor_debito', '>', 0)`)
  - `tests/Unit/Models/MovimientoScopeForProveedorTest.php` (new) — `#[Test] public function scope_filters_by_proveedor_and_excludes_creditos()`, `#[Test] public function scope_does_not_affect_existing_queries()`
- **Acceptance**: 2 tests green; existing 21+ MovimientoControllerTest still passes
- **Depends on**: —
- **Branch**: `feat/erp-movimientos-cxp-scope`

### B10 — Write `ErpCxpReportTest` covering 9 scenarios from spec (RED)
- **Type**: test
- **Effort**: 1h
- **File**: `tests/Feature/ERP/ErpCxpReportTest.php` (new)
- **Tests (9, from `erp-cxp-report` spec)**:
  - 5 from REQ-CXP-001: list for proveedor, reject missing proveedor_id, 404 on unknown proveedor, filter by date range, filter by cuenta_id
  - 3 from REQ-CXP-002: includes cuenta origen, cuenta null when unset, legacy cuenta included regardless of tipo
  - 1 from REQ-CXP-003: total_valor_debito sum + paginates + clamps per_page (combine in one test) — actually break into 3: `test_total_valor_debito`, `test_paginates_results`, `test_clamps_per_page_max`
  - 3 from REQ-CXP-004: allows rol 4, rejects rol 3 with 403, rejects unauthenticated with 401
- **Acceptance**: 9 tests compile and FAIL
- **Depends on**: B9
- **Branch**: `feat/erp-pagos-report`

### B11 — Implement `PagosReporteUseCase` + HTTP endpoint (GREEN)
- **Type**: feat
- **Effort**: 1.5h
- **Files** (all new):
  - `app/Application/UseCases/ERP/Reporte/PagosReporteUseCase.php` — uses `Movimiento::forProveedor($id)`, eager-loads `cuenta`, returns paginator with `total_valor_debito`
  - `app/Http/Requests/ERP/ReportePagosRequest.php`
  - `app/Http/Resources/ERP/PagoReporteResource.php`
  - `app/Http/Controllers/ERP/ReportePagosController.php`
  - `routes/api.php` (extend) — `Route::get('pagos', [ReportePagosController::class, 'index'])->name('erp.pagos')->withoutMiddleware('throttle-mutations')`
- **Acceptance**: 9 tests from B10 pass
- **Depends on**: B10
- **Branch**: `feat/erp-pagos-report`

### B12 — Run Phase B checkpoint
- **Type**: chore
- **Effort**: 0.5h
- **Acceptance**: `composer test` green; 12 PRs merged for Phase A+B; `route:list` shows 11 ERP routes (5 cuentas + 6 pagos-cliente + 1 pagos)
- **Depends on**: B1..B11
- **Branch**: `chore/phase-b-checkpoint`

---

## Phase C — Facturación y Cartera (Days 6-8, ~32h)

### C1 — Migrations: `facturas` + `factura_detalles` + `factura_pagos`
- **Type**: test + feat
- **Effort**: 2.5h
- **Files**:
  - `database/migrations/2026_08_19_100002_create_facturas_table.php` (new) — columns per design §8 row 4: `id`, `numero` (UNIQUE, but allow NULL pre-conversion), `tipo ENUM('proforma','factura')`, `entidad_id` FK RESTRICT, `servicio_id` FK SET NULL nullable, `fecha_emision`, `fecha_vencimiento` nullable, `subtotal/iva/total/saldo DECIMAL(15,2)`, `estado ENUM('Borrador','Emitida','Pagada','Anulada') DEFAULT 'Borrador'`, `observaciones` text, `created_by`, timestamps, `deleted_at`. INDEX `(entidad_id, saldo)`, INDEX `(estado)`, INDEX `(fecha_emision)`.
  - `database/migrations/2026_08_19_100003_create_factura_detalles_table.php` (new) — columns per design §8 row 5 + INDEX `(factura_id)`.
  - `database/migrations/2026_08_19_100004_create_factura_pagos_table.php` (new) — `id`, `factura_id` FK CASCADE, `pago_cliente_id` FK CASCADE, `valor_aplicado DECIMAL(15,2)`, timestamps. INDEX `(factura_id)`, INDEX `(pago_cliente_id)`, UNIQUE `(factura_id, pago_cliente_id)` (prevents duplicate applications).
  - `tests/Feature/Migration/FacturasMigrationTest.php` (new) — 3 tests (one per migration asserting columns + indexes)
- **Acceptance**: `migrate:fresh` clean; 3 tests green
- **Depends on**: B12
- **Branch**: `feat/erp-facturas-migrations`

### C2 — Write `ErpInvoicingTest` covering 22 scenarios from spec (RED)
- **Type**: test
- **Effort**: 3h
- **File**: `tests/Feature/ERP/ErpInvoicingTest.php` (new)
- **Tests (22, from `erp-invoicing` spec)**:
  - 4 from REQ-INV-001: create proforma with details (201 + totals computed), reject empty detalles, reject zero precio_unitario, atomic rollback on invalid detalle FK
  - 5 from REQ-INV-002: convert proforma→factura happy, reject double conversion, reject conversion without detalles, assigns unique numero sequentially, convert returns 404 on missing
  - 5 from REQ-INV-003: lists paginated with detalles_count, filters by tipo=factura, filters by estado, show includes detalles, show returns 404
  - 5 from REQ-INV-004: update proforma observaciones, reject detalle update on factura, reject update on anulada, soft-deletes borrador, blocks delete on emitida
  - 3 from REQ-INV-005: allows rol 4, rejects rol 3 with 403, rejects unauthenticated with 401
- **Acceptance**: 22 tests compile and FAIL
- **Depends on**: C1
- **Branch**: `feat/erp-facturas-crud`

### C3 — Implement Factura domain + FacturaDetalle + FacturaPago entities
- **Type**: feat
- **Effort**: 1.5h
- **Files** (all new, pure PHP):
  - `app/Domain/Entities/Factura.php` — with `addDetalle()`, `aplicarPago(PagoCliente, Money)` (asserts saldo>=valor, transitions to Pagada when saldo=0), `convertir()` (asserts tipo=proforma, generates numero)
  - `app/Domain/Entities/FacturaDetalle.php` — `subtotal = cantidad * precio_unitario * (1 - descuento/100)`, `total = subtotal * (1 + iva/100)`
  - `app/Domain/Entities/FacturaPago.php` — `valor_aplicado`, relationships
- **Acceptance**: `php -l` clean; unit tests on entity invariants (`tests/Unit/Domain/FacturaEntityTest.php`) — 3 tests for saldo invariants, estado transitions, conversion rules
- **Depends on**: A7
- **Branch**: `feat/erp-facturas-crud`

### C4 — Implement Eloquent Factura repository + Store/Update/Show use cases (GREEN for INV-001/003/004)
- **Type**: feat
- **Effort**: 3h
- **Files** (all new):
  - `app/Domain/Repositories/FacturaRepositoryInterface.php` — methods per design §3: `find`, `findByNumero`, `paginateForCartera`, `withLock`, `createWithDetalles(array)`, `updateSaldo($id, Money $delta, string $estado)`, `createPivot(PagoCliente, Money)`
  - `app/Models/Factura.php` — Eloquent with `SoftDeletes`, relationships (`entidad`, `servicio`, `detalles`, `pagos`)
  - `app/Models/FacturaDetalle.php`
  - `app/Models/FacturaPago.php`
  - `app/Infrastructure/Persistence/EloquentFacturaRepository.php`
  - `app/Application/UseCases/ERP/Factura/StoreFacturaUseCase.php` — `DB::transaction`, INSERT factura + N detalles, computes subtotal/iva/total server-side, generates `numero = PROFORMA-YYYY-NNN` via running counter query `SELECT MAX(numero) WHERE numero LIKE 'PROFORMA-YYYY-%'`
  - `app/Application/UseCases/ERP/Factura/UpdateFacturaUseCase.php` — rejects when `estado='Emitida'|'Pagada'|'Anulada'` AND `detalles` in payload
  - `app/Application/UseCases/ERP/Factura/ShowFacturaUseCase.php`
  - `app/Application/UseCases/ERP/Factura/IndexFacturaUseCase.php`
  - `app/Application/UseCases/ERP/Factura/DestroyFacturaUseCase.php` — soft-delete via `SoftDeletes` trait, only when `estado='Borrador'`
- **Acceptance**: 17 tests from C2 (REQ-INV-001, REQ-INV-003, REQ-INV-004) pass
- **Depends on**: C2, C3
- **Branch**: `feat/erp-facturas-crud`

### C5 — Implement `ConvertirProformaAFacturaUseCase` (GREEN for INV-002)
- **Type**: feat
- **Effort**: 1.5h
- **Files** (all new):
  - `app/Application/UseCases/ERP/Factura/ConvertirProformaAFacturaUseCase.php` — `DB::transaction`, `SELECT ... FOR UPDATE` on factura, validates tipo=proforma + has detalles, generates `numero = F-YYYY-NNNNNN` running counter via MAX+1, sets `estado='Emitida'`, fires `event(new FacturaConvertida($factura))`
  - `app/Http/Controllers/ERP/FacturaController.php` (extend) — add `convertirProforma(int $id, ConvertirFacturaRequest $r)` method
  - `app/Http/Requests/ERP/ConvertirFacturaRequest.php` — `fecha_emision:required|date`, `fecha_vencimiento:nullable|date|after_or_equal:fecha_emision`
- **Acceptance**: 5 tests from C2 (REQ-INV-002) pass
- **Depends on**: C4
- **Branch**: `feat/erp-facturas-convertir`

### C6 — HTTP layer for `/api/v1/erp/facturas*` + verify all REQ-INV tests
- **Type**: feat
- **Effort**: 1.5h
- **Files** (all new):
  - `app/Http/Requests/ERP/FacturaRequest.php` — rules per design §5.3
  - `app/Http/Resources/ERP/FacturaResource.php` — shape per design §5.4
  - `app/Http/Resources/ERP/FacturaDetalleResource.php`
  - `app/Http/Controllers/ERP/FacturaController.php` (finalise) — constructor with all 5 use cases (Store, Update, Show, Destroy, ConvertirProforma, AplicarPago placeholder)
  - `routes/api.php` (extend) — `Route::apiResource('facturas', ...)`, `Route::post('facturas/{id}/convertir', ...)->name('erp.facturas.convertir')`
- **Acceptance**: all 22 tests from C2 pass; `route:list` shows 8 factura routes
- **Depends on**: C5
- **Branch**: `feat/erp-facturas-crud`

### C7 — Write `ErpCarteraReportTest` covering 9 scenarios from spec (RED)
- **Type**: test
- **Effort**: 1.5h
- **File**: `tests/Feature/ERP/ErpCarteraReportTest.php` (new)
- **Tests (9, from `erp-cartera-report` spec)**:
  - 4 from REQ-CAR-001: returns aging buckets (4 buckets: 0-30, 31-60, 61-90, 90+), rejects missing entidad_id, 404 on unknown entidad, excludes Pagada facturas
  - 2 from REQ-CAR-002: includes client cuenta, cuenta null when unregistered
  - 3 from REQ-CAR-003: allows rol 4, rejects rol 3 with 403, second request hits cache (assert `Cache::has(...)`)
  - 2 from REQ-CAR-004: paginates results, clamps per_page max
  - **[DECISION]:** spec OQ-3 (explicit `Emitida → Anulada` transition) — **NOT in v1**; soft-delete via `deleted_at` suffices. Document in AD-2.
- **Acceptance**: 9 tests compile and FAIL
- **Depends on**: C6
- **Branch**: `feat/erp-cartera-report`

### C8 — Implement `CarteraReporteUseCase` + `AgingBucketCalculator` Strategy (GREEN)
- **Type**: feat
- **Effort**: 3h
- **Files** (all new):
  - `app/Domain/Services/AgingBucketCalculator.php` — Strategy pattern: `bucket(int $daysDiff): string` returning one of `0-30|31-60|61-90|90+`
  - `app/Domain/Services/AgingBucketClassifier.php` — interface (Strategy contract)
  - `app/Application/UseCases/ERP/Reporte/CarteraReporteUseCase.php` — `Cache::remember("erp.cartera.{$entidadId}." . today()->toDateString(), 300, fn() => ...)`, filters `estado='Emitida' AND saldo > 0`, groups by bucket, includes latest `pagos_cliente.cuenta_id` for the entidad
  - `app/Http/Requests/ERP/ReporteCarteraRequest.php` — `entidad_id:required|exists:entidad,id`, `per_page:integer|min:1|max:100`
  - `app/Http/Resources/ERP/FacturaCarteraResource.php` — embeds `cuenta` (nullable)
  - `app/Http/Controllers/ERP/ReporteCarteraController.php`
  - `routes/api.php` (extend) — `Route::get('cartera', [ReporteCarteraController::class, 'index'])->name('erp.cartera')->withoutMiddleware('throttle-mutations')`
- **Acceptance**: 9 tests from C7 pass; cache invalidates via event listener on `PagoAplicado` event
- **Depends on**: C7
- **Branch**: `feat/erp-cartera-report`

### C9 — Run Phase C checkpoint
- **Type**: chore
- **Effort**: 0.5h
- **Acceptance**: `composer test` green; `route:list` shows 19 ERP routes total; Cartera endpoint returns valid JSON for seeded data
- **Depends on**: C1..C8
- **Branch**: `chore/phase-c-checkpoint`

---

## Phase D — Recurrencia y Scheduler (Days 9-10 AM, ~20h)

### D1 — Migrations: `facturacion_recurrente` + `pago_recurrente_proveedor`
- **Type**: test + feat
- **Effort**: 1.5h
- **Files**:
  - `database/migrations/2026_08_19_100005_create_facturacion_recurrente_table.php` (new) — columns per design §8 row 7: `id`, `entidad_id` FK RESTRICT, `servicio_id` FK SET NULL nullable, `frecuencia ENUM('mensual','trimestral','semestral','anual')`, `proxima_emision` date, `vigencia_hasta` date nullable, `vr_base DECIMAL(15,2)`, `estado ENUM('Activa','Pausada','Cancelada') DEFAULT 'Activa'`, `ultimo_periodo_facturado` date nullable, `created_by`, timestamps, `deleted_at`. INDEX `(estado, proxima_emision)`.
  - `database/migrations/2026_08_19_100006_create_pago_recurrente_proveedor_table.php` (new) — symmetric columns, INDEX `(estado, proximo_pago)`.
  - `tests/Feature/Migration/RecurrenteMigrationTest.php` (new) — 2 tests
- **Acceptance**: `migrate:fresh` clean; 2 tests green
- **Depends on**: C9
- **Branch**: `feat/erp-recurrente-migrations`

### D2 — Write `ErpRecurringSchedulerTest` covering 23 scenarios from spec (RED)
- **Type**: test
- **Effort**: 2.5h
- **File**: `tests/Feature/ERP/ErpRecurringSchedulerTest.php` (new)
- **Tests (23, from `erp-recurring-scheduler` spec)**:
  - 7 from REQ-REC-001: create happy, reject invalid frecuencia, reject zero vr_base, filter by estado, update preserves proxima_emision, soft-delete, 404
  - 4 from REQ-REC-002: create pago recurrente happy, reject missing concepto, list, soft-delete
  - 4 from REQ-REC-003: pause activa, resume pausada, pause idempotent, scheduler skips pausada
  - 6 from REQ-REC-004: scheduler creates one factura for due, idempotent on same day, advances trimestral, pauses past vigencia, isolates failure per row (per-row rollback — **[DECISION]**: spec OQ-4 → per-row, log + continue), outputs summary
  - 2 from REQ-REC-005: allows rol 4, rejects rol 3 with 403
  - **[DECISION]:** spec OQ-5 retroactiva recurrencia — **START CLEAN**, no backfill on first run. Add `--backfill` flag in command for future. Document in design §7.5 footnote.
- **Acceptance**: 23 tests compile and FAIL
- **Depends on**: D1
- **Branch**: `feat/erp-recurrente-crud`

### D3 — Implement FacturacionRecurrente + PagoRecurrenteProveedor domain + repositories + CRUD use cases (GREEN for REC-001/002/003)
- **Type**: feat
- **Effort**: 3h
- **Files** (all new):
  - `app/Domain/Entities/FacturacionRecurrente.php` — `assert()`, `avanzarProximaEmision(Carbon $hoy)`, `pausar()`, `reanudar()`, `cancelar()`
  - `app/Domain/Entities/PagoRecurrenteProveedor.php` — symmetric
  - `app/Domain/Repositories/FacturacionRecurrenteRepositoryInterface.php` — `dueAsOf(Carbon $fecha): Collection` (filters estado='Activa' + proxima_emision <= fecha + deleted_at IS NULL + FOR UPDATE SKIP LOCKED with MariaDB 10.6+ fallback to plain FOR UPDATE)
  - `app/Domain/Repositories/PagoRecurrenteProveedorRepositoryInterface.php`
  - `app/Models/FacturacionRecurrente.php` + `app/Models/PagoRecurrenteProveedor.php`
  - `app/Infrastructure/Persistence/EloquentFacturacionRecurrenteRepository.php`
  - `app/Infrastructure/Persistence/EloquentPagoRecurrenteProveedorRepository.php`
  - `app/Application/UseCases/ERP/Recurrente/{Store,Update,Destroy,Index,Show}FacturacionRecurrenteUseCase.php`
  - `app/Application/UseCases/ERP/Recurrente/{Store,Update,Destroy,Index,Show}PagoRecurrenteProveedorUseCase.php`
  - `app/Application/UseCases/ERP/Recurrente/PausarFacturacionRecurrenteUseCase.php` + `ReanudarFacturacionRecurrenteUseCase.php` (symmetric for pago_recurrente)
- **Acceptance**: 17 tests from D2 (REC-001/002/003) pass
- **Depends on**: D2
- **Branch**: `feat/erp-recurrente-crud`

### D4 — HTTP layer for `/api/v1/erp/facturacion-recurrente*` and `/api/v1/erp/pagos-recurrentes*`
- **Type**: feat
- **Effort**: 1.5h
- **Files** (all new):
  - `app/Http/Requests/ERP/FacturacionRecurrenteRequest.php`
  - `app/Http/Requests/ERP/PagoRecurrenteProveedorRequest.php`
  - `app/Http/Resources/ERP/FacturacionRecurrenteResource.php`
  - `app/Http/Resources/ERP/PagoRecurrenteProveedorResource.php`
  - `app/Http/Controllers/ERP/FacturacionRecurrenteController.php` — CRUD + `pausar()`, `reanudar()`
  - `app/Http/Controllers/ERP/PagoRecurrenteProveedorController.php` — CRUD + `pausar()`, `reanudar()`
  - `routes/api.php` (extend) — `Route::apiResource('facturacion-recurrente', ...)`, `Route::post('facturacion-recurrente/{id}/pausar', ...)`, `/reanudar`; `Route::apiResource('pagos-recurrentes', ...)`
- **Acceptance**: 17 HTTP tests pass; `route:list` shows 12 recurrence routes
- **Depends on**: D3
- **Branch**: `feat/erp-recurrente-crud`

### D5 — Implement `GenerarFacturasRecurrentesUseCase` (GREEN for REC-004 partial)
- **Type**: feat
- **Effort**: 1.5h
- **Files** (all new):
  - `app/Application/UseCases/ERP/Recurrente/GenerarFacturasRecurrentesUseCase.php` — per-row `DB::transaction`: call `repo->dueAsOf($fecha)`, for each row call `StoreFacturaUseCase::execute(tipo='proforma', total=vr_base)`, advance `ultimo_periodo_facturado + proxima_emision`, pause if past `vigencia_hasta`, fire `RecurrenciaGenerada` event. Per-row rollback on failure (log + continue to next row).
- **Acceptance**: unit test `tests/Unit/Application/GenerarFacturasRecurrentesUseCaseTest.php` with 3 tests (happy path, per-row failure isolation, idempotency via `ultimo_periodo_facturado` guard) passes
- **Depends on**: D3, C4 (StoreFacturaUseCase)
- **Branch**: `feat/erp-recurrente-scheduler`

### D6 — Implement `FinancieroGenerarFacturasRecurrentesCommand` (GREEN for REC-004)
- **Type**: feat
- **Effort**: 1h
- **Files**:
  - `app/Console/Commands/FinancieroGenerarFacturasRecurrentesCommand.php` (new) — signature `financiero:generar-facturas-recurrentes {--dry-run} {--entidad-id=} {--fecha=YYYY-MM-DD}`; outputs `Generated N facturas, skipped M, errors K` summary line
- **Acceptance**: 6 scheduler tests from D2 (REQ-REC-004) pass; `php artisan financiero:generar-facturas-recurrentes --dry-run` prints without side effects
- **Depends on**: D5
- **Branch**: `feat/erp-recurrente-scheduler`

### D7 — Hook scheduler in `routes/console.php`
- **Type**: chore
- **Effort**: 0.25h
- **File**: `routes/console.php` (modify) — add `Schedule::command('financiero:generar-facturas-recurrentes')->dailyAt('02:00')->timezone('America/Bogota')->withoutOverlapping(60)->onOneServer()->runInBackground()->emailOutputOnFailure(env('OPS_ALERT_EMAIL'))`
- **Acceptance**: `php artisan schedule:list` shows the new entry
- **Depends on**: D6
- **Branch**: `feat/erp-recurrente-scheduler`

### D8 — Update `AGENTS.md` with ERP module section
- **Type**: docs
- **Effort**: 0.5h
- **File**: `AGENTS.md` (modify) — add new "ERP module" section with: capabilities, endpoints table, command list, gotchas (rol_id 4/5, no CUFE/CUDE, IVA stored not computed)
- **Acceptance**: PR with updated AGENTS.md is merged
- **Depends on**: D7
- **Branch**: `docs/erp-agentes-md`

### D9 — Create `Docs/openapi/erp-financiero.yaml`
- **Type**: docs
- **Effort**: 1.5h
- **File**: `Docs/openapi/erp-financiero.yaml` (new) — OpenAPI 3.1 spec covering all 19 ERP endpoints (cuentas, pagos-cliente, facturas, facturacion-recurrente, pagos-recurrentes, cartera, pagos) with request/response schemas derived from Form Requests + Resources. Use `Docs/openapi/auth.yaml` as reference for structure.
- **Acceptance**: file validates against `openapi-spec-validator` (`pip install openapi-spec-validator && openapi-spec-validator Docs/openapi/erp-financiero.yaml` exits 0)
- **Depends on**: D8
- **Branch**: `docs/erp-openapi-spec`

### D10 — Run Phase D checkpoint
- **Type**: chore
- **Effort**: 0.5h
- **Acceptance**: `composer test` green; `php artisan schedule:list` shows scheduler entry; `route:list | grep erp/` shows 29 routes total
- **Depends on**: D1..D9
- **Branch**: `chore/phase-d-checkpoint`

---

## Phase E — Polish & Buffer (Day 10 PM, ~6h)

### E1 — E2E happy-path test of the full ERP flow
- **Type**: test
- **Effort**: 2h
- **File**: `tests/Feature/ERP/E2EFlowTest.php` (new)
- **Coverage**: seed Entidad + Servicio → create proforma → convert to factura → register pago_cliente → apply pago to factura → assert saldo=0 + estado=Pagada → fetch `/cartera` → assert factura does NOT appear in buckets
- **Acceptance**: single test green; logs the flow's HTTP responses for visual verification
- **Depends on**: D10
- **Branch**: `test/erp-e2e-flow`

### E2 — Performance test: `/cartera` p95 < 2s with 10k facturas
- **Type**: test
- **Effort**: 1.5h
- **File**: `tests/Feature/ERP/CarteraPerformanceTest.php` (new)
- **Coverage**: seed 10k `facturas` rows (faker-generated, 100 entidades), call `GET /api/v1/erp/cartera?entidad_id=X` 5 times, assert median latency < 2s. Tag test with `#[Group('performance')]` so it can be excluded in CI default run.
- **Acceptance**: `$this->assertLessThan(2000, $medianMs)` passes; test skip mechanism (`SKIP_PERFORMANCE_TESTS=1`) documented
- **Depends on**: C8
- **Branch**: `test/erp-cartera-perf`

### E3 — Domain unit tests for Factura + PagoCliente entity invariants
- **Type**: test
- **Effort**: 1h
- **Files**:
  - `tests/Unit/Domain/FacturaEntityTest.php` (new) — 5 tests: `aplicarPago_below_saldo_succeeds`, `aplicarPago_above_saldo_throws_domain_exception`, `aplicarPago_to_zero_marks_pagada`, `convertir_proforma_sets_tipo_and_numero`, `convertir_already_factura_throws`
  - `tests/Unit/Domain/FacturacionRecurrenteEntityTest.php` (new) — 3 tests: `avanzar_mensual_adds_one_month`, `avanzar_trimestral_adds_three_months`, `avanzar_past_vigencia_marks_pausada`
- **Acceptance**: 8 unit tests green
- **Depends on**: C3, D3
- **Branch**: `test/erp-domain-unit`

### E4 — Final full-suite run + lint + coverage check
- **Type**: chore
- **Effort**: 1h
- **Acceptance**:
  - `composer test` 100% green
  - `vendor/bin/pint --test` no errors
  - `php artisan test --coverage --min=80` (new code only) ≥80%
  - All 10 PRs (A1..D10) merged to `develop` (or current sprint branch)
  - AGENTS.md updated with the new "ERP module" section
  - `Docs/openapi/erp-financiero.yaml` validates
- **Depends on**: E1, E2, E3
- **Branch**: `chore/erp-sprint-closeout`

---

## Sprint Total

| Phase | Days | Tasks | Estimated effort | Tests expected |
|---|---|---|---|---|
| A — Foundations | 1-2 | 8 (A1..A8) | ~7h | ~10 (middleware + HU01+HU02 + entity unit) |
| B — Cuentas y Pagos | 3-5 | 12 (B1..B12) | ~21h | ~45 (15 cuentas + 19 pagos-cliente + 9 pagos + 2 movimiento + migration) |
| C — Facturación y Cartera | 6-8 | 9 (C1..C9) | ~19h | ~34 (22 facturas + 9 cartera + 3 facturas entity unit) |
| D — Recurrencia y Scheduler | 9-10 AM | 10 (D1..D10) | ~13.5h | ~26 (23 recurrence + 3 use-case unit) |
| E — Polish & Buffer | 10 PM | 4 (E1..E4) | ~5.5h | ~3 (E2E + perf + 8 entity unit) |
| **TOTAL** | **10** | **43** | **~66h** | **~118** |

> Note: the launch prompt listed 24 tasks; this document decomposes them further into **43 individual tasks** to align with the precedent (`production-blockers-and-ux-fixes/tasks.md`) which uses 15-21 tasks per PR. Each numbered task (e.g. `A4`, `B7`) is one atomic PR.

## Branching strategy

One branch per atomic task, prefixed per `branch-pr` convention:
- `feat/<slug>` for new code (e.g. `feat/erp-cuentas-crud`, `feat/erp-facturas-convertir`)
- `test/<slug>` for test-only changes (e.g. `test/document-hu01-hu02`, `test/erp-e2e-flow`)
- `chore/<slug>` for refactor/cleanup (e.g. `chore/clean-administrativo-module`)
- `docs/<slug>` for documentation (e.g. `docs/adr-persona-unification`, `docs/erp-openapi-spec`)

PRs merge into `develop` (or current sprint branch) as they complete.

## Open decisions (require user confirmation during apply)

1. **Spec OQ-1 (Cuenta destino heuristic)**: spec leaves open whether `facturas.cuenta_destino_id` should be deterministic per-factura OR heuristic from latest pago. **[DECISION]**: v1 uses **HEURISTIC** (look up latest `pagos_cliente.cuenta_id` for entidad, fallback to `null`). Future ticket: add `facturas.cuenta_destino_id` column + migration.
2. **Spec OQ-2 (numero generation)**: design says autogenerate `F-YYYY-NNNNNN`; user-input also possible. **[DECISION]**: AUTOGENERATE on `convertir` action. Counter is `MAX(CAST(SUBSTRING(numero, -6) AS UNSIGNED)) + 1` inside `lockForUpdate()` transaction.
3. **Spec OQ-3 (explicit Emitida → Anulada transition)**: spec lists this as scenario; design §2.2 marks Anulada as terminal-no-pago state. **[DECISION]**: NOT in v1; soft-delete via `deleted_at` suffices. AD-2 documents this.
4. **Spec OQ-4 (scheduler rollback semantics)**: spec lists two options (per-row vs whole-batch). **[DECISION]**: per-row; one bad row logs error and continues to next row (allows other tenants' recurrences to process). ADR-002 captures this.
5. **Spec OQ-5 (retroactive recurrencia backfill)**: spec asks if first run should backfill missed periods. **[DECISION]**: START CLEAN. Add `--backfill` flag in command for future use; first run does NOT generate missed periods.
6. **HU11 (cashflow projection)**: spec calls out as deferred. **[DECISION]**: defer to separate change `AFIN-002`. Document in proposal §3 "Out of scope".
7. **`rol_id` for ERP users**: default `[4, 5]` configurable via `ERP_ALLOWED_ROL_IDS` env. **[DECISION]**: confirm in `apply` phase if team uses different IDs in production.
8. **`Modules/Administrativo` cleanup (A1)**: complete purge vs keep namespace. **[DECISION]**: complete purge of `app/Models/` subdir (8 files) but keep `module.json` so nwidart still recognises the module. `app/Models/Cuenta.php` and `app/Models/Movimiento.php` become self-contained (no longer extend the module's classes).
9. **`factura numero` format**: spec uses `F-YYYY-NNNNNN`. **[DECISION]**: align with launch prompt default `FAC-{YYMMDD}-{seq}`? **STICK WITH `F-YYYY-NNNNNN`** (design §2.2 example value) since it's already validated by spec scenario "convert assigns unique numero".
10. **CHECK constraint on `cuentas`**: MariaDB ≥10.2 supports CHECK under `sql_mode='ANSI'`. **[DECISION]**: ASSUME supported. App-layer validation is primary defense (per design §2.1 `entity->assert()`). If MariaDB version is <10.2, the migration's CHECK is wrapped in `try/catch` and logs warning.

## Notes for apply phase

- **Backward compatibility**: A1 deletes `Modules\Administrativo\app\Models\*.php` — verify no test imports from `Modules\Administrativo\Models\*` before deleting (use `grep -r "Modules\\\\Administrativo\\\\Models" tests/`).
- **Concurrency**: SPEC INV-002 REQ specifies `lockForUpdate()` for numero generation. Apply D5 + D6 must use `DB::transaction` + `lockForUpdate()` (NOT plain INSERT) to avoid races.
- **Cache key for `/cartera`**: C8 uses `today()->toDateString()` in cache key. This means cache auto-expires daily at midnight America/Bogota — intentional, aligns with spec REQ-CAR-003.
- **Factura numero uniqueness**: even though `F-YYYY-NNNNNN` is generated inside `lockForUpdate()`, add a `UNIQUE` constraint on `facturas.numero` as defense in depth (C1 migration).
- **HU01+HU02 already implemented**: `GanarOportunidadUseCase` already auto-creates Servicio and sets Entidad.estado='Cliente'. A6 only adds the missing tests for `cliente_desde` and the "first win vs subsequent wins" invariant.
- **Test file naming**: spec files use `ErpBankingAccountsTest.php`, `ErpCxcPaymentsTest.php`, etc. (one per capability). If a capability has too many tests for one file, split into `ErpCarteraReportBucketsTest.php` + `ErpCarteraReportCacheTest.php`. Keep the prefix `Erp` + `<Capability>` consistent.
- **Money VO usage**: Apply C7 should accept Money from request (cast via Form Request) and pass Money to use cases. Convert `float` ↔ `Money` in the Form Request layer.
