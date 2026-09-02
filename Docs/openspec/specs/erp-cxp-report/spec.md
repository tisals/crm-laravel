# Spec: erp-cxp-report

**Capability**: erp-cxp-report
**Change**: AFIN-001-modulo-administrativo-financiero
**Status**: Draft
**Date**: 2026-08-19

## Purpose

Expone el **reporte de Pagos a Proveedores (CxP)** filtrado por `proveedor_id`. Devuelve los movimientos de egreso (`movimientos` con `valor_debito > 0`) registrados contra ese proveedor, con la `cuenta` origen usada para el pago, totalizadores por rango de fechas y paginación. Permite al equipo financiero ver "¿a quién le pagamos, cuánto y desde qué cuenta?" para soportar decisiones de tesorería (PR02).

## Requirements

### REQ-CXP-001: GET /api/v1/erp/pagos filtered by proveedor

The system SHALL return paginated `movimientos` where `proveedor_id=X` AND `valor_debito > 0`, optionally filtered by `?fecha_desde`, `?fecha_hasta`, `?cuenta_id`. Each item SHALL include the `cuenta` (if any) from which the payment was sourced.

#### Scenarios

**Scenario: list movements for a proveedor**
  Given `Proveedor` id 7 with 3 `movimientos` having `valor_debito > 0` (500000, 800000, 200000) and 1 with `valor_credito > 0` (100000)
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7`
  Then the response SHALL be `200` with `data.total=3`
  And every item SHALL have `proveedor_id=7` and `valor_debito > 0`
  And the `valor_credito` movement SHALL NOT appear

**Scenario: reject when proveedor_id is missing**
  Given auth context
  When the client sends `GET /api/v1/erp/pagos`
  Then the response SHALL be `422` with `{ success: false, error: "proveedor_id is required" }`

**Scenario: unknown proveedor_id returns 404**
  Given no `Proveedor` with id 99999
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=99999`
  Then the response SHALL be `404` with `{ success: false, error: "Proveedor not found" }`

**Scenario: filter by date range narrows result**
  Given `Proveedor` id 7 with movimientos on `2026-01-15`, `2026-03-20`, `2026-05-10`
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7&fecha_desde=2026-03-01&fecha_hasta=2026-04-30`
  Then the response SHALL contain exactly 1 item (the March movement)

**Scenario: filter by cuenta_id narrows result**
  Given `Proveedor` id 7 with 2 pagos sourced from `cuenta` id 9 and 1 from `cuenta` id 10
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7&cuenta_id=9`
  Then the response SHALL contain exactly 2 items

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_lists_pagos_for_proveedor`
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_rejects_missing_proveedor_id`
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_returns_404_on_unknown_proveedor`
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_filters_by_date_range`
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_filters_by_cuenta_id`
- [ ] Endpoint: `GET /api/v1/erp/pagos?proveedor_id=X`

### REQ-CXP-002: Each movement includes cuenta origen info

The system SHALL attach the `cuenta` linked to the `movimiento` via a join or eager-load on `cuentas.id`, exposing `banco`, `numero_cuenta`, `tipo`.

#### Scenarios

**Scenario: movimiento includes cuenta origen details**
  Given `movimiento` id 100 with `proveedor_id=7`, `valor_debito=500000`, `cuenta_id=9`
  And `cuenta` id 9 has `banco='Bancolombia'`, `numero_cuenta='123-456789-00'`, `tipo='Ahorros'`, `tipo_cuenta='proveedor'`
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7`
  Then the item for movimiento 100 SHALL include `cuenta: { id: 9, banco: "Bancolombia", numero_cuenta: "123-456789-00", tipo: "Ahorros" }`

**Scenario: movimiento with null cuenta_id has cuenta null**
  Given `movimiento` id 101 with `proveedor_id=7`, `valor_debito=200000`, `cuenta_id=null`
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7`
  Then the item for movimiento 101 SHALL include `cuenta: null`

**Scenario: cross-tipo_cuenta cuenta is still included (legacy proveedores only)**
  Given `movimiento` id 102 with `proveedor_id=7` linked to `cuenta` id 11 having `tipo_cuenta='cliente'`
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7`
  Then the item for movimiento 102 SHALL include `cuenta: { id: 11, ... }` (no filtering by tipo_cuenta)

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_includes_cuenta_origen`
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_cuenta_null_when_unset`
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_legacy_cuenta_included_regardless_of_tipo`

### REQ-CXP-003: Totals and pagination

The system SHALL return `data.total_valor_debito` (sum of `valor_debito` for the filtered set) and `meta.total`, `meta.current_page`, `meta.last_page` when paginating.

#### Scenarios

**Scenario: total_valor_debito reflects sum of filtered movements**
  Given `Proveedor` id 7 with movimientos `valor_debito` = 500000, 800000, 200000
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7`
  Then `data.total_valor_debito` SHALL equal `1500000`

**Scenario: paginate when resultset exceeds per_page**
  Given `Proveedor` id 7 with 40 `valor_debito` movements
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7&per_page=15`
  Then the response SHALL return 15 items with `meta.current_page=1`, `meta.total=40`, `meta.last_page=3`

**Scenario: per_page above max is clamped to 100**
  Given auth context
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7&per_page=500`
  Then the request SHALL be processed with `per_page=100` (no error)

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_total_valor_debito`
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_paginates_results`
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_clamps_per_page_max`

### REQ-CXP-004: Authorization

The system SHALL enforce `erp.auth` middleware on `/api/v1/erp/pagos`.

#### Scenarios

**Scenario: user with rol_id=4 passes**
  Given a `Usuario` with `rol_id=4` authenticated via Sanctum
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7`
  Then the response SHALL be `200`

**Scenario: user with rol_id=3 (CRM) gets 403**
  Given a `Usuario` with `rol_id=3` authenticated via Sanctum
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7`
  Then the response SHALL be `403`

**Scenario: unauthenticated request gets 401**
  Given no `Authorization: Bearer` header
  When the client sends `GET /api/v1/erp/pagos?proveedor_id=7`
  Then the response SHALL be `401`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_allows_rol_4`
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_rejects_rol_3_with_403`
- [ ] Test: `tests/Feature/ERP/ErpCxpReportTest.php::test_rejects_unauthenticated_with_401`
- [ ] Permission: `erp.auth` (rol_id IN 4,5)

## Out of scope (this spec)
- Aging buckets (CxP does not require aging for sprint 1)
- Aggregations across multiple proveedores (one proveedor at a time)
- Pagos recurrentes scheduled forecast (covered by `erp-recurring-scheduler`)
- CSV / PDF export
- Reconciliation against bank statements

## Dependencies
- `erp.auth` middleware
- `erp-banking-accounts` capability (consumes `cuentas` for join)
- Existing tables: `movimientos` (unchanged), `proveedores`, `cuentas`, `usuarios`