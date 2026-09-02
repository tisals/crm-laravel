# Cuentas Endpoint Specification

## Purpose

SAIlus-facing alias for `GET /api/v1/entidad/{id}` with a normalized response format. Provides a consistent account resource for the FastAPI integration.

## Requirements

### Requirement: Exponer entidad como cuenta SAIlus

The system MUST expose `GET /api/v1/cuentas/{id}` (protected, auth:sanctum). This is a READ-ONLY alias — it MUST NOT create or modify entities.

#### Scenario: Cuenta existente retorna 200 con formato SAIlus
- GIVEN an entidad with id=10, nombre="Mi Empresa", and estado="Activo"
- WHEN GET `/api/v1/cuentas/10` with valid auth
- THEN response MUST be 200: `{ success: true, data: { id: 10, nombre: "Mi Empresa", plan_type: null, status: "Activo" } }`

#### Scenario: Cuenta inexistente retorna 404
- GIVEN no entidad with id=999 exists
- WHEN GET `/api/v1/cuentas/999` with valid auth
- THEN response MUST be 404 with `{ success: false, error: "Entidad no encontrada." }`

### Requirement: Formato de respuesta normalizado

The system MUST include `id`, `nombre`, `plan_type`, and `status` in the response data. `plan_type` MAY be null if no plan is assigned.

#### Scenario: Entity con plan_type asignado
- GIVEN an entidad with a servicio linked that has a plan type
- WHEN GET `/api/v1/cuentas/{id}`
- THEN `data.plan_type` MUST reflect the linked plan type
