# Proposal: sailus-integration-v1

## Intent

Implementar los endpoints faltantes que SAIlus necesita consumir del CRM Laravel según ADR-CRM-integrations-20260515.

## Scope

### In Scope
- `GET /api/v1/auth/validate-key` — validar API keys de bots (hoy 501)
- `POST /api/v1/webhook/registration` — registro desde WordPress/Marketing
- `GET /api/v1/cuentas/{id}` — alias de entidad para SAIlus
- `GET /api/v1/entidad/{id}/servicios` — servicios activos de una entidad
- `GET /api/v1/plans` — productos con tipo suscripción (campo nuevo en productos)

### Out of Scope
- Multi-tenancy
- Permisos granulares por bot
- Traducción de endpoints a inglés

## Capabilities

### New Capabilities
- `validate-api-key`: Validación de API keys con response SAIlus
- `webhook-registration`: Registro de organizaciones desde WordPress
- `cuentas-endpoint`: Endpoint cuentas/{id} con response estandarizado

### Modified Capabilities
- `productos`: Agregar campo `tipo` para filtrar planes de suscripción
- `entidad-servicios`: Listar servicios anidados por entidad

## Approach

Backend: extender controladores existentes o crear nuevos. Usar ValidateApiKeyMiddleware existente para auth de bots. Webhook registration crea entidad + contacto + servicio en transacción.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Webhook registration necesita schema de datos específico | Media | Validar con request dedicado |

## Success Criteria

- [ ] validate-key retorna 200 con bot_id y permissions (no 501)
- [ ] webhook/registration crea entidad+contacto+servicio
- [ ] cuentas/{id} retorna data de entidad en formato SAIlus
- [ ] entidad/{id}/servicios retorna servicios activos
- [ ] /plans retorna productos tipo suscripción
