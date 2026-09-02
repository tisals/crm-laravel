# Productos Specification

## General

Full specification for Productos module. Covers backend (tipo column, plans endpoint) and frontend CRUD operations (create/edit/delete via SlidePanel).

## ADDED Requirements

### Requirement: Agregar campo `tipo` a productos

The system MUST add a `tipo` column to the `productos` table via migration. Valid values SHALL include `'suscripcion'`, `'servicio'`, `'producto'`. Default MUST be `'producto'`.

#### Scenario: Migración agrega columna tipo
- GIVEN the productos table exists without a `tipo` column
- WHEN the new migration runs
- THEN the `tipo` column MUST exist with type string(50) nullable with default `'producto'`

#### Scenario: Rollback elimina columna tipo
- GIVEN the migration has been applied
- WHEN the migration is rolled back
- THEN the `tipo` column MUST be removed

### Requirement: Endpoint público de planes de suscripción

The system MUST expose `GET /api/v1/plans` (public, no auth). Returns products filtered by `tipo = 'suscripcion'` and `estado = 'Activo'`.

#### Scenario: Listar planes activos de suscripción
- GIVEN products exist with tipo='suscripcion' (some active, some inactive) and tipo='servicio' (should be excluded)
- WHEN GET `/api/v1/plans`
- THEN response MUST be 200 with array of only active suscripcion products
- AND response format: `{ success: true, data: [{ id, name, price, description, features }] }`

#### Scenario: Sin planes de suscripción retorna array vacío
- GIVEN no products with tipo='suscripcion' exist
- WHEN GET `/api/v1/plans`
- THEN response MUST be 200 with `{ success: true, data: [] }`

### Requirement: Response fields para plans

The system MUST map `productos.nombre` to `name` and expose standard plan fields.

#### Scenario: Mapeo de campos del producto
- GIVEN a product with nombre="Plan Básico"
- WHEN it appears in the plans list
- THEN `data[0].name` MUST be "Plan Básico" and `id` MUST be the product id

### Requirement: Frontend create producto via SlidePanel

The frontend MUST add a "Nuevo Producto" button and SlidePanel form calling `POST /api/v1/productos`.

#### Scenario: Create producto

- GIVEN user is on ProductosPage and clicks "Nuevo Producto"
- THEN a SlidePanel SHALL open with fields: nombre (required), precio, IVA, medida, estado (select: Activo/Inactivo)
- WHEN user submits valid data
- THEN `POST /api/v1/productos` SHALL be called
- AND on success (201), the query SHALL invalidate `['productos']` and the panel SHALL close

#### Scenario: Validation error from API

- GIVEN the create form is open
- WHEN user submits empty nombre
- THEN the error message from API SHALL display in the form

### Requirement: Frontend edit producto

The frontend MUST add an Edit button per row opening a pre-filled SlidePanel calling `PUT /api/v1/productos/{id}`.

#### Scenario: Edit producto

- GIVEN user clicks Edit on a producto row
- THEN a SlidePanel SHALL open with current values
- WHEN user modifies fields and submits
- THEN `PUT /api/v1/productos/{id}` SHALL be called
- AND on success (200), the list SHALL refresh

### Requirement: Frontend delete producto

The frontend MUST add a Delete button per row with confirmation dialog.

#### Scenario: Delete producto with confirmation

- GIVEN user clicks Delete on a producto row
- THEN `confirm('¿Eliminar producto {nombre}?')` SHALL appear
- WHEN user confirms
- THEN `DELETE /api/v1/productos/{id}` SHALL be called
- AND on success (200), the list SHALL refresh

#### Scenario: Cancel delete does nothing

- GIVEN the delete confirmation dialog
- WHEN user clicks Cancel
- THEN no API request SHALL be made
