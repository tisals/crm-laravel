# Maestros Specification

## Purpose

CRUD completo para datos maestros (tabla `maestros`). Backend pasa de read-only a store/update/destroy. Frontend agrega modal create/edit y delete con confirmación.

## Architecture Decision

**Clean Architecture completo.** A diferencia del controller actual (Eloquent directo en el controller), el CRUD nuevo debe seguir la misma arquitectura que el resto del proyecto:

| Capa | Artefacto |
|------|-----------|
| Domain | `MaestroRepositoryInterface` |
| Application | `ListarMaestroUseCase`, `CrearMaestroUseCase`, `ActualizarMaestroUseCase`, `EliminarMaestroUseCase` |
| Infrastructure | `EloquentMaestroRepository` |
| Interfaces | `MaestroController` (refactorizado), `MaestroRequest`, `MaestroResource` |
| Tests | `MaestroControllerTest` con CRUD completo |

## Requirements

### Requirement: Backend POST /api/v1/maestros

The system MUST accept `POST /api/v1/maestros` to create a maestro record.

#### Validation rules
- `nombre`: required, string, max:255
- `campo`: required, string, max:100
- `habilitado`: required, string, in:Y,N

#### Scenario: Create maestro successfully

- GIVEN authenticated user with RBAC
- WHEN POST `/api/v1/maestros` with `{ nombre: "Técnico", campo: "Cargos", habilitado: "Y" }`
- THEN response MUST be 201 with `success: true` and the created record in `data`
- AND the record SHALL exist in the database

#### Scenario: Create maestro with missing nombre

- GIVEN authenticated user
- WHEN POST `/api/v1/maestros` with `{ campo: "Cargos", habilitado: "Y" }`
- THEN response MUST be 422 with validation error for `nombre`

#### Scenario: Create maestro with invalid habilitado

- GIVEN authenticated user
- WHEN POST `/api/v1/maestros` with `{ nombre: "Test", campo: "Cargos", habilitado: "X" }`
- THEN response MUST be 422

### Requirement: Backend PUT /api/v1/maestros/{id}

The system MUST accept `PUT /api/v1/maestros/{id}` to update a maestro record. Same validation rules as create.

#### Scenario: Update maestro successfully

- GIVEN a maestro with `id=1` exists
- WHEN PUT `/api/v1/maestros/1` with `{ nombre: "Senior Técnico" }`
- THEN response MUST be 200 with `success: true` and updated record

#### Scenario: Update nonexistent maestro

- GIVEN no maestro with `id=999` exists
- WHEN PUT `/api/v1/maestros/999`
- THEN response MUST be 404

### Requirement: Backend DELETE /api/v1/maestros/{id}

The system MUST accept `DELETE /api/v1/maestros/{id}` to delete a maestro record.

#### Scenario: Delete maestro successfully

- GIVEN a maestro with `id=1` exists
- WHEN DELETE `/api/v1/maestros/1`
- THEN response MUST be 200 with `success: true` and message "Maestro eliminado exitosamente."
- AND the record SHALL be removed from the database

#### Scenario: Delete nonexistent maestro

- GIVEN no maestro with `id=999` exists
- WHEN DELETE `/api/v1/maestros/999`
- THEN response MUST be 404

### Requirement: Tests for new endpoints

The system MUST include Feature tests covering create, update, delete, validation failures, and 404 scenarios.

#### Test scenarios (minimum, via PHPUnit):
- `it_creates_a_maestro`
- `it_updates_a_maestro`
- `it_deletes_a_maestro`
- `it_returns_422_on_validation_failure`
- `it_returns_404_when_maestro_not_found`

### Requirement: Frontend CRUD MaestrosPage

The frontend MUST add "Nuevo Maestro" button, SlidePanel create/edit form, and delete with confirmation.

#### Scenario: Create maestro from frontend

- GIVEN user is on MaestrosPage
- WHEN user clicks "Nuevo Maestro" and submits the SlidePanel form
- THEN `POST /api/v1/maestros` SHALL be called
- AND on success the list SHALL refresh (client-side filter since data is loaded as a single list)

#### Scenario: Edit maestro from frontend

- GIVEN user clicks Edit on a maestro row
- WHEN the SlidePanel opens with pre-filled data and user submits changes
- THEN `PUT /api/v1/maestros/{id}` SHALL be called

#### Scenario: Delete maestro from frontend

- GIVEN user clicks Delete on a maestro row
- WHEN user confirms the dialog
- THEN `DELETE /api/v1/maestros/{id}` SHALL be called
- AND the list SHALL refresh
