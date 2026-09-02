# Delta for Entidad Servicios

## General: No existing spec found — this is a FULL spec for a new capability.

## ADDED Requirements

### Requirement: Listar servicios activos de una entidad

The system MUST expose `GET /api/v1/entidad/{id}/servicios` (protected, auth:sanctum). Returns servicios where `entidad_id = {id}` and `estado = 'Activo'`.

#### Scenario: Entidad con servicios activos
- GIVEN an entidad with id=5 has 3 servicios (2 activos, 1 inactivo)
- WHEN GET `/api/v1/entidad/5/servicios` with valid auth
- THEN response MUST be 200 with array of 2 servicios
- AND each servicio MUST include `id`, `nombre`, `vr_servicio`, `estado`, and `detalles_count`

#### Scenario: Entidad sin servicios retorna array vacío
- GIVEN an entidad with id=99 exists but has no servicios
- WHEN GET `/api/v1/entidad/99/servicios` with valid auth
- THEN response MUST be 200 with `{ success: true, data: [] }`

#### Scenario: Entidad inexistente retorna 404
- GIVEN no entidad with id=999 exists
- WHEN GET `/api/v1/entidad/999/servicios` with valid auth
- THEN response MUST be 404 with error message

### Requirement: Incluir conteo de detalles por servicio

The system MUST include `detalles_count` in each servicio response, representing the count of associated `detalle_servicios` rows.

#### Scenario: Servicio con detalles incluidos en conteo
- GIVEN a servicio with 5 detalle_servicios rows
- WHEN it appears in the servicios list
- THEN `detalles_count` MUST be 5
