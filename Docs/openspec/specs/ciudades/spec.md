# Ciudades Specification

## Purpose

CRUD completo para ciudades (tabla `ciudades`, primaryKey `cod_municipio`). Backend pasa de read-only (index/show) a store/update/destroy. Frontend agrega modal create/edit y delete.

## Requirements

### Requirement: Backend POST /api/v1/ciudades

The system MUST accept `POST /api/v1/ciudades` to create a ciudad record.

#### Validation rules
- `cod_municipio`: required, string, max:10, unique:ciudades
- `nombre`: required, string, max:200
- `departamento`: nullable, string, max:100

#### Scenario: Create ciudad successfully

- GIVEN authenticated user with RBAC
- WHEN POST `/api/v1/ciudades` with `{ cod_municipio: "05001", nombre: "Medellín", departamento: "Antioquia" }`
- THEN response MUST be 201 with `success: true` and the created record in `data`

#### Scenario: Create ciudad with duplicate cod_municipio

- GIVEN a ciudad with `cod_municipio="05001"` already exists
- WHEN POST `/api/v1/ciudades` with duplicate `cod_municipio`
- THEN response MUST be 422 with validation error for `cod_municipio`

### Requirement: Backend PUT /api/v1/ciudades/{cod_municipio}

The system MUST accept `PUT /api/v1/ciudades/{cod_municipio}` to update. Validation: `nombre` required, `departamento` optional.

#### Scenario: Update ciudad successfully

- GIVEN a ciudad with `cod_municipio="05001"` exists
- WHEN PUT `/api/v1/ciudades/05001` with `{ nombre: "Medellín Actualizado" }`
- THEN response MUST be 200 with updated record

#### Scenario: Update nonexistent ciudad

- GIVEN no ciudad with `cod_municipio="99999"` exists
- WHEN PUT `/api/v1/ciudades/99999`
- THEN response MUST be 404

### Requirement: Backend DELETE /api/v1/ciudades/{cod_municipio}

The system MUST accept `DELETE /api/v1/ciudades/{cod_municipio}` to delete.

#### Scenario: Delete ciudad successfully

- GIVEN a ciudad exists
- WHEN DELETE `/api/v1/ciudades/{cod_municipio}`
- THEN response MUST be 200 with `success: true`
- AND the record SHALL be removed from the database

#### Scenario: Delete nonexistent ciudad

- GIVEN no ciudad matches the cod_municipio
- WHEN DELETE `/api/v1/ciudades/99999`
- THEN response MUST be 404

### Requirement: Tests for new endpoints (modified existing test file)

The existing `CiudadControllerTest` MUST be extended with tests covering create, update, delete, validation, and 404.

#### Test scenarios (minimum additions):
- `it_creates_a_ciudad`
- `it_updates_a_ciudad`
- `it_deletes_a_ciudad`
- `it_returns_422_on_duplicate_cod_municipio`
- `it_returns_404_when_updating_nonexistent_ciudad`

### Requirement: Frontend CRUD CiudadesPage

The frontend MUST add "Nueva Ciudad" button, SlidePanel create/edit form, and delete with confirmation.

#### Scenario: Create ciudad from frontend

- GIVEN user is on CiudadesPage
- WHEN user clicks "Nueva Ciudad" and submits the SlidePanel form with cod_municipio, nombre, departamento
- THEN `POST /api/v1/ciudades` SHALL be called
- AND on success the query `['ciudades']` SHALL be invalidated

#### Scenario: Edit ciudad from frontend

- GIVEN user clicks Edit on a ciudad row
- WHEN the SlidePanel form is submitted
- THEN `PUT /api/v1/ciudades/{cod_municipio}` SHALL be called

#### Scenario: Delete ciudad from frontend

- GIVEN user clicks Delete and confirms
- THEN `DELETE /api/v1/ciudades/{cod_municipio}` SHALL be called
- AND the list SHALL refresh
