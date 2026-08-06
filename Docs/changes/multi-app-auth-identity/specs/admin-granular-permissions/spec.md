# Admin Granular Permissions Specification

## Purpose

Allow admins to override scoped permissions per (user, app) without
touching the rol defaults — admin UI and API.

## Requirements

### Requirement: List Scoped Permissions

The system MUST expose `GET /api/v1/usuarios/{userId}/apps/{appId}/permisos`
returning the user's current scoped permissions.

#### Scenario: List existing permissions

- GIVEN a user with 3 scoped permissions on app "brp"
- WHEN admin calls the endpoint with the user's id
- THEN the response returns `{"permisos": ["brp.create", ...]}` (3 items)

### Requirement: Sync Permissions (Replace-All)

The system MUST expose `POST /api/v1/usuarios/{userId}/apps/{appId}/permisos`
that replaces all scoped permissions atomically.

#### Scenario: Replace permissions

- GIVEN a user with [v1, v2] scoped on app
- WHEN admin POSTs `{"vistas": ["v3"]}`
- THEN the user MUST have exactly [v3] (v1, v2 removed)

### Requirement: Grant One Permission

`POST .../permisos/grant` MUST add a single vista without affecting others.

#### Scenario: Grant idem-potently

- GIVEN existing [v1] and POST `{"vista": "v1"}`
- THEN the result MUST still be [v1] (no duplicates)

### Requirement: Revoke Permission

`DELETE .../permisos/{vista}` MUST remove the single vista.

#### Scenario: Idempotent revoke

- GIVEN user with [v1, v2] and DELETE v1
- THEN the result MUST be [v2]
- AND deleting v2 (non-existent) returns 404 OR idempotent success

### Requirement: Reset to Rol Defaults

`POST .../reset-to-role-defaults` MUST clear user overrides and roll back
to the rol permissions.

#### Scenario: Reset clears overrides

- GIVEN user with [v1, v2] scoped and rol=[v_default]
- WHEN admin calls reset
- THEN the user MUST have [v_default] (only rol permissions)

### Requirement: Hook Invalidation

Any mutation MUST set `user_identity_snapshot.is_stale=1` for the affected
user and increment a metrics counter.

#### Scenario: Cache bust

- GIVEN a user with cached snapshot
- WHEN admin mutates any permission
- THEN the next read MUST trigger a recompute

## Out of Scope

- Bulk operations across multiple users (single user at a time)
- Audit log of who changed what (separate concern)
