# Spec: erp-cartera-report

**Capability**: erp-cartera-report
**Change**: AFIN-001-modulo-administrativo-financiero
**Status**: Draft
**Date**: 2026-08-19

## Purpose

Expone el **reporte de Cartera (CxC)** que lista todas las facturas pendientes de pago (`estado='Emitida'`, `saldo > 0`) para una `entidad_id` específica, clasificadas en **buckets de antigüedad** según los días transcurridos desde `fecha_emision`: `0-30`, `31-60`, `61-90`, `90+`. Cada factura devuelta incluye la `cuenta` destino esperada y la suma por bucket para decisión de tesorería (PR02). El endpoint aplica cache de 5 minutos para proteger AC03 (p95 < 2s con 10k facturas).

## Requirements

### REQ-CAR-001: GET /api/v1/erp/cartera with aging buckets

The system SHALL return a JSON envelope containing per-bucket totals and the list of facturas for the requested `entidad_id`. Buckets are calculated as `days_diff = today - fecha_emision`: `0-30` (0–30 días inclusive), `31-60` (31–60), `61-90` (61–90), `90+` (>90).

#### Scenarios

**Scenario: cartera returns buckets with facturas for an entidad**
  Given `Entidad` id 42 with 3 facturas `estado='Emitida'`, `saldo > 0`:
    - factura 50: `fecha_emision=today - 10 days`, `saldo=500000`
    - factura 51: `fecha_emision=today - 45 days`, `saldo=1200000`
    - factura 52: `fecha_emision=today - 120 days`, `saldo=800000`
  When the client sends `GET /api/v1/erp/cartera?entidad_id=42`
  Then the response SHALL be `200` with `{ success: true, data: { buckets: { "0-30": { total: 500000, count: 1, facturas: [factura 50] }, "31-60": { total: 1200000, count: 1, facturas: [factura 51] }, "61-90": { total: 0, count: 0, facturas: [] }, "90+": { total: 800000, count: 1, facturas: [factura 52] } }, total_cartera: 2500000 } }`

**Scenario: missing entidad_id returns 422**
  Given auth context
  When the client sends `GET /api/v1/erp/cartera`
  Then the response SHALL be `422` with `{ success: false, error: "entidad_id is required" }`

**Scenario: unknown entidad_id returns 404**
  Given no `Entidad` with id 99999
  When the client sends `GET /api/v1/erp/cartera?entidad_id=99999`
  Then the response SHALL be `404` with `{ success: false, error: "Entidad not found" }`

**Scenario: excluded facturas do not appear in buckets**
  Given `Entidad` id 42 with one factura `estado='Emitida'` (saldo=500000) and one `estado='Pagada'` (saldo=0)
  When the client sends `GET /api/v1/erp/cartera?entidad_id=42`
  Then the response SHALL contain exactly 1 factura across all buckets
  And the Pagada factura SHALL NOT appear

**Scenario: total_cartera is sum of bucket totals**
  Given `Entidad` id 42 with the 3 facturas from the happy path scenario
  When the client sends `GET /api/v1/erp/cartera?entidad_id=42`
  Then `data.total_cartera` SHALL equal `500000 + 1200000 + 800000 = 2500000`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_returns_aging_buckets`
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_rejects_missing_entidad_id`
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_returns_404_on_unknown_entidad`
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_excludes_pagada_facturas`
- [ ] Endpoint: `GET /api/v1/erp/cartera?entidad_id=X`

### REQ-CAR-002: Each factura entry includes cuenta destino info

The system SHALL attach the `cuenta` (with `tipo_cuenta='cliente'`) linked via the latest `pagos_cliente.cuenta_id` registered for that `entidad`, falling back to `null` when no client cuenta exists.

#### Scenarios

**Scenario: factura includes client cuenta when one is registered**
  Given `Entidad` id 42 has a `cuenta` with id 9, `tipo_cuenta='cliente'`, `banco='Bancolombia'`
  And a `factura` id 50 with `saldo=500000`, `estado='Emitida'`
  When the client sends `GET /api/v1/erp/cartera?entidad_id=42`
  Then the entry for factura 50 SHALL include `cuenta: { id: 9, banco: "Bancolombia", numero_cuenta: "...", tipo: "Ahorros" }`

**Scenario: factura cuenta is null when entidad has no client cuenta**
  Given `Entidad` id 88 has zero `cuentas` with `tipo_cuenta='cliente'`
  And a `factura` id 60 with `saldo=200000`, `estado='Emitida'`
  When the client sends `GET /api/v1/erp/cartera?entidad_id=88`
  Then the entry for factura 60 SHALL include `cuenta: null`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_includes_client_cuenta`
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_cuenta_null_when_unregistered`

### REQ-CAR-003: Authorization and caching

The system SHALL enforce `erp.auth` middleware and apply a 5-minute cache keyed by `(entidad_id, today)` to keep p95 latency below 2s with 10k facturas (AC03).

#### Scenarios

**Scenario: user with rol_id=4 passes**
  Given a `Usuario` with `rol_id=4` authenticated via Sanctum
  When the client sends `GET /api/v1/erp/cartera?entidad_id=42`
  Then the response SHALL be `200`

**Scenario: user with rol_id=3 (CRM) gets 403**
  Given a `Usuario` with `rol_id=3` authenticated via Sanctum
  When the client sends `GET /api/v1/erp/cartera?entidad_id=42`
  Then the response SHALL be `403`

**Scenario: second request within TTL hits cache**
  Given `Entidad` id 42 with 1 factura pendiente
  And the first `GET /api/v1/erp/cartera?entidad_id=42` returned bucket totals X
  When the client sends a second `GET /api/v1/erp/cartera?entidad_id=42` within 5 minutes
  Then both responses SHALL return identical `data.buckets`
  And the second response SHALL NOT execute additional SQL beyond the cache hit

**Scenario: cache invalidates when a new pago is applied**
  Given `Entidad` id 42 with a cached cartera response from 1 minute ago
  When a `pagos_cliente` application reduces `factura.saldo` to zero
  Then the next `GET /api/v1/erp/cartera?entidad_id=42` SHALL recompute and the previously-paid factura SHALL NOT appear

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_allows_rol_4`
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_rejects_rol_3_with_403`
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_second_request_hits_cache`
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_cache_invalidates_on_payment`
- [ ] Permission: `erp.auth` (rol_id IN 4,5)

### REQ-CAR-004: Pagination for large resultsets (AC03)

The system SHALL paginate the underlying query with `per_page` default 25, max 100, returning `current_page`, `total`, `last_page` in the envelope when the resultset exceeds `per_page`.

#### Scenarios

**Scenario: paginate when more than per_page facturas exist**
  Given `Entidad` id 99 with 60 facturas `estado='Emitida'`
  When the client sends `GET /api/v1/erp/cartera?entidad_id=99&per_page=25`
  Then the response SHALL return `data.buckets` containing 25 facturas
  And `meta.current_page=1`, `meta.total=60`, `meta.last_page=3`

**Scenario: per_page above max is clamped**
  Given auth context
  When the client sends `GET /api/v1/erp/cartera?entidad_id=42&per_page=500`
  Then the response SHALL treat `per_page` as 100 (no error)

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_paginates_results`
- [ ] Test: `tests/Feature/ERP/ErpCarteraReportTest.php::test_clamps_per_page_max`
- [ ] Pagination enforced even on cached responses

## Out of scope (this spec)
- Multi-entidad aggregation (one cartera per entidad at a time)
- Forecasting / projection of future payments (HU11 — separate spec)
- CSV / PDF export
- Aging reclassification when fecha_emision changes after editing (out of scope for sprint 1)

## Dependencies
- `erp.auth` middleware
- `erp-invoicing` capability (needs `facturas.estado` and `facturas.saldo`)
- `erp-cxc-payments` capability (consumes `factura_pagos` to know which facturas are partially paid)
- Existing tables: `cuentas`, `entidad`, `usuarios`