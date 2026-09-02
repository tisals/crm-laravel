# Spec: erp-recurring-scheduler

**Capability**: erp-recurring-scheduler
**Change**: AFIN-001-modulo-administrativo-financiero
**Status**: Draft
**Date**: 2026-08-19

## Purpose

Habilita la **facturación recurrente a clientes** (mensual / trimestral / semestral / anual) y los **pagos recurrentes a proveedores** con la misma granularidad de frecuencia. El comando `financiero:generar-facturas-recurrentes` corre diario a las 02:00 America/Bogota y materializa las facturas / movimientos cuyo `proxima_emision <= today`, avanzando la fecha de manera **atómica e idempotente** (AC06) para que un re-run del scheduler no duplique facturas.

## Requirements

### REQ-REC-001: CRUD facturacion_recurrente

The system SHALL expose `/api/v1/erp/facturacion-recurrente` with full CRUD on the `facturacion_recurrente` table. Required fields: `entidad_id`, `frecuencia ENUM('mensual','trimestral','semestral','anual')`, `proxima_emision`, `vr_base > 0`. Optional: `servicio_id`, `vigencia_hasta`, `estado` (defaults to `Activa`).

#### Scenarios

**Scenario: create recurrente happy path**
  Given `Entidad` id 42 with `estado='Cliente'`
  When the client sends `POST /api/v1/erp/facturacion-recurrente` with `{ entidad_id: 42, frecuencia: "mensual", proxima_emision: "2026-06-01", vr_base: 1500000, vigencia_hasta: "2027-06-01" }`
  Then the response SHALL be `201` with `{ success: true, data: { id, estado: "Activa", proxima_emision: "2026-06-01" } }`

**Scenario: reject invalid frecuencia value**
  Given auth context
  When the client sends `POST /api/v1/erp/facturacion-recurrente` with `{ entidad_id: 42, frecuencia: "diaria", proxima_emision: "2026-06-01", vr_base: 1000 }`
  Then the response SHALL be `422` with `{ success: false, error: "frecuencia must be one of: mensual, trimestral, semestral, anual" }`

**Scenario: reject vr_base <= 0**
  Given auth context
  When the client sends `POST /api/v1/erp/facturacion-recurrente` with `{ entidad_id: 42, frecuencia: "mensual", proxima_emision: "2026-06-01", vr_base: 0 }`
  Then the response SHALL be `422` with `{ success: false, error: "vr_base must be greater than 0" }`

**Scenario: list and filter recurrentes by estado**
  Given 3 recurrentes with `estado='Activa'` and 2 with `estado='Pausada'`
  When the client sends `GET /api/v1/erp/facturacion-recurrente?estado=Activa`
  Then the response SHALL contain exactly 3 items

**Scenario: update recurrente preserves proxima_emision when not in payload**
  Given `facturacion_recurrente` id 50 with `proxima_emision='2026-06-01'`
  When the client sends `PUT /api/v1/erp/facturacion-recurrente/50` with `{ vr_base: 2000000 }`
  Then `proxima_emision` SHALL remain `'2026-06-01'`

**Scenario: soft-delete recurrente**
  Given `facturacion_recurrente` id 60 with `estado='Activa'`
  When the client sends `DELETE /api/v1/erp/facturacion-recurrente/60`
  Then the response SHALL be `200` with `data.deleted=true`
  And the row SHALL have `deleted_at` set

**Scenario: 404 on missing recurrente**
  Given no `facturacion_recurrente` with id 99999
  When the client sends `GET /api/v1/erp/facturacion-recurrente/99999`
  Then the response SHALL be `404`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_creates_recurrente`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_rejects_invalid_frecuencia`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_rejects_zero_vr_base`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_filters_by_estado`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_updates_vr_base_preserves_proxima_emision`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_soft_deletes_recurrente`
- [ ] Endpoint: `GET|POST|PUT|DELETE /api/v1/erp/facturacion-recurrente[/{id}]`

### REQ-REC-002: CRUD pago_recurrente_proveedor

The system SHALL expose `/api/v1/erp/pagos-recurrentes` with full CRUD on `pago_recurrente_proveedor`. Required: `proveedor_id`, `concepto`, `frecuencia`, `proximo_pago`, `vr_base > 0`. Optional: `servicio_id`, `vigencia_hasta`.

#### Scenarios

**Scenario: create pago recurrente happy path**
  Given `Proveedor` id 7
  When the client sends `POST /api/v1/erp/pagos-recurrentes` with `{ proveedor_id: 7, concepto: "Hosting mensual", frecuencia: "mensual", proximo_pago: "2026-06-01", vr_base: 250000 }`
  Then the response SHALL be `201` with `{ success: true, data: { id, estado: "Activo" } }`

**Scenario: reject missing concepto**
  Given auth context
  When the client sends `POST /api/v1/erp/pagos-recurrentes` with `{ proveedor_id: 7, frecuencia: "mensual", proximo_pago: "2026-06-01", vr_base: 250000 }`
  Then the response SHALL be `422` with `{ success: false, error: "concepto is required" }`

**Scenario: list pago recurrentes**
  Given 4 pago recurrentes exist
  When the client sends `GET /api/v1/erp/pagos-recurrentes`
  Then the response SHALL be `200` with `data.total=4`

**Scenario: soft-delete pago recurrente**
  Given `pago_recurrente_proveedor` id 30 with `estado='Activo'`
  When the client sends `DELETE /api/v1/erp/pagos-recurrentes/30`
  Then the response SHALL be `200` with `data.deleted=true`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_creates_pago_recurrente`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_rejects_missing_concepto`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_lists_pagos_recurrentes`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_soft_deletes_pago_recurrente`
- [ ] Endpoint: `GET|POST|PUT|DELETE /api/v1/erp/pagos-recurrentes[/{id}]`

### REQ-REC-003: Pause and resume recurrence

The system SHALL expose `POST /api/v1/erp/facturacion-recurrente/{id}/pausar` and `/reanudar` that set `estado='Pausada'` or `estado='Activa'` respectively. Paused recurrences SHALL be skipped by the scheduler. The same verbs SHALL apply to `/api/v1/erp/pagos-recurrentes/{id}`.

#### Scenarios

**Scenario: pause an activa recurrente**
  Given `facturacion_recurrente` id 50 with `estado='Activa'`
  When the client sends `POST /api/v1/erp/facturacion-recurrente/50/pausar`
  Then the response SHALL be `200` with `data.estado='Pausada'`

**Scenario: resume a pausada recurrente**
  Given `facturacion_recurrente` id 50 with `estado='Pausada'`
  When the client sends `POST /api/v1/erp/facturacion-recurrente/50/reanudar`
  Then the response SHALL be `200` with `data.estado='Activa'`

**Scenario: pause already-pausada is idempotent**
  Given `facturacion_recurrente` id 51 with `estado='Pausada'`
  When the client sends `POST /api/v1/erp/facturacion-recurrente/51/pausar`
  Then the response SHALL be `200` with `data.estado='Pausada'` (no error)

**Scenario: scheduler skips Pausada recurrente**
  Given `facturacion_recurrente` id 50 with `estado='Pausada'` and `proxima_emision=today`
  When `php artisan financiero:generar-facturas-recurrentes` runs
  Then no new `factura` SHALL be created for id 50

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_pauses_activa_recurrente`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_resumes_pausada_recurrente`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_pause_is_idempotent`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_scheduler_skips_pausada`
- [ ] Endpoints: `POST /api/v1/erp/facturacion-recurrente/{id}/{pausar,reanudar}` and equivalents for pagos-recurrentes

### REQ-REC-004: Scheduler generates facturas atomically and idempotently

The command `financiero:generar-facturas-recurrentes` SHALL query `facturacion_recurrente WHERE estado='Activa' AND proxima_emision <= today AND deleted_at IS NULL`, then for each row inside a single `DB::transaction`:
1. Insert a `facturas` row with `tipo='proforma'`, `estado='Borrador'`, `total = vr_base`, `saldo = vr_base`.
2. Update `facturacion_recurrente SET ultimo_periodo_facturado = today, proxima_emision = advance(proxima_emision, frecuencia)`.
3. If `proxima_emision > vigencia_hasta`, set `estado='Pausada'`.

Re-running the command within the same day SHALL NOT produce a second factura for the same recurrente (AC06 idempotency).

#### Scenarios

**Scenario: scheduler creates one factura for one due recurrente**
  Given `facturacion_recurrente` id 100 with `frecuencia='mensual'`, `proxima_emision='2026-05-01'`, `vr_base=1500000`, `estado='Activa'`, `vigencia_hasta='2027-01-01'`
  When `Carbon::setTestNow('2026-05-01')` and `php artisan financiero:generar-facturas-recurrentes` runs
  Then exactly 1 new `factura` SHALL be inserted with `entidad_id=42`, `tipo='proforma'`, `total=1500000`, `saldo=1500000`, `estado='Borrador'`
  And `facturacion_recurrente.id=100` SHALL have `ultimo_periodo_facturado='2026-05-01'` and `proxima_emision='2026-06-01'`

**Scenario: scheduler running twice same day produces one factura only**
  Given the previous scenario's recurrente with `proxima_emision='2026-06-01'` after first run
  When `php artisan financiero:generar-facturas-recurrentes` runs again on `2026-05-01`
  Then zero new `facturas` SHALL be inserted (idempotent re-run)
  And `proxima_emision` SHALL remain `'2026-06-01'`

**Scenario: scheduler advances proxima_emision according to frecuencia**
  Given `facturacion_recurrente` with `frecuencia='trimestral'`, `proxima_emision='2026-04-01'`, `vigencia_hasta='2027-01-01'`
  When `Carbon::setTestNow('2026-04-01')` and the command runs
  Then `proxima_emision` SHALL be updated to `'2026-07-01'`

**Scenario: scheduler pauses recurrente past vigencia_hasta**
  Given `facturacion_recurrente` id 200 with `frecuencia='mensual'`, `proxima_emision='2026-12-01'`, `vigencia_hasta='2026-12-31'`
  When `Carbon::setTestNow('2026-12-01')` and the command runs
  Then 1 new `factura` SHALL be created
  And `facturacion_recurrente.id=200` SHALL have `proxima_emision='2027-01-01'` AND `estado='Pausada'`

**Scenario: scheduler atomicity when factura insert fails mid-batch**
  Given 3 due recurrentes: id 100 (OK), id 101 (OK), id 102 (entidad_id pointing to a deleted Entidad that violates FK)
  When the command runs
  Then the 2 valid facturas SHALL be inserted
  And `facturacion_recurrente.ultimo_periodo_facturado` SHALL be advanced for id 100 and id 101 only
  And id 102 SHALL remain at its original `proxima_emision` (rollback semantics per row, not whole batch)

**Scenario: scheduler reports summary to console**
  Given 2 due recurrentes
  When the command runs
  Then stdout SHALL contain a line `Generated 2 facturas, skipped 0, errors 0`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_scheduler_creates_one_factura_for_due_recurrente`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_scheduler_idempotent_on_same_day`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_scheduler_advances_trimestral`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_scheduler_pauses_past_vigencia`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_scheduler_isolates_failure_per_row`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_scheduler_outputs_summary`
- [ ] Command: `php artisan financiero:generar-facturas-recurrentes`

### REQ-REC-005: Authorization

The system SHALL enforce `erp.auth` middleware on all `/api/v1/erp/facturacion-recurrente*` and `/api/v1/erp/pagos-recurrentes*` routes.

#### Scenarios

**Scenario: user with rol_id=4 passes**
  Given a `Usuario` with `rol_id=4` authenticated via Sanctum
  When the client sends `GET /api/v1/erp/facturacion-recurrente`
  Then the response SHALL be `200`

**Scenario: user with rol_id=3 (CRM) gets 403**
  Given a `Usuario` with `rol_id=3` authenticated via Sanctum
  When the client sends `POST /api/v1/erp/facturacion-recurrente`
  Then the response SHALL be `403`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_allows_rol_4`
- [ ] Test: `tests/Feature/ERP/ErpRecurringSchedulerTest.php::test_rejects_rol_3_with_403`

## Out of scope (this spec)
- Forecasting projection of recurrences (HU11 — separate spec)
- Email notifications when factura is generated
- Manual triggering of single recurrence from UI (covered by `POST` endpoint above)
- Multi-currency

## Dependencies
- `erp.auth` middleware
- `erp-invoicing` capability (creates `facturas` rows from scheduler)
- Existing tables: `entidad`, `servicios`, `proveedores`, `usuarios`
- Laravel Scheduler cron (`schedule:run` every minute) — must be configured on host