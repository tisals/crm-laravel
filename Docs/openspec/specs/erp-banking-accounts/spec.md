# Spec: erp-banking-accounts

**Capability**: erp-banking-accounts
**Change**: AFIN-001-modulo-administrativo-financiero
**Status**: Draft
**Date**: 2026-08-19

## Purpose

Permite dar de alta, listar, actualizar y eliminar cuentas bancarias tanto de **proveedores** (cuentas origen para pagos CxP) como de **clientes** (cuentas destino para reembolsos y pagos CxC) en una sola tabla `cuentas`. La columna `tipo_cuenta` discrimina el rol del titular y la capa de aplicación garantiza exclusividad: una cuenta pertenece exactamente a UN proveedor O a UN cliente, nunca a ambos ni a ninguno.

## Requirements

### REQ-BAN-001: Create cuenta with discriminator tipo_cuenta

The system SHALL create rows in `cuentas` where exactly one of `proveedor_id` or `entidad_id` is non-null, and the value of `tipo_cuenta` MUST match the populated foreign key (`proveedor` ↔ `proveedor_id`, `cliente` ↔ `entidad_id`).

#### Scenarios

**Scenario: create proveedor cuenta happy path**
  Given an existing `Proveedor` with id 7
  When the client sends `POST /api/v1/erp/cuentas` with `{ tipo_cuenta: "proveedor", proveedor_id: 7, banco: "Bancolombia", numero_cuenta: "123-456789-00", tipo: "Ahorros", estado: "Activo" }`
  Then the response SHALL be `201` with `{ success: true, data: { id, tipo_cuenta: "proveedor", proveedor_id: 7, entidad_id: null } }`
  And `cuentas` table SHALL contain exactly one row with `proveedor_id=7` and `entidad_id IS NULL`

**Scenario: create cliente cuenta happy path**
  Given an existing `Entidad` with id 42 and `estado="Cliente"`
  When the client sends `POST /api/v1/erp/cuentas` with `{ tipo_cuenta: "cliente", entidad_id: 42, banco: "Davivienda", numero_cuenta: "987-654321-11", tipo: "Corriente", estado: "Activo" }`
  Then the response SHALL be `201` with `data.tipo_cuenta="cliente"` and `data.entidad_id=42` and `data.proveedor_id=null`

**Scenario: reject when both proveedor_id and entidad_id are provided**
  Given existing `Proveedor` id 7 and `Entidad` id 42
  When the client sends `POST /api/v1/erp/cuentas` with `{ tipo_cuenta: "proveedor", proveedor_id: 7, entidad_id: 42, banco: "X", numero_cuenta: "000", tipo: "Ahorros" }`
  Then the response SHALL be `422` with `{ success: false, error: "Cuenta must reference exactly one of proveedor_id or entidad_id" }`
  And `cuentas` table SHALL remain unchanged

**Scenario: reject when neither proveedor_id nor entidad_id is provided**
  Given no `proveedor_id` and no `entidad_id` in the payload
  When the client sends `POST /api/v1/erp/cuentas` with `{ tipo_cuenta: "proveedor", banco: "X", numero_cuenta: "000", tipo: "Ahorros" }`
  Then the response SHALL be `422` with the same exclusividad error
  And no row SHALL be inserted

**Scenario: reject when tipo_cuenta does not match populated FK**
  Given existing `Entidad` id 42
  When the client sends `POST /api/v1/erp/cuentas` with `{ tipo_cuenta: "proveedor", entidad_id: 42, banco: "X", numero_cuenta: "000", tipo: "Ahorros" }`
  Then the response SHALL be `422` with `{ success: false, error: "tipo_cuenta 'proveedor' does not match populated FK entidad_id" }`

**Scenario: reject when referenced proveedor or entidad does not exist**
  Given no `Proveedor` with id 999 exists
  When the client sends `POST /api/v1/erp/cuentas` with `{ tipo_cuenta: "proveedor", proveedor_id: 999, banco: "X", numero_cuenta: "000", tipo: "Ahorros" }`
  Then the response SHALL be `422` with `{ success: false, error: "proveedor_id 999 does not exist" }`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_creates_proveedor_cuenta`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_creates_cliente_cuenta`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_rejects_both_foreign_keys`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_rejects_missing_foreign_key`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_rejects_tipo_cuenta_mismatch`
- [ ] Endpoint: `POST /api/v1/erp/cuentas`
- [ ] Permission: `erp.auth` (rol_id IN 4,5)

### REQ-BAN-002: List and filter cuentas by tipo_cuenta

The system SHALL expose `GET /api/v1/erp/cuentas` returning paginated cuentas, optionally filtered by `?tipo_cuenta=proveedor|cliente` and by `?proveedor_id=N` or `?entidad_id=N`.

#### Scenarios

**Scenario: list all cuentas paginated**
  Given 3 cuentas proveedor and 2 cuentas cliente exist
  When the client sends `GET /api/v1/erp/cuentas?per_page=10`
  Then the response SHALL be `200` with `{ success: true, data: { data: [...5 items], current_page: 1 } }`

**Scenario: filter by tipo_cuenta=cliente**
  Given 3 cuentas proveedor and 2 cuentas cliente exist
  When the client sends `GET /api/v1/erp/cuentas?tipo_cuenta=cliente`
  Then the response SHALL contain exactly 2 items
  And every item SHALL have `tipo_cuenta="cliente"` and `proveedor_id IS NULL`

**Scenario: filter by proveedor_id**
  Given `Proveedor` id 7 has 2 cuentas and `Proveedor` id 8 has 1 cuenta
  When the client sends `GET /api/v1/erp/cuentas?proveedor_id=7`
  Then the response SHALL contain exactly 2 items
  And every item SHALL have `proveedor_id=7`

**Scenario: invalid tipo_cuenta value returns 422**
  Given any auth context
  When the client sends `GET /api/v1/erp/cuentas?tipo_cuenta=banco`
  Then the response SHALL be `422` with `{ success: false, error: "tipo_cuenta must be 'proveedor' or 'cliente'" }`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_lists_cuentas_paginated`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_filters_by_tipo_cuenta_cliente`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_filters_by_proveedor_id`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_rejects_invalid_tipo_cuenta_value`
- [ ] Endpoint: `GET /api/v1/erp/cuentas`

### REQ-BAN-003: Update and delete cuenta

The system SHALL allow updating mutable fields on `cuentas` (`banco`, `numero_cuenta`, `tipo`, `estado`) but SHALL NOT allow changing the discriminator once created (`proveedor_id`, `entidad_id`, `tipo_cuenta` are immutable post-create). Deletes SHALL be physical (no softDeletes — `cuentas` is a historical ledger per DR-1).

#### Scenarios

**Scenario: update mutable fields succeeds**
  Given a `cuenta` with id 15, `tipo_cuenta="proveedor"`, `banco="Bancolombia"`, `estado="Activo"`
  When the client sends `PUT /api/v1/erp/cuentas/15` with `{ banco: "BBVA", estado: "Inactivo" }`
  Then the response SHALL be `200` with `data.banco="BBVA"` and `data.estado="Inactivo"`
  And `data.tipo_cuenta` SHALL remain `"proveedor"`

**Scenario: attempt to change discriminator fails**
  Given a `cuenta` with id 15 and `proveedor_id=7`
  When the client sends `PUT /api/v1/erp/cuentas/15` with `{ tipo_cuenta: "cliente", entidad_id: 42 }`
  Then the response SHALL be `422` with `{ success: false, error: "Discriminator (tipo_cuenta / proveedor_id / entidad_id) is immutable" }`
  And persisted row SHALL remain unchanged

**Scenario: get cuenta by id returns 200**
  Given a `cuenta` with id 15 exists
  When the client sends `GET /api/v1/erp/cuentas/15`
  Then the response SHALL be `200` with the cuenta payload

**Scenario: get unknown cuenta returns 404**
  Given no `cuenta` with id 99999 exists
  When the client sends `GET /api/v1/erp/cuentas/99999`
  Then the response SHALL be `404` with `{ success: false, error: "Cuenta not found" }`

**Scenario: delete cuenta removes row physically**
  Given a `cuenta` with id 15 and no FK references from `pagos_cliente` or `movimientos`
  When the client sends `DELETE /api/v1/erp/cuentas/15`
  Then the response SHALL be `200` with `{ success: true, data: { deleted: true, id: 15 } }`
  And `SELECT * FROM cuentas WHERE id=15` SHALL return zero rows

**Scenario: delete cuenta referenced by pagos_cliente returns 422**
  Given a `cuenta` with id 15 that is referenced by a row in `pagos_cliente.cuenta_id`
  When the client sends `DELETE /api/v1/erp/cuentas/15`
  Then the response SHALL be `422` with `{ success: false, error: "Cuenta is referenced by pagos_cliente and cannot be deleted" }`
  And the row SHALL remain in `cuentas`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_updates_mutable_fields`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_rejects_discriminator_change`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_returns_404_on_get`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_deletes_cuenta_when_unreferenced`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_blocks_delete_when_referenced`
- [ ] Endpoints: `GET|PUT|DELETE /api/v1/erp/cuentas/{id}`

### REQ-BAN-004: Authorization — only ERP roles access /api/v1/erp/cuentas

The system SHALL reject all requests to `/api/v1/erp/cuentas*` whose authenticated user has `rol_id` outside the allowed set (default `[4, 5]`).

#### Scenarios

**Scenario: user with rol_id=4 passes**
  Given a `Usuario` with `rol_id=4` authenticated via Sanctum
  When the client sends `GET /api/v1/erp/cuentas`
  Then the response SHALL be `200` with `success: true`

**Scenario: user with rol_id=5 passes**
  Given a `Usuario` with `rol_id=5` authenticated via Sanctum
  When the client sends `GET /api/v1/erp/cuentas`
  Then the response SHALL be `200`

**Scenario: user with rol_id=3 (CRM) gets 403**
  Given a `Usuario` with `rol_id=3` authenticated via Sanctum
  When the client sends `GET /api/v1/erp/cuentas`
  Then the response SHALL be `403` with `{ success: false, error: "ERP access requires rol_id IN [4,5]" }`

**Scenario: unauthenticated request gets 401**
  Given no `Authorization: Bearer` header
  When the client sends `GET /api/v1/erp/cuentas`
  Then the response SHALL be `401`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_allows_rol_4`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_allows_rol_5`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_rejects_rol_3_with_403`
- [ ] Test: `tests/Feature/ERP/ErpBankingAccountsTest.php::test_rejects_unauthenticated_with_401`

## Out of scope (this spec)
- Multi-currency support (assumed COP)
- Bank reconciliation
- Payment gateway integration
- Soft-deletes on `cuentas` (kept as physical-delete ledger per DR-1)

## Dependencies
- `erp.auth` middleware (see `erp-rbac-middleware` spec)
- Existing tables: `entidad`, `proveedores`, `usuarios`
- Existing migration: `add_entidad_id_and_tipo_cuenta_to_cuentas_table` (additive ALTER)