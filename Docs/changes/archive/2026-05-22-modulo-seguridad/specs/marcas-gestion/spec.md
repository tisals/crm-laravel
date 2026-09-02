# Marcas (Propia) Specification

## Purpose

CRUD de entidades con estado `'Propia'` — marcas internas de la empresa (Tecnoinnsoft, Deseguridad.dev). Reusa el `EntidadController` existente filtrando por `estado=Propia`. Sin nuevos endpoints backend.

## Requirements

### Requirement: Listar marcas Propia

The frontend MUST call `GET /api/v1/entidad?estado=Propia` to list marcas. Response SHALL use existing Entidad paginated format.

#### Scenario: Load marcas list

- GIVEN entidades with `estado='Propia'` exist
- WHEN user navigates to `/marcas`
- THEN a table SHALL display columns: nombre, identificación, dominio, email, teléfono
- THEN each row SHALL have Edit and Delete action buttons
- THEN a "Nueva Marca" button SHALL be visible in the header

#### Scenario: Empty state

- GIVEN no entidades with `estado='Propia'` exist
- WHEN the marcas list loads
- THEN a centered empty state SHALL display: "No hay marcas registradas" with a "Crear primera marca" CTA button

### Requirement: Create marca

The frontend MUST call `POST /api/v1/entidad` with `estado: 'Propia'` when creating a new marca.

#### Scenario: Create marca via SlidePanel

- GIVEN user clicks "Nueva Marca"
- THEN a SlidePanel SHALL open with form fields: nombre (required), identificación (required), dominio, email, teléfono, dirección
- WHEN user fills required fields and clicks "Crear"
- THEN `POST /api/v1/entidad { ..., estado: 'Propia' }` SHALL be called
- AND on success (201), the list MUST refresh and the panel SHALL close
- AND a success toast SHALL appear: "Marca creada exitosamente"

#### Scenario: Validation errors shown inline

- GIVEN the create form is open
- WHEN user submits without nombre
- THEN the API validation error SHALL display below the nombre field
- AND the panel SHALL remain open

### Requirement: Edit marca

The frontend MUST call `PUT /api/v1/entidad/{id}` when editing.

#### Scenario: Edit marca via SlidePanel

- GIVEN user clicks Edit on a marca row
- THEN a SlidePanel SHALL open pre-filled with current values
- WHEN user modifies fields and clicks "Actualizar"
- THEN `PUT /api/v1/entidad/{id}` SHALL be called
- AND on success (200), the list SHALL refresh and panel SHALL close

### Requirement: Delete marca

The frontend MUST call `DELETE /api/v1/entidad/{id}` after confirmation.

#### Scenario: Delete with confirmation

- GIVEN user clicks Delete on a marca row
- THEN `window.confirm('¿Eliminar marca {nombre}?')` SHALL appear
- WHEN user confirms
- THEN `DELETE /api/v1/entidad/{id}` SHALL be called
- AND on success (200), the list SHALL refresh
- AND if user cancels, no request SHALL be made
