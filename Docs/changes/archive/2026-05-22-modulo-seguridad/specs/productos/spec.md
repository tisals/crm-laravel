# Delta for Productos

## General

Existing Productos spec covers backend (tipo column, plans endpoint). This delta adds frontend CRUD operations. Backend endpoints (`POST/PUT/DELETE /api/v1/productos`) already exist via `ProductoController`.

## ADDED Requirements

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
