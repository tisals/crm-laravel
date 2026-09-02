# CRUD API Completo — Full Specification

## Overview

Complete CRUD API for 19 ERP entities replacing the existing 6-table CRM schema. All endpoints under `/api/v1/`, Sanctum auth, Clean Architecture. Every entity: Index (paginated), Show, Store, Update, Destroy.

## Cross-Cutting Concerns

| Concern | Decision |
|---------|----------|
| **Soft deletes** | All entities SHALL support soft deletes except `ciudades` (read-only) and `movimientos` (audit trail requirement). Deleting a `ciudad` is forbidden entirely. |
| **Audit trail** | All entities SHALL have `created_by` and `updated_by` (nullable FK to `usuarios`). The system MUST auto-set these from `auth()->id()`. |
| **Pagination** | All Index endpoints MUST return 15 items/page by default, `per_page` query param (max 100). Response includes `{ data, meta: { current_page, last_page, per_page, total } }`. |
| **Sorting** | Default sort: `created_at DESC`. Override via `sort_by` and `sort_order` query params. |
| **Search** | `search` query param on: `entidad`, `contacto`, `oportunidad`, `servicios`, `colaboradores`, `proveedores`, `productos`. Searches `nombre`/`nombres` + `identificacion`. |
| **JSON Envelope** | `{ success: bool, data?: ..., message?: string, error?: string }` — via `ApiResponse` trait. |
| **Auth** | All CRUD endpoints MUST require `auth:sanctum` except public health check. |

## Per-Domain Specifications

### 1. Seguridad & Accesos

| Entity | Endpoints | Store/Update Rules | Business Logic |
|--------|-----------|-------------------|----------------|
| `roles` | `GET /roles`, `POST /roles`, `GET /roles/{id}`, `PUT /roles/{id}`, `DELETE /roles/{id}` | `nombre`: required, string, max:100, unique | Cannot delete if `usuarios` reference it (SHOULD check). Destroy = soft. |
| `permisos` | `GET /permisos`, `POST /permisos`, `GET /permisos/{id}`, `PUT /permisos/{id}`, `DELETE /permisos/{id}` | `rol_id`: required, exists:roles,id. `vista`: required, string, max:100 | On delete: cascade to permission assignments. |
| `usuarios` | `POST /auth/login`, `POST /auth/logout`, `GET /usuarios`, `POST /usuarios`, `GET /usuarios/{id}`, `PUT /usuarios/{id}`, `DELETE /usuarios/{id}`, `PUT /usuarios/{id}/status` | `email`: required, email, unique. `nombre`: required, string. `password`: required (store), min:8. `rol_id`: required, exists:roles,id | Login returns Sanctum token. Password stored as bcrypt hash. Status toggle: `estado` switches `Activo`/`Inactivo`. Cannot self-delete. |

### 2. Maestros Generales

| Entity | Endpoints | Rules | Notes |
|--------|-----------|-------|-------|
| `ciudades` | `GET /ciudades`, `GET /ciudades/{cod_municipio}` | No Store/Update/Destroy. Filter by `departamento`. | Read-only reference data seeded from DANE codes. PK is `cod_municipio`. |
| `productos` | Full CRUD | `nombre`: required, max:200. `iva`: numeric, min:0, max:100. `linea_negocio`: string, max:100. | Standard CRUD. |
| `etiquetas` | Full CRUD | `nombre`: required, max:100, unique. | Standard CRUD. |

### 3. Directorio Empresarial

| Entity | Endpoints | Rules | Business Logic |
|--------|-----------|-------|---------------|
| `entidad` | Full CRUD + `GET /entidad?search=` | `tipo_persona`: required, in:Natural,Juridica. `identificacion`: required, unique. `nombre`: required. `ciudad_cod`: exists:ciudades,cod_municipio. | Status field `Activo`/`Inactivo`. Cannot delete if has related `oportunidad` or `servicios` (SHOULD check). |
| `lugares_entidad` | Full CRUD (nested under `/entidad/{id}/lugares`) | `entidad_id`: required (auto from route). `direccion`: max:255. `ciudad_cod`: exists:ciudades. `contacto_id`: exists:contacto,id. | Cascade soft-delete when parent `entidad` is deleted. |
| `contacto` | Full CRUD + `GET /contacto?search=&entidad_id=` | `nombres`, `apellidos`: required. `email_contacto`: email, nullable. `entidad_id`: exists:entidad,id, nullable. | Supports orphan contacts (no entidad). Unique constraint: no duplicate email per entidad (Use Case rule). |

### 4. Talento & Proveedores

| Entity | Endpoints | Rules | Notes |
|--------|-----------|-------|-------|
| `colaboradores` | Full CRUD + `GET /colaboradores?search=` | `nombres`, `apellidos`: required. `usuario_id`: required, exists:usuarios,id, unique. `identificacion`: unique. | `fecha_retiro` set only when `estado` = Inactivo. Soft delete. |
| `proveedores` | Full CRUD + `GET /proveedores?search=` | `identificacion`: required, unique. `ciudad_cod`: exists:ciudades. Either `nombres`+`apellidos` OR `profesion`. | Cannot delete if referenced by `cuentas` or `servicios` (SHOULD check). |

### 5. CRM Comercial

| Entity | Endpoints | Rules | Business Logic |
|--------|-----------|-------|---------------|
| `oportunidad` | Full CRUD + `GET /oportunidad?search=&estado=&entidad_id=` | `codigo`: system-generated (auto), unique. `entidad_id`: required, exists. `contacto_id`: required, exists. `fecha`: date. `estado`: in:Borrador,Enviada,Ganada,Perdida. | `codigo` auto-generated as `OP-{YYYY}-{XXXX}` on Store. Changing to `Ganada` SHALL auto-create a `servicios` record (Use Case rule). |
| `detalle_oportunidad` | Full CRUD (nested `/oportunidad/{id}/detalles`) | `oportunidad_id`: auto. `producto_id`: exists. `cantidad`: required, numeric, min:0.01. `vr_unitario`: required, numeric. | On Store, MUST auto-calculate `vr_total = cantidad × vr_unitario`. If `producto_id` set, pre-fill `iva` and `medida` from product (Use Case). |
| `seguimiento` | Full CRUD + `GET /seguimiento?oportunidad_id=&contacto_id=&entidad_id=&fecha_desde=&fecha_hasta=` | `fecha`: required, date. At least one of `oportunidad_id`, `contacto_id`, `entidad_id` MUST be set. `autor_id`: auto from `auth()->id()`. | `estado`: Pendiente (default) or Completado. Supports date range filtering. Soft delete. |

### 6. Operaciones ERP

| Entity | Endpoints | Rules | Business Logic |
|--------|-----------|-------|---------------|
| `servicios` | Full CRUD + `GET /servicios?search=&estado=&entidad_id=` | `oportunidad_id`: required, exists, unique (one service per won opportunity). `entidad_id`: required, exists. `nombre`: required. `prestador_id`: exists:proveedores. | `vr_servicio` defaults from related `oportunidad` total. |
| `detalle_servicios` | Full CRUD (nested `/servicios/{id}/detalles`) | `servicio_id`: auto. `producto_id`: exists. `cantidad`: numeric. `precio`: numeric. | Auto-calculate `sub_total`, `iva`, `total` on Store/Update. |
| `orden_servicio` | Full CRUD + `GET /orden-servicio?estado=&colaborador_id=&proveedor_id=&desde=&hasta=` | `detalle_srv_id`: required, exists. At least one of `colaborador_id` or `proveedor_id` MUST be set. `contacto_id`: exists. | Routes: `orden-servicio` (hyphenated). Soft delete. |

### 7. Finanzas

| Entity | Endpoints | Rules | Notes |
|--------|-----------|-------|-------|
| `cuentas` | Full CRUD | `proveedor_id`: required, exists. `banco`: required. `numero_cuenta`: required. `tipo`: in:Ahorros,Corriente. | Standard CRUD. No soft delete (financial records are permanent). |
| `movimientos` | Full CRUD + `GET /movimientos?proveedor_id=&servicio_id=&fecha_desde=&fecha_hasta=` | `fecha`: required, date. At least one of `valor_debito` > 0 OR `valor_credito` > 0. `proveedor_id`, `colaborador_id`, `servicio_id`: nullable, exists. | No soft delete — financial records MUST be permanent. Debit/credit MUST NOT both be zero. SHOULD NOT both be > 0 (use case validation). |

## Key Scenarios

### Scenario: Usuario login returns Sanctum token
- GIVEN a `usuario` with `estado = Activo` and valid credentials
- WHEN POST `/api/v1/auth/login` with `email` and `password`
- THEN response returns 200 with `{ success: true, data: { token, usuario: {...} } }`
- AND a new Sanctum `PersonalAccessToken` is created

### Scenario: Inactive usuario cannot login
- GIVEN a `usuario` with `estado = Inactivo`
- WHEN POST `/api/v1/auth/login` with credentials
- THEN response returns 401 with `{ success: false, error: "Usuario inactivo" }`

### Scenario: Oportunidad auto-generates codigo
- GIVEN a valid `entidad` and `contacto` exist
- WHEN POST `/api/v1/oportunidad` with required fields
- THEN response returns 201 with `data.codigo` matching pattern `OP-{year}-{sequential number}`

### Scenario: Oportunidad won creates Servicio
- GIVEN an existing `oportunidad` with `estado = Enviada`
- WHEN PUT `/api/v1/oportunidad/{id}` with `estado = Ganada`
- THEN a new `servicios` record is auto-created linked to this `oportunidad`
- AND `servicios.entidad_id` matches `oportunidad.entidad_id`

### Scenario: Detalle auto-calculates totals
- GIVEN a valid `oportunidad` and `producto` exist
- WHEN POST `/api/v1/oportunidad/{id}/detalles` with `cantidad = 2` and `vr_unitario = 100000`
- THEN `data.vr_total` = 200000
- AND `data.iva` is auto-filled from `productos.iva` percentage

### Scenario: Movimiento validation
- GIVEN a valid `proveedor` exists
- WHEN POST `/api/v1/movimientos` with `valor_debito = 0` AND `valor_credito = 0`
- THEN response returns 422 with validation error

### Scenario: Soft delete restores
- GIVEN an existing `colaborador`
- WHEN DELETE `/api/v1/colaboradores/{id}`
- THEN response returns 200 with `success: true`
- AND querying `GET /api/v1/colaboradores` does NOT include it
- AND querying with `?trashed=true` includes it

## Error Scenarios

| Code | Condition | Response |
|------|-----------|----------|
| 401 | No/invalid Sanctum token | `{ success: false, error: "Unauthenticated." }` |
| 403 | Authenticated but lacks permission | `{ success: false, error: "Forbidden." }` |
| 404 | Resource not found | `{ success: false, error: "Resource not found." }` |
| 422 | Validation failure | `{ success: false, message: "Validation failed.", error: { field: [errors] } }` |
| 409 | Conflict (e.g. duplicate unique constraint) | `{ success: false, error: "Duplicate entry." }` |

## Auth & Authorization

- All CRUD endpoints protected by `auth:sanctum` middleware
- RBAC via `usuarios.rol_id → permisos.vista`: Use Case checks if authenticated usuario's rol has permission for the requested `vista` (module name). If `permisos` table has no entry for the role + vista, access is DENIED.
- Public: health check (`GET /api/v1/health`), login (`POST /api/v1/auth/login`)
- `ValidateApiKeyMiddleware` preserved for FastAPI integration (future use)
