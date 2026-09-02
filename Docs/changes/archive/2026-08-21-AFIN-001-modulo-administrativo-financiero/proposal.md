# Proposal: AFIN-001 — Módulo Administrativo-Financiero (Minerva)

> **Status: Archived** (2026-08-21) — All 7 capabilities shipped across phases A→E plus Phase F hot-fixes. See `archive-report.md` for final state, `verify-report.md` for verification, and `Docs/openspec/specs/erp-*/spec.md` for synced main specs.
>
> **Branch**: `feat/erp-phase-f` (to be merged to develop)

## 1. Why

The current CRM is strong on pre-sale (leads, opportunities, diagnostic) but stops at the post-sale boundary: once an opportunity is won, the resulting service, invoice, collection, and treasury flow are tracked manually or in disconnected spreadsheets. This change operationalizes the **post-sale cycle** (service → invoice → payment → cartera) inside the same Laravel backend, without introducing a full accounting scope (R01: no plan de cuentas, no partida doble). It also gives the ERP dashboard a dedicated RBAC wall and a stable API contract so the existing FastAPI/Vue front (`dashboard-crm`) can consume the administrative-financial module independently of the CRM. The user-facing value: a single source of truth for CxC, CxP, recurrence, and treasury projection, with integridad transaccional garantizada (AC01/AC07).

## 2. What changes

**New tables** (all under `database/migrations/2026_08_19_*`):
- `pagos_cliente` — CxC ledger, ingresos de clientes.
- `facturas` — pro-formas + facturas con discriminador `tipo ENUM('proforma','factura')`.
- `factura_detalles` — line items de factura.
- `factura_pagos` — M2M factura↔pago_cliente con `valor_aplicado` (aplicación N:M).
- `facturacion_recurrente` — recurrencia cliente (mensual/trimestral/semestral/anual).
- `pago_recurrente_proveedor` — recurrencia proveedor.

**Modified tables**:
- `cuentas` — add `entidad_id` nullable + `tipo_cuenta ENUM('proveedor','cliente')` + CHECK constraint.
- `oportunidad` — convert `frecuencia` column to ENUM.

**New endpoints** (all under `/api/v1/erp/*` with `erp.auth` middleware):
- `/api/v1/erp/cuentas` — CRUD cuentas cliente/proveedor.
- `/api/v1/erp/pagos-cliente` — CRUD pagos recibidos.
- `/api/v1/erp/facturas` + `/{id}/convertir` + `/{id}/aplicar-pago` — CRUD + transiciones.
- `/api/v1/erp/cartera` — reporte CxC con aging buckets.
- `/api/v1/erp/pagos` — reporte CxP por proveedor.
- `/api/v1/erp/facturacion-recurrente` y `/api/v1/erp/pagos-recurrentes` — CRUD recurrencia.

**New use cases** (`app/Application/UseCases/Administrativo/`):
- `Cuenta/{Store,Update,Destroy}CuentaUseCase.php`
- `PagoCliente/{Store,Update,Destroy,List}PagoClienteUseCase.php`
- `Factura/{Store,Update,Convertir,AplicarPago}FacturaUseCase.php`
- `Reporte/{Cartera,Pagos}ReporteUseCase.php`
- `Recurrente/{Store,Update,Pausar,Reanudar,Generar}RecurrenteUseCase.php`

**New middleware + scheduler**:
- `App\Infrastructure\Auth\ErpAuthMiddleware` (alias `erp.auth`) — valida `rol_id IN (4,5)`.
- `App\Console\Commands\FinancieroGenerarFacturasRecurrentesCommand` — comando `financiero:generar-facturas-recurrentes` scheduled daily 02:00 America/Bogota.

**Schema additions for ADR** (Persona unification): ADR-002 documenta el path; sin migración de runtime.

## 3. Scope

**In scope (Sprint 1, 2 semanas, 1 dev full-stack)**:
- Auto-creación de Servicio + Detalle al ganar oportunidad (HU01+HU02) — ya implementado, falta test + doc.
- Cuentas cliente/proveedor en `cuentas` con constraint (HU03).
- Pagos a proveedores (CxP) — extender `movimientos` (HU04).
- Pagos recibidos de clientes (CxC) — nueva tabla `pagos_cliente` (HU05).
- Reporte cartera con aging 0-30/31-60/61-90/90+ (HU06).
- Reporte pagos por proveedor (HU07).
- Pro-formas + conversión a factura (HU08).
- Facturación/pagos recurrentes + scheduler idempotente (HU09+HU10).
- Middleware `erp.auth` + prefijo `/api/v1/erp/*` (HU12).
- ADR-002 Persona unification (deferred implementation).

**Out of scope (explicit)**:
- Plan contable / partida doble / libro diario (R01).
- Cálculo de impuestos (IVA se almacena, no se calcula).
- Conciliación bancaria automatizada.
- Integración con bancos / pasarela de pagos.
- Multi-moneda (asumimos COP).
- Notificaciones automáticas de mora.
- Reportes financieros avanzados (estado de resultados, balance).
- Persona unification como refactor activo (solo ADR).

## 4. Approach

**Module structure** — `Modules/Administrativo` está vacío con shadow models. **Decisión**: limpiar a 1 source of truth en `app/` raíz (Clean Architecture: Domain → Application → Infrastructure → Interfaces); `Modules/Administrativo` queda como namespace reservado para use cases específicos del ERP. Migrations viven en `database/migrations/` raíz; controllers en `app/Http/Controllers/ERP/`.

**Ledger strategy** — **Split ledger**: `movimientos` se mantiene como histórico puro para CxP (egreso) sin refactor; se crea `pagos_cliente` para CxC (ingreso). Razón: 21+ tests existentes referencian `movimientos`; refactor unificado es destructivo. La tabla pivote `factura_pagos` materializa la aplicación N:M de un pago a varias facturas.

**RBAC strategy** — Middleware `erp.auth` registrado en `bootstrap/app.php` con alias; valida `rol_id IN (4,5)` desde `config('erp.allowed_roles')` (configurable sin redeploy). Aplicado al grupo `Route::prefix('erp')->middleware(['auth:sanctum', 'erp.auth'])` dentro de `/api/v1`. Front ERP separado consume con token Sanctum + claim `erp_scope` opcional.

**Scheduler strategy** — `Schedule::command('financiero:generar-facturas-recurrentes')->dailyAt('02:00')->timezone('America/Bogota')->withoutOverlapping()->onOneServer()->runInBackground()` en `routes/console.php`. Idempotencia garantizada por `ultimo_periodo_facturado` + `proxima_emision` (avanzar Atomic en DB::transaction).

**TDD approach** — Strict TDD (RED → GREEN → REFACTOR). Una feature test por use case cubriendo happy path + error path. Atomicidad probada con `RefreshDatabase` + `assertDatabaseHas`/`assertDatabaseMissing` post-rollback. Tests transactionales en `tests/Feature/ERP/` siguiendo el patrón de `tests/Feature/API/PlanControllerTest.php`. Cobertura objetivo >80% en código nuevo.

## 5. Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Refactor de `cuentas` rompe 21+ tests existentes | M | M | Migration aditiva; tests con `RefreshDatabase`; constraint reforzado en app layer. |
| CHECK constraint no soportado en MariaDB antiguo | L | M | Validación en `StoreCuentaUseCase`; CHECK como refuerzo, no como única defensa. |
| Scheduler duplica facturas en race condition | L | H | `withoutOverlapping()->onOneServer()` + test idempotente. |
| Performance de `/cartera` con 10k facturas | M | M | Índices `(entidad_id, saldo)`, paginación obligatoria, cache 5min. |
| Persona unification pospuesta bloquea futuro | M | M | ADR-002 documenta el path; revisión cada sprint. |

## 6. Success criteria

- ✅ `php artisan test` pasa al 100%; cobertura >80% en código nuevo.
- ✅ E2E completo: ganar oportunidad → servicio → factura → pago → saldo actualizado → visible en cartera.
- ✅ Scheduler `financiero:generar-facturas-recurrentes` ejecuta sin errores y es idempotente.
- ✅ Front ERP consume `/api/v1/erp/*` con token `rol_id=4`; cross-access `rol_id≠4,5` retorna 403.
- ✅ Latencia p95 `/cartera` < 2s con 10k registros.
- ✅ Sin migraciones destructivas; schema compatible con alcance contable futuro.

## 7. References

- **ADD**: `Docs/design/ADD-AFIN-001-modulo-administrativo-financiero.md` (source of truth arquitectónico).
- **Previous change**: `production-blockers-and-ux-fixes` (cerrado) — patrón de migrations aditivas + tests.
- **Existing precedent**: `Docs/design/ADD-AUTH-001-multi-app-auth.md` — midpoint de RBAC con token Sanctum + `ValidateApiKeyMiddleware`.
- **Implementation guide**: `GanarOportunidadUseCase.php` (HU01+HU02 ya implementadas), `ListUsersForSnapshotUseCase.php` (CQRS-Lite read model).

---

## Capabilities (contract with sdd-spec)

### New Capabilities
- `erp-banking-accounts` — CRUD de cuentas cliente/proveedor con constraint de exclusividad.
- `erp-cxc-payments` — Registro y listado de pagos recibidos de clientes.
- `erp-invoicing` — Pro-formas, conversión a factura, aplicación de pagos.
- `erp-cartera-report` — Reporte CxC con aging buckets.
- `erp-cxp-report` — Reporte CxP por proveedor.
- `erp-recurring-scheduler` — Recurrencia cliente/proveedor + scheduler daily.
- `erp-rbac-middleware` — Middleware `erp.auth` con `rol_id IN (4,5)`.

### Modified Capabilities
- None (no existing `openspec/specs/` baseline; this is greenfield).

---

## Rollback Plan

Como las migrations son **aditivas** (no destructivas), el rollback es mecanico:
1. Revertir las 6 migrations nuevas + 2 alter table con `php artisan migrate:rollback --step=8`.
2. Remover el grupo `Route::prefix('erp')` añadido a `routes/api.php`.
3. Quitar el alias `erp.auth` de `bootstrap/app.php` y eliminar `ErpAuthMiddleware.php`.
4. Remover el `Schedule::command('financiero:generar-facturas-recurrentes')` de `routes/console.php`.
5. Borrar archivos bajo `app/Application/UseCases/Administrativo/`, `app/Domain/Entities/{Factura,PagoCliente,...}.php`, `app/Http/Controllers/ERP/`, `app/Models/{Factura,FacturaDetalle,...}.php`.
6. La limpieza de `Modules/Administrativo` shadow models (tarea A1) es independiente y puede revertirse sin afectar el ledger.

**Criticidad**: baja. Ningún cambio toca las tablas core CRM (`entidad`, `oportunidad`, `contactos`, `servicios`). El rollback preserva todos los datos operacionales.

## Dependencies

- Laravel Scheduler ya configurado vía cron del sistema (`schedule:run` cada minuto).
- Driver `database` para cache (existente).
- Sanctum tokens emitidos por `crm:generate-token` (existente).
- Front ERP (`D:\sitios desarrollo\dashboard-crm`) — out of scope backend; contrato API via `Docs/openapi/erp-financiero.yaml` (E2).
