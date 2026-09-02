# Spec: erp-cxc-payments

**Capability**: erp-cxc-payments
**Change**: AFIN-001-modulo-administrativo-financiero
**Status**: Draft
**Date**: 2026-08-19

## Purpose

Habilita el registro y consulta de **pagos recibidos de clientes (CxC)** en una tabla nueva `pagos_cliente`, simétrica a `movimientos` pero dedicada al lado del ingreso. Permite además **aplicar** un pago a una o varias facturas a través de la tabla pivote `factura_pagos`, decrementando `facturas.saldo` y promoviendo la factura a `Pagada` cuando el saldo llega a cero. La aplicación es **transaccional**: o se commitea el pago + aplicaciones + actualización de saldos, o se hace rollback total.

## Requirements

### REQ-CXC-001: Register a pago_cliente

The system SHALL create rows in `pagos_cliente` with `entidad_id`, `fecha`, `valor > 0`, and optional `cuenta_id`, `servicio_id`, `referencia`, `observaciones`. Every row MUST record `created_by` from the authenticated user.

#### Scenarios

**Scenario: create pago_cliente happy path**
  Given an existing `Entidad` id 42 with `estado="Cliente"`
  When the client sends `POST /api/v1/erp/pagos-cliente` with `{ entidad_id: 42, fecha: "2026-05-10", valor: 1500000, cuenta_id: 7, referencia: "TRF-001", servicio_id: 88 }`
  Then the response SHALL be `201` with `{ success: true, data: { id, entidad_id: 42, valor: 1500000, saldo_pendiente: 1500000, created_by: <auth_user_id> } }`
  And `pagos_cliente` table SHALL contain exactly one row with `valor=1500000`

**Scenario: reject when valor is zero or negative**
  Given auth context
  When the client sends `POST /api/v1/erp/pagos-cliente` with `{ entidad_id: 42, fecha: "2026-05-10", valor: 0 }`
  Then the response SHALL be `422` with `{ success: false, error: "valor must be greater than 0" }`

**Scenario: reject when entidad_id is missing**
  Given auth context
  When the client sends `POST /api/v1/erp/pagos-cliente` with `{ fecha: "2026-05-10", valor: 1000 }`
  Then the response SHALL be `422` with `{ success: false, error: "entidad_id is required" }`

**Scenario: reject when entidad does not exist**
  Given no `Entidad` with id 99999
  When the client sends `POST /api/v1/erp/pagos-cliente` with `{ entidad_id: 99999, fecha: "2026-05-10", valor: 1000 }`
  Then the response SHALL be `422` with `{ success: false, error: "entidad_id 99999 does not exist" }`

**Scenario: reject when referenced cuenta does not exist**
  Given no `cuenta` with id 9999
  When the client sends `POST /api/v1/erp/pagos-cliente` with `{ entidad_id: 42, fecha: "2026-05-10", valor: 1000, cuenta_id: 9999 }`
  Then the response SHALL be `422` with `{ success: false, error: "cuenta_id 9999 does not exist" }`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_creates_pago_cliente`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_rejects_zero_or_negative_valor`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_rejects_missing_entidad_id`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_rejects_unknown_entidad`
- [ ] Endpoint: `POST /api/v1/erp/pagos-cliente`
- [ ] Permission: `erp.auth` (rol_id IN 4,5)

### REQ-CXC-002: List pagos_cliente with filters

The system SHALL expose `GET /api/v1/erp/pagos-cliente` returning paginated rows, filterable by `?entidad_id`, `?fecha_desde`, `?fecha_hasta`, `?cuenta_id`.

#### Scenarios

**Scenario: list with default pagination**
  Given 25 `pagos_cliente` rows exist
  When the client sends `GET /api/v1/erp/pagos-cliente`
  Then the response SHALL be `200` with `data.data.length=15` (default per_page) and `data.total=25`

**Scenario: filter by entidad_id**
  Given `Entidad` id 42 has 3 pagos and `Entidad` id 43 has 5 pagos
  When the client sends `GET /api/v1/erp/pagos-cliente?entidad_id=42`
  Then the response SHALL contain exactly 3 items
  And every item SHALL have `entidad_id=42`

**Scenario: filter by date range**
  Given pagos on `2026-05-01`, `2026-05-15`, `2026-06-01`
  When the client sends `GET /api/v1/erp/pagos-cliente?fecha_desde=2026-05-01&fecha_hasta=2026-05-31`
  Then the response SHALL contain exactly 2 items (May rows only)

**Scenario: get single pago returns 200**
  Given a `pagos_cliente` with id 100
  When the client sends `GET /api/v1/erp/pagos-cliente/100`
  Then the response SHALL be `200` with the full pago payload

**Scenario: get unknown pago returns 404**
  Given no `pagos_cliente` with id 99999
  When the client sends `GET /api/v1/erp/pagos-cliente/99999`
  Then the response SHALL be `404` with `{ success: false, error: "Pago not found" }`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_lists_with_default_pagination`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_filters_by_entidad_id`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_filters_by_date_range`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_show_returns_404_on_missing`
- [ ] Endpoint: `GET /api/v1/erp/pagos-cliente[/{id}]`

### REQ-CXC-003: Apply payment to invoices (atomic, M2M)

The system SHALL expose `POST /api/v1/erp/pagos-cliente/{id}/aplicar` accepting a payload `{ aplicaciones: [{ factura_id, valor_aplicado }] }`. The use case MUST wrap the operation in `DB::transaction`: insert rows in `factura_pagos`, decrement `facturas.saldo` by `valor_aplicado`, and set `facturas.estado="Pagada"` when saldo reaches zero. The total `SUM(valor_aplicado)` MUST NOT exceed `pagos_cliente.valor` (idempotent: re-applying the same `valor_aplicado` to the same factura SHALL fail with 422, not duplicate).

#### Scenarios

**Scenario: apply payment to single invoice updates saldo**
  Given a `pagos_cliente` id 100 with `valor=1500000` and `saldo_pendiente=1500000`
  And a `factura` id 50 with `total=1500000`, `saldo=1500000`, `estado="Emitida"`
  When the client sends `POST /api/v1/erp/pagos-cliente/100/aplicar` with `{ aplicaciones: [{ factura_id: 50, valor_aplicado: 1500000 }] }`
  Then the response SHALL be `200` with `{ success: true, data: { pago_id: 100, aplicaciones: [{ factura_id: 50, valor_aplicado: 1500000 }], factura_estados: [{ id: 50, nuevo_saldo: 0, estado: "Pagada" }] } }`
  And `factura_pagos` SHALL contain one row with `pago_cliente_id=100`, `factura_id=50`, `valor_aplicado=1500000`
  And `facturas.id=50` SHALL have `saldo=0` and `estado="Pagada"`

**Scenario: apply payment split across multiple invoices**
  Given a `pagos_cliente` id 101 with `valor=1000000`
  And `factura` id 60 with `saldo=400000`, `factura` id 61 with `saldo=300000`, `factura` id 62 with `saldo=300000`
  When the client sends `POST /api/v1/erp/pagos-cliente/101/aplicar` with `{ aplicaciones: [{ factura_id: 60, valor_aplicado: 400000 }, { factura_id: 61, valor_aplicado: 300000 }, { factura_id: 62, valor_aplicado: 300000 }] }`
  Then the response SHALL be `200`
  And all three `factura_pagos` rows SHALL be inserted
  And all three facturas SHALL have `saldo=0` and `estado="Pagada"`

**Scenario: partial application leaves saldo and estado unchanged**
  Given `pagos_cliente` id 102 with `valor=1000000`
  And `factura` id 70 with `total=2000000`, `saldo=2000000`, `estado="Emitida"`
  When the client sends `POST /api/v1/erp/pagos-cliente/102/aplicar` with `{ aplicaciones: [{ factura_id: 70, valor_aplicado: 1000000 }] }`
  Then the response SHALL be `200`
  And `factura.id=70` SHALL have `saldo=1000000` and `estado="Emitida"`

**Scenario: reject when sum of applications exceeds pago valor**
  Given `pagos_cliente` id 103 with `valor=500000`
  When the client sends `POST /api/v1/erp/pagos-cliente/103/aplicar` with `{ aplicaciones: [{ factura_id: 80, valor_aplicado: 700000 }] }`
  Then the response SHALL be `422` with `{ success: false, error: "Sum of aplicaciones (700000) exceeds pago valor (500000)" }`

**Scenario: reject duplicate application to same factura**
  Given `pagos_cliente` id 104 with `valor=1500000`
  And `factura_pagos` already contains a row with `pago_cliente_id=104, factura_id=90, valor_aplicado=500000`
  When the client sends `POST /api/v1/erp/pagos-cliente/104/aplicar` with `{ aplicaciones: [{ factura_id: 90, valor_aplicado: 200000 }] }`
  Then the response SHALL be `422` with `{ success: false, error: "Pago already applied to factura 90" }`

**Scenario: atomic rollback when one application fails mid-batch**
  Given `pagos_cliente` id 105 with `valor=1000000`
  And `factura` id 91 with `saldo=400000` and `factura` id 99999 with `saldo=300000` (does not exist)
  When the client sends `POST /api/v1/erp/pagos-cliente/105/aplicar` with `{ aplicaciones: [{ factura_id: 91, valor_aplicado: 400000 }, { factura_id: 99999, valor_aplicado: 300000 }] }`
  Then the response SHALL be `422` with `{ success: false, error: "factura_id 99999 does not exist" }`
  And `factura_pagos` SHALL contain zero new rows for this request
  And `factura.id=91` SHALL retain its original `saldo=400000` (no partial update)

**Scenario: reject when factura belongs to a different entidad**
  Given `pagos_cliente` id 106 for `Entidad` id 42
  And `factura` id 92 for `Entidad` id 99
  When the client sends `POST /api/v1/erp/pagos-cliente/106/aplicar` with `{ aplicaciones: [{ factura_id: 92, valor_aplicado: 100000 }] }`
  Then the response SHALL be `422` with `{ success: false, error: "factura 92 does not belong to entidad 42" }`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_applies_to_single_invoice`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_applies_split_to_multiple_invoices`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_partial_application_keeps_estado_emitida`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_rejects_when_sum_exceeds_pago_valor`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_rejects_duplicate_application_to_factura`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_atomic_rollback_on_mid_batch_failure`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_rejects_cross_entidad_application`
- [ ] Endpoint: `POST /api/v1/erp/pagos-cliente/{id}/aplicar`

### REQ-CXC-004: Update and soft-delete pago_cliente

The system SHALL allow updating `referencia` and `observaciones` on a `pago_cliente` whose `saldo_pendiente == valor` (no applications yet). Once a pago has applications in `factura_pagos`, updates to `valor` and `entidad_id` SHALL be rejected. Deletes SHALL be soft (`deleted_at`).

#### Scenarios

**Scenario: update observaciones on pago sin aplicaciones succeeds**
  Given `pagos_cliente` id 200 with `valor=1000000` and zero rows in `factura_pagos`
  When the client sends `PUT /api/v1/erp/pagos-cliente/200` with `{ observaciones: "Confirmado por cliente" }`
  Then the response SHALL be `200` with `data.observaciones="Confirmado por cliente"`

**Scenario: reject update of valor after applications exist**
  Given `pagos_cliente` id 201 with one row in `factura_pagos` with `valor_aplicado=500000`
  When the client sends `PUT /api/v1/erp/pagos-cliente/201` with `{ valor: 2000000 }`
  Then the response SHALL be `422` with `{ success: false, error: "Cannot modify valor after applications exist" }`

**Scenario: soft-delete pago sin aplicaciones**
  Given `pagos_cliente` id 202 with zero rows in `factura_pagos`
  When the client sends `DELETE /api/v1/erp/pagos-cliente/202`
  Then the response SHALL be `200` with `{ success: true, data: { deleted: true, id: 202 } }`
  And the row SHALL have `deleted_at` set to current timestamp

**Scenario: reject delete when applications exist**
  Given `pagos_cliente` id 203 with at least one row in `factura_pagos`
  When the client sends `DELETE /api/v1/erp/pagos-cliente/203`
  Then the response SHALL be `422` with `{ success: false, error: "Cannot delete pago with applications" }`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_updates_observaciones_on_unapplied_pago`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_rejects_valor_change_after_applications`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_soft_deletes_unapplied_pago`
- [ ] Test: `tests/Feature/ERP/ErpCxcPaymentsTest.php::test_blocks_delete_with_applications`
- [ ] Endpoints: `PUT|DELETE /api/v1/erp/pagos-cliente/{id}`

## Out of scope (this spec)
- CxP movements (covered by `erp-cxp-report`)
- Pro-forma → invoice conversion (covered by `erp-invoicing`)
- Multi-currency, payment gateway integration
- Reconciliación bancaria

## Dependencies
- `erp.auth` middleware
- `erp-invoicing` capability (needs `facturas` table + `factura_pagos` M2M)
- Existing tables: `entidad`, `cuentas`, `servicios`, `usuarios`