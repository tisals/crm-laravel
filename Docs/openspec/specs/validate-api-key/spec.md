# Validate API Key Specification

## Purpose

Endpoint for SAIlus bots to validate their API key and retrieve entity metadata. Reuses existing `ValidateApiKeyMiddleware`.

## Requirements

### Requirement: Validar API key y retornar datos de entidad

The system MUST expose `GET /api/v1/auth/validate-key` with auth via `X-API-Key` header (ValidateApiKeyMiddleware). The controller MUST read validated data from `$request->attributes` and return SAIlus-formatted response.

#### Scenario: API key válida retorna 200 con datos
- GIVEN a valid `X-API-Key` header matching an active entity with allowed domain
- WHEN GET `/api/v1/auth/validate-key`
- THEN response MUST be 200: `{ success: true, data: { valid: true, bot_id: <id>, name: "<nombre>", permissions: ["read", "write"] } }`

#### Scenario: API key inválida retorna 401
- GIVEN an `X-API-Key` header that does not match any entity
- WHEN GET `/api/v1/auth/validate-key`
- THEN response MUST be 401 with `{ success: false, error: "API key inválida." }`

#### Scenario: Entidad inactiva retorna 401
- GIVEN an `X-API-Key` matching an entity with estado !== 'Activo'
- WHEN GET `/api/v1/auth/validate-key`
- THEN response MUST be 401 with error message indicating inactive entity

### Requirement: Response incluye organización como bot

The system MUST map `entidad.id` → `bot_id` and `entidad.nombre` → `name` in the response.

#### Scenario: Mapeo correcto de campos
- GIVEN entity with id=5 and nombre="SAIlus Bot"
- WHEN GET `/api/v1/auth/validate-key` with valid key for that entity
- THEN `data.bot_id` MUST be 5 and `data.name` MUST be "SAIlus Bot"
