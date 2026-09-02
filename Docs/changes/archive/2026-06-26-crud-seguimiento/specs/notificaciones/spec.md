# Capability: notificaciones (delta)

## Purpose

The `notificaciones` capability covers application-level notifications sent from the CRM. This delta scopes the existing `FollowUpNotification` to the comercials responsible for the seguimiento's entidad, instead of fanning out to every admin.

## Requirements

### Requirement: Comercial-scoped routing

When a seguimiento is created (via `POST /api/v1/seguimientos` or `POST /api/v1/contactos/{id}/acciones`) and triggers a `FollowUpNotification`, the recipients MUST be:

1. All usuarios with rol `Comercial` who are mapped to the seguimiento's `entidad_id` via `entidad_usuario`.
2. **Fallback**: if no comercial is mapped, send to all `Admin` and `SuperAdmin` usuarios.

If neither group has any recipient, the system MUST NOT send a notification but MUST log a warning at `warning` level.

#### Scenario: Comercial is mapped to the entidad
- WHEN a seguimiento is created for `entidad_id=10` and `entidad_usuario` has `usuario_id=42` (rol=Comercial)
- THEN the notification is sent ONLY to user 42

#### Scenario: Multiple comercials mapped
- WHEN a seguimiento is created for `entidad_id=10` and `entidad_usuario` has users 42 and 55 (both Comercial)
- THEN the notification is sent to BOTH users

#### Scenario: No comercial mapped, admins exist
- WHEN a seguimiento is created for an entidad with no `entidad_usuario` rows, and 2 admins exist
- THEN the notification is sent to those 2 admins

#### Scenario: No recipients at all
- WHEN a seguimiento is created and no comercial is mapped AND no admin exists
- THEN the notification is NOT sent, but a `warning` log line is emitted
