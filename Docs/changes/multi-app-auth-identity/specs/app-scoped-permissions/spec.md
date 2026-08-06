# App-Scoped Permissions Specification

## Purpose

Extend the existing `permisos(rol_id, vista)` global-per-role model
with per-(user, app) scoping without breaking the current behavior.

## Requirements

### Requirement: Storage of Scoped Permissions

The system MUST persist per-(usuario, app, vista) permissions in
`usuario_app_permisos` with UNIQUE (usuario_id, app_id, vista).

#### Scenario: Grant a scoped permission

- GIVEN a user without BRP write access
- WHEN admin POSTs `{"vista": "brp.create"}` to the assignment endpoint
- THEN a row is inserted in `usuario_app_permisos`

### Requirement: Effective Permissions = Core ∪ App-Scoped

The system MUST resolve a user's effective permissions for an app as the
union of `permisos WHERE rol_id=X` and `usuario_app_permisos WHERE
usuario_id=Y AND app_id=Z`.

#### Scenario: Comercial with no overrides

- GIVEN user with rol="Comercial", no `usuario_app_permisos` rows
- WHEN effective permissions are requested for app "brp"
- THEN the result MUST equal the rol's `permisos` set

#### Scenario: Comercial with app override

- GIVEN user with rol="Comercial" and one `usuario_app_permisos` row
  granting "brp.admin" (NOT in rol permissions)
- WHEN effective permissions are requested for "brp"
- THEN the result MUST include "brp.admin"

### Requirement: Cascade on App Assignment

The system MUST create scoped permissions automatically when
`POST /entidad/{id}/apps/{appId}` assigns an app to an entity.

#### Scenario: Bulk cascade

- GIVEN an entity with 5 users and app "brp" being newly assigned
- WHEN the assignment POST succeeds
- THEN the system MUST create `usuario_app_permisos` rows for each
  user based on the rol's defaults

### Requirement: Isolation Between Apps

Permissions on app A MUST NOT grant access to app B's resources.

#### Scenario: Cross-app access denied

- GIVEN a user with `usuario_app_permisos` on "brp" but NO rows on
  "indicadores"
- WHEN the user attempts an indicadores API call
- THEN the system MUST return 403 even if "indicadores" is in the
  user's rol permissions

## Out of Scope

- Per-entity scoping (only app-level in v1)
- Time-bound permissions (TTL per vista)
