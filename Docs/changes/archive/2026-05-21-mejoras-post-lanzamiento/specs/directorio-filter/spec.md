# directorio-filter Specification

## Purpose

Allow the Directorio page to filter entities by estado using multiple values (IN clause instead of equality). The frontend sends `estado[]` as an array; the backend accepts an array of estados.

## Requirements

### Requirement: Backend accepts multiple estado values

The `GET /api/v1/entidad` endpoint MUST accept `estado` as a comma-separated string or array param in addition to the current single-value filter.

#### Scenario: Filter by single estado (backward compatible)

- GIVEN an existing client that sends `estado=Activo`
- WHEN the request is processed
- THEN the response includes only entities with `estado = 'Activo'`
- AND backward compatibility is maintained

#### Scenario: Filter by multiple estados

- GIVEN a client sends `estado=Activo,Prospecto`
- WHEN the request is processed
- THEN the response includes entities with `estado IN ('Activo', 'Prospecto')`
- AND entities with other estados are excluded

#### Scenario: Case-insensitive matching

- GIVEN a client sends `estado=activo,PROSPECTO`
- WHEN the request is processed
- THEN entities with `estado = 'Activo'` and `estado = 'Prospecto'` are returned (case-insensitive)

#### Scenario: No estado filter

- GIVEN a client sends no `estado` param
- WHEN the request is processed
- THEN all entities are returned regardless of estado

### Requirement: Frontend sends estado filter

The DirectorioPage MUST include an estado filter dropdown that can select multiple values.

#### Scenario: Directorio shows estado filter

- GIVEN a user is on the DirectorioPage
- WHEN they interact with the filter
- THEN they can select "Prospectos", "Clientes", or both
- AND only entities with matching estado values are displayed

#### Scenario: Default filter for commercial role

- GIVEN a user with role "comercial"
- WHEN they visit the DirectorioPage
- THEN the default filter shows "Prospectos" and "Clientes" (estados `Activo` and `Prospecto`)
- AND entities with other estados are hidden
