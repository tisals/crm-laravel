# Spec: erp-invoicing

**Capability**: erp-invoicing
**Change**: AFIN-001-modulo-administrativo-financiero
**Status**: Draft
**Date**: 2026-08-19

## Purpose

Permite emitir **pro-formas** y convertirlas en **facturas** formales dentro de una sola tabla `facturas` discriminada por la columna `tipo ENUM('proforma','factura')`. El módulo soporta **líneas de detalle** en `factura_detalles` y la transición atómica **pro-forma → factura** que asigna `numero` definitivo. Toda escritura multi-tabla se ejecuta dentro de `DB::transaction` para garantizar AC01/AC07 (atomicidad e integridad transaccional).

## Requirements

### REQ-INV-001: Create pro-forma with detalle line items (atomic)

The system SHALL create a `factura` of `tipo='proforma'` together with one or more `factura_detalles` rows inside a single `DB::transaction`. Subtotal, IVA and total SHALL be derived server-side from `factura_detalles` (IVA stored, not calculated — R01). The `numero` field is generated as `PROFORMA-{YYYY}-{NNN}` running counter.

#### Scenarios

**Scenario: create pro-forma with two detail lines succeeds**
  Given an existing `Entidad` id 42 and `Servicio` id 88
  When the client sends `POST /api/v1/erp/facturas` with `{ tipo: "proforma", entidad_id: 42, servicio_id: 88, fecha_emision: "2026-05-10", detalles: [{ descripcion: "Licencia anual", cantidad: 1, precio_unitario: 1000000, iva_porcentaje: 19 }, { descripcion: "Soporte premium", cantidad: 12, precio_unitario: 50000, iva_porcentaje: 19 }] }`
  Then the response SHALL be `201` with `{ success: true, data: { id, tipo: "proforma", numero: "PROFORMA-2026-001", subtotal: 1600000, iva: 304000, total: 1904000, saldo: 1904000, estado: "Borrador", detalles: [2 items] } }`
  And `facturas` SHALL contain exactly one row with `tipo='proforma'`
  And `factura_detalles` SHALL contain exactly two rows linked to the new factura

**Scenario: reject empty detalles array**
  Given auth context
  When the client sends `POST /api/v1/erp/facturas` with `{ tipo: "proforma", entidad_id: 42, detalles: [] }`
  Then the response SHALL be `422` with `{ success: false, error: "At least one detalle is required" }`

**Scenario: reject detalle with precio_unitario <= 0**
  Given auth context
  When the client sends `POST /api/v1/erp/facturas` with `{ tipo: "proforma", entidad_id: 42, detalles: [{ descripcion: "X", cantidad: 1, precio_unitario: 0, iva_porcentaje: 0 }] }`
  Then the response SHALL be `422` with `{ success: false, error: "detalles[0].precio_unitario must be greater than 0" }`

**Scenario: atomic rollback when second detalle has invalid FK**
  Given `Entidad` id 42 exists
  When the client sends `POST /api/v1/erp/facturas` with `{ tipo: "proforma", entidad_id: 42, detalles: [{ descripcion: "OK", cantidad: 1, precio_unitario: 1000, iva_porcentaje: 0 }, { descripcion: "BAD", cantidad: 1, precio_unitario: 2000, iva_porcentaje: 0, producto_id: 99999 }] }`
  Then the response SHALL be `422` with `{ success: false, error: "detalles[1].producto_id 99999 does not exist" }`
  And `facturas` SHALL contain zero new rows for this request
  And `factura_detalles` SHALL contain zero new rows for this request

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_creates_proforma_with_details`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_rejects_empty_detalles`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_rejects_zero_precio_unitario`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_atomic_rollback_on_invalid_detalle_fk`
- [ ] Endpoint: `POST /api/v1/erp/facturas`
- [ ] Permission: `erp.auth` (rol_id IN 4,5)

### REQ-INV-002: Convert pro-forma to invoice (atomic)

The system SHALL expose `POST /api/v1/erp/facturas/{id}/convertir` that atomically transitions a `factura` from `tipo='proforma'` to `tipo='factura'`, assigns a new `numero='F-{YYYY}-{NNNNNN}'` running counter, and sets `estado='Emitida'`. The conversion is **one-way**: a `tipo='factura'` cannot be converted again (422). The use case MUST execute inside `DB::transaction`.

#### Scenarios

**Scenario: convert pro-forma to factura happy path**
  Given a `factura` id 100 with `tipo='proforma'`, `estado='Borrador'`, `numero='PROFORMA-2026-001'`
  When the client sends `POST /api/v1/erp/facturas/100/convertir` with `{ fecha_emision: "2026-05-15", fecha_vencimiento: "2026-06-15" }`
  Then the response SHALL be `200` with `{ success: true, data: { id: 100, tipo: "factura", numero: "F-2026-000001", estado: "Emitida", fecha_emision: "2026-05-15" } }`
  And persisted `facturas.id=100` SHALL have `tipo='factura'`, `numero='F-2026-000001'`, `estado='Emitida'`

**Scenario: reject conversion of already-converted factura**
  Given a `factura` id 101 with `tipo='factura'`
  When the client sends `POST /api/v1/erp/facturas/101/convertir`
  Then the response SHALL be `422` with `{ success: false, error: "Only proformas can be converted" }`

**Scenario: reject conversion of pro-forma with detalles missing**
  Given a `factura` id 102 with `tipo='proforma'` and zero `factura_detalles`
  When the client sends `POST /api/v1/erp/facturas/102/convertir`
  Then the response SHALL be `422` with `{ success: false, error: "Proforma must have at least one detalle before conversion" }`

**Scenario: convert assigns unique numero**
  Given two pro-formas id 110 and id 111, both `tipo='proforma'`
  When client converts 110 then 111 in sequence
  Then factura 110 SHALL have `numero='F-2026-000001'`
  And factura 111 SHALL have `numero='F-2026-000002'` (NOT a collision)
  And the assignment SHALL use `lockForUpdate()` to serialize concurrent calls

**Scenario: convert on missing factura returns 404**
  Given no `factura` with id 99999
  When the client sends `POST /api/v1/erp/facturas/99999/convertir`
  Then the response SHALL be `404` with `{ success: false, error: "Factura not found" }`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_converts_proforma_to_factura`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_rejects_double_conversion`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_rejects_conversion_without_detalles`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_assigns_unique_numero_sequentially`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_converts_returns_404_on_missing`
- [ ] Endpoint: `POST /api/v1/erp/facturas/{id}/convertir`

### REQ-INV-003: List and filter facturas

The system SHALL expose `GET /api/v1/erp/facturas` returning paginated rows filterable by `?tipo=proforma|factura`, `?entidad_id`, `?estado`, `?fecha_desde`, `?fecha_hasta`, and include the `detalles` count.

#### Scenarios

**Scenario: list all facturas paginated**
  Given 5 pro-formas and 3 facturas exist
  When the client sends `GET /api/v1/erp/facturas?per_page=20`
  Then the response SHALL be `200` with `data.total=8`
  And each item SHALL include `detalles_count`

**Scenario: filter by tipo=factura**
  Given 5 pro-formas and 3 facturas exist
  When the client sends `GET /api/v1/erp/facturas?tipo=factura`
  Then the response SHALL contain exactly 3 items
  And every item SHALL have `tipo='factura'`

**Scenario: filter by estado=Pagada**
  Given 2 facturas with `estado='Emitida'` and 1 with `estado='Pagada'`
  When the client sends `GET /api/v1/erp/facturas?estado=Pagada`
  Then the response SHALL contain exactly 1 item with `estado='Pagada'`

**Scenario: get single factura includes detalles array**
  Given a `factura` id 200 with 3 detalles
  When the client sends `GET /api/v1/erp/facturas/200`
  Then the response SHALL be `200` with `data.detalles.length=3`

**Scenario: get unknown factura returns 404**
  Given no `factura` with id 99999
  When the client sends `GET /api/v1/erp/facturas/99999`
  Then the response SHALL be `404`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_lists_paginated_with_detalles_count`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_filters_by_tipo_factura`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_filters_by_estado`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_show_includes_detalles`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_show_returns_404`
- [ ] Endpoint: `GET /api/v1/erp/facturas[/{id}]`

### REQ-INV-004: Update pro-forma (only while tipo=proforma and estado in [Borrador])

The system SHALL allow updating `fecha_emision`, `fecha_vencimiento`, `observaciones`, and adding/removing `factura_detalles` only when `tipo='proforma'` AND `estado IN ('Borrador')`. Once a pro-forma is converted (`tipo='factura'`), the `detalles` array is immutable; only `observaciones` and `fecha_vencimiento` may be updated. Updates to an `Anulada` factura SHALL be rejected.

#### Scenarios

**Scenario: update pro-forma observaciones succeeds**
  Given `factura` id 300 with `tipo='proforma'`, `estado='Borrador'`
  When the client sends `PUT /api/v1/erp/facturas/300` with `{ observaciones: "Pendiente de aprobación" }`
  Then the response SHALL be `200` with `data.observaciones='Pendiente de aprobación'`

**Scenario: update converted factura detalles is rejected**
  Given `factura` id 301 with `tipo='factura'`, `estado='Emitida'`
  When the client sends `PUT /api/v1/erp/facturas/301` with `{ detalles: [{ descripcion: "New line", cantidad: 1, precio_unitario: 100, iva_porcentaje: 0 }] }`
  Then the response SHALL be `422` with `{ success: false, error: "Cannot modify detalles of an issued factura" }`

**Scenario: update anulada factura is rejected**
  Given `factura` id 302 with `estado='Anulada'`
  When the client sends `PUT /api/v1/erp/facturas/302` with `{ observaciones: "Try" }`
  Then the response SHALL be `422` with `{ success: false, error: "Cannot modify an Anulada factura" }`

**Scenario: soft-delete only allowed in Borrador**
  Given `factura` id 303 with `tipo='proforma'`, `estado='Borrador'`
  When the client sends `DELETE /api/v1/erp/facturas/303`
  Then the response SHALL be `200` with `data.deleted=true`
  And `facturas.id=303` SHALL have `deleted_at` set

**Scenario: reject delete of Emitida factura**
  Given `factura` id 304 with `estado='Emitida'`
  When the client sends `DELETE /api/v1/erp/facturas/304`
  Then the response SHALL be `422` with `{ success: false, error: "Only Borrador facturas can be deleted" }`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_updates_proforma_observaciones`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_rejects_detalle_update_on_factura`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_rejects_update_on_anulada`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_soft_deletes_borrador`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_blocks_delete_on_emitida`
- [ ] Endpoints: `PUT|DELETE /api/v1/erp/facturas/{id}`

### REQ-INV-005: Authorization for all invoice endpoints

The system SHALL reject all requests to `/api/v1/erp/facturas*` whose authenticated user has `rol_id` outside the allowed ERP set.

#### Scenarios

**Scenario: user with rol_id=4 passes**
  Given a `Usuario` with `rol_id=4` authenticated via Sanctum
  When the client sends `GET /api/v1/erp/facturas`
  Then the response SHALL be `200`

**Scenario: user with rol_id=3 (CRM) gets 403**
  Given a `Usuario` with `rol_id=3` authenticated via Sanctum
  When the client sends `GET /api/v1/erp/facturas`
  Then the response SHALL be `403` with `{ success: false, error: "ERP access requires rol_id IN [4,5]" }`

**Scenario: unauthenticated request gets 401**
  Given no `Authorization: Bearer` header
  When the client sends `GET /api/v1/erp/facturas`
  Then the response SHALL be `401`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_allows_rol_4`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_rejects_rol_3_with_403`
- [ ] Test: `tests/Feature/ERP/ErpInvoicingTest.php::test_rejects_unauthenticated_with_401`

## Out of scope (this spec)
- Pago application (covered by `erp-cxc-payments`)
- Tax calculation (IVA is stored, not computed — R01)
- Multi-currency
- Electronic invoicing / DIAN integration

## Dependencies
- `erp.auth` middleware
- Existing tables: `entidad`, `servicios`, `productos`, `detalle_servicios`, `usuarios`
- New tables: `facturas`, `factura_detalles`, `factura_pagos` (pivot)