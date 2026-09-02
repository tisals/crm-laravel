# Delta for Productos

## General: No existing spec found — this is a FULL spec for a new capability.

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
