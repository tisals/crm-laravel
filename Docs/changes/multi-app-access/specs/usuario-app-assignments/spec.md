# Spec: User-App Assignments (`usuario_app` pivot + admin endpoints)

## Purpose

Define the `usuario_app` pivot table that records which user has access to which app and with which role. The pivot is the runtime source of truth for app authorization — login response, `/me/apps`, and `/auth/validate-token` all read from it. Admin endpoints at `POST /usuarios/{id}/apps` and `DELETE /usuarios/{id}/apps/{app_id}` mutate it. Super-admin users (via `roles.es_super_admin = TRUE`) bypass the pivot entirely.

This spec covers the table, the canonical seed for the 4 named users, the public admin endpoints (list/assign/revoke), the UNIQUE constraint and bypass rules.

---

## Requirements

### REQ-USRAPP-1: Schema for the `usuario_app` pivot

The system MUST create a `usuario_app` table with the following columns:

| Column | Type | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT |
| `usuario_id` | BIGINT UNSIGNED | NOT NULL, FK → `usuarios(id)` ON DELETE CASCADE |
| `app_id` | BIGINT UNSIGNED | NOT NULL, FK → `apps(id)` ON DELETE CASCADE |
| `rol_id` | BIGINT UNSIGNED | NOT NULL, FK → `roles(id)` ON DELETE RESTRICT |
| `created_at` | TIMESTAMP | NULL |
| `updated_at` | TIMESTAMP | NULL |

Constraints: `UNIQUE(usuario_id, app_id)`.

Indexes: `(usuario_id)` (for `/me/apps`), `(app_id)` (for admin views).

**Given** `usuarios`, `apps`, and `roles` tables all exist (and `apps` is seeded)
**When** the migration runs
**Then** the `usuario_app` table exists with the schema above
**And** inserting (1, 1, 1) twice raises a UNIQUE constraint violation the second time

#### Scenario: Migration creates the table on a fresh DB
- GIVEN an empty DB with `usuarios`, `apps`, `roles` present
- WHEN the `create_usuario_app_table` migration runs
- THEN `usuario_app` exists with all columns and the FK constraints described
- AND `SHOW INDEX FROM usuario_app` lists a UNIQUE index on `(usuario_id, app_id)`

#### Scenario: FK cascade on usuario delete
- GIVEN a `usuario_app` row linking user_id=42 to app_id=1
- WHEN `DELETE FROM usuarios WHERE id = 42` runs
- THEN the `usuario_app` row is automatically deleted (ON DELETE CASCADE)
- AND no FK violation error is raised

#### Scenario: FK restrict on rol delete
- GIVEN a `usuario_app` row with `rol_id = 5`
- WHEN `DELETE FROM roles WHERE id = 5` is attempted
- THEN the database rejects the delete (ON DELETE RESTRICT)
- AND an error message indicates the FK constraint

#### Scenario: FK cascade on app delete
- GIVEN a `usuario_app` row with `app_id = 99`
- WHEN `DELETE FROM apps WHERE id = 99` runs
- THEN all `usuario_app` rows with `app_id = 99` are also deleted
- AND the user records are preserved

#### Scenario: UNIQUE on (usuario_id, app_id)
- GIVEN an existing assignment for (usuario_id=5, app_id=1)
- WHEN `INSERT INTO usuario_app (usuario_id, app_id, rol_id) VALUES (5, 1, 2)` is attempted
- THEN the insert fails with UNIQUE constraint violation
- AND the existing row is unchanged

#### Scenario: Same user can have assignments to different apps
- GIVEN an existing assignment for (usuario_id=5, app_id=1)
- WHEN `INSERT INTO usuario_app (usuario_id, app_id, rol_id) VALUES (5, 2, 1)` runs
- THEN the insert succeeds
- AND the user now has assignments to app_id 1 and app_id 2

#### Scenario: Different users can have assignments to the same app
- GIVEN an existing assignment for (usuario_id=5, app_id=1)
- WHEN `INSERT INTO usuario_app (usuario_id, app_id, rol_id) VALUES (7, 1, 2)` runs
- THEN the insert succeeds
- AND both users now have separate assignments to app_id 1 (different roles)

---

### REQ-USRAPP-2: Canonical seed for the 4 named users (real DB IDs)

The system MUST provide a `UsuarioAppSeeder` that assigns the 4 commercial/admin users to apps using the REAL user IDs documented in the explore report (NOT the literal `(1,2,3,4)` from the PRD):

| usuario (email) | usuario.id (real) | apps | rol_id (super-admin) |
|---|---|---|---|
| `admin@tecnoinnsoft.dev` | **5** | all 6 apps | `roles.id = 1` |
| `innovacionydesarrollo.tis@gmail.com` (Lorena) | **1** | `crm` | rol with `slug = 'comercial'` (resolved by slug) |
| `servicioalcliente.tis@gmail.com` (Patricia) | **4** | `crm`, `brp` | rol with `slug = 'operativo'` |
| `direccion.tis@gmail.com` (Jaime) | **3** | `crm`, `marketing` | `roles.id = 1` (super-admin flag) |

The seeder MUST:
- Resolve apps by `slug` (not by id, because ids may differ fresh vs. seed)
- Resolve roles by `slug` (which must exist after PM-1 migration runs)
- Be idempotent (rerun is no-op)
- Use database transactions
- Skip soft-deleted users (`usuarios.deleted_at IS NOT NULL`)

**Given** the seed has run on a DB where these users and the apps table exist
**When** `SELECT * FROM usuario_app` is queried
**Then** the rows match the assignments above (4 users × variable apps = 9 rows total: 6+1+2+2 minus duplicates = 11 rows wait check... 6 vos + 1 lorena + 2 patricia + 2 jaime = 11 rows total)

> Note: total = 6 (admin) + 1 (Lorena) + 2 (Patricia) + 2 (Jaime) = **11 pivot rows** for the 4 canonical users.

#### Scenario: Admin (Vos) has 6 app assignments
- GIVEN the user `admin@tecnoinnsoft.dev` exists with id=5
- WHEN the seeder runs
- THEN `SELECT COUNT(*) FROM usuario_app WHERE usuario_id = 5` returns 6
- AND each assignment is to a distinct app slug from {crm, sailus, marketing, wp-plugin, la-llave, brp}
- AND each `rol_id` references `roles.id = 1` (super-admin)

#### Scenario: Lorena has exactly 1 app assignment
- GIVEN the user `innovacionydesarrollo.tis@gmail.com` exists with id=1
- WHEN the seeder runs
- THEN `SELECT COUNT(*) FROM usuario_app WHERE usuario_id = 1` returns 1
- AND that single assignment is to app with `slug = 'crm'`
- AND `rol_id` references the role with `slug = 'comercial'`

#### Scenario: Patricia has exactly 2 app assignments
- GIVEN the user `servicioalcliente.tis@gmail.com` exists with id=4
- WHEN the seeder runs
- THEN `SELECT COUNT(*) FROM usuario_app WHERE usuario_id = 4` returns 2
- AND the assignments cover app slugs `crm` and `brp`
- AND `rol_id` references the role with `slug = 'operativo'`

#### Scenario: Jaime has exactly 2 app assignments
- GIVEN the user `direccion.tis@gmail.com` exists with id=3
- WHEN the seeder runs
- THEN `SELECT COUNT(*) FROM usuario_app WHERE usuario_id = 3` returns 2
- AND the assignments cover app slugs `crm` and `marketing`
- AND `rol_id` references `roles.id = 1` (super-admin role)

#### Scenario: Seed uses real user IDs (1, 3, 4, 5), not PRD literals
- GIVEN the seeder runs
- WHEN querying `SELECT u.id, u.email FROM usuarios u JOIN usuario_app ua ON ua.usuario_id = u.id`
- THEN the result includes rows for users with ids 5, 1, 4, 3
- AND it does NOT try to insert rows for users with ids 2 (no canonical user with that id)

> **Note:** `id = 2` is `gestorcomercial.tis@gmail.com` (a 5th user) — the seeder does NOT touch this user.

#### Scenario: Seed is idempotent
- GIVEN the seed has already populated 11 rows
- WHEN `db:seed --class=UsuarioAppSeeder` runs again
- THEN zero new rows are inserted (because UNIQUE conflicts on (usuario_id, app_id))
- AND the seeder does not raise

#### Scenario: Seed skips soft-deleted users
- GIVEN user id=1 has `deleted_at IS NOT NULL`
- WHEN the seeder runs
- THEN no `usuario_app` rows are created with `usuario_id = 1`
- AND the seeder logs the skip

#### Scenario: Seed resolves roles by slug (not by id)
- GIVEN `roles.id = 5` is the `operativo` row in this DB instance
- WHEN the seeder runs Patricia's assignment
- THEN it computes `rol_id = SELECT id FROM roles WHERE slug = 'operativo'` (NOT a hardcoded `5`)
- AND a different DB instance with `operativo` at `id = 3` still gets Patricia correctly assigned

---

### REQ-USRAPP-3: Admin endpoint to list a user's assignments

`GET /api/v1/usuarios/{id}/apps` MUST return the list of all apps assigned to that user.

**Given** an authenticated admin caller (super-admin or with `rbac` permission for the route)
**When** `GET /api/v1/usuarios/{id}/apps` is invoked with a valid `id`
**Then** the response is 200 with `data` listing each assignment as `{app_id, app_slug, app_nombre, rol_id, rol_slug, rol_nombre}`

#### Scenario: Admin lists a user's apps
- GIVEN the caller is `admin@tecnoinnsoft.dev` (super-admin)
- AND Patricia (id=4) has 2 assignments
- WHEN `GET /api/v1/usuarios/4/apps` is called with the admin's Bearer token
- THEN the response is 200 with `success = true`
- AND `data` is an array of 2 entries
- AND each entry has `app_slug`, `rol_slug`, and `rol_id`

#### Scenario: Listing for a user with no assignments
- GIVEN an arbitrary user with zero `usuario_app` rows
- WHEN `GET /api/v1/usuarios/{id}/apps` is called
- THEN the response is 200 with `data: []` (empty array, NOT 404)

#### Scenario: Listing for a nonexistent user
- GIVEN no user with id=9999 exists
- WHEN `GET /api/v1/usuarios/9999/apps` is called
- THEN the response is 404 with `{success: false, error: "user_not_found"}`

#### Scenario: Non-admin caller is rejected
- GIVEN the caller is `lorena@test.com` (comercial role, not super-admin)
- WHEN `GET /api/v1/usuarios/4/apps` is called with Lorena's token
- THEN the response is 403 with `{success: false, error: "forbidden"}`

---

### REQ-USRAPP-4: Admin endpoint to assign an app+role

`POST /api/v1/usuarios/{id}/apps` MUST create a new `usuario_app` row for the given user.

Request body: `{ "app_slug": "...", "rol_slug": "..." }` (more API-friendly than raw IDs; the endpoint resolves the IDs via lookup).

**Given** an admin caller
**When** `POST /api/v1/usuarios/{id}/apps` is invoked with a valid body
**Then** the system creates a `usuario_app` row with the right `usuario_id`, `app_id`, `rol_id`
**And** the response is 201 with the new assignment data

#### Scenario: Admin assigns a new app+role
- GIVEN the caller is super-admin
- AND user id=1 (Lorena) currently has only the `crm` assignment
- WHEN `POST /api/v1/usuarios/1/apps` with body `{"app_slug": "marketing", "rol_slug": "comercial"}` is called
- THEN a new `usuario_app` row exists linking Lorena to `marketing`
- AND the response is 201 with `success = true` and the new row's data

#### Scenario: Assigning an already-assigned app returns 409
- GIVEN Lorena already has `crm`
- WHEN `POST /api/v1/usuarios/1/apps` with `{"app_slug": "crm", "rol_slug": "comercial"}` is called
- THEN the response is 409 with `error: "assignment_already_exists"`
- AND no second row is created

#### Scenario: Unknown app_slug returns 422
- GIVEN no app has `slug = 'unknown'`
- WHEN `POST /api/v1/usuarios/1/apps` with `{"app_slug": "unknown", "rol_slug": "comercial"}` is called
- THEN the response is 422 with a validation error referencing `app_slug`

#### Scenario: Unknown rol_slug returns 422
- GIVEN no role has `slug = 'unknown'`
- WHEN `POST /api/v1/usuarios/1/apps` with `{"app_slug": "crm", "rol_slug": "unknown"}` is called
- THEN the response is 422 with a validation error referencing `rol_slug`

#### Scenario: Non-admin caller is rejected
- GIVEN the caller is `lorena@test.com` (comercial, not super-admin)
- WHEN `POST /api/v1/usuarios/1/apps` is called with Lorena's token
- THEN the response is 403

#### Scenario: Assignment for nonexistent user returns 404
- GIVEN no user with id=9999 exists
- WHEN `POST /api/v1/usuarios/9999/apps` is called
- THEN the response is 404

#### Scenario: Missing body fields returns 422
- WHEN `POST /api/v1/usuarios/1/apps` is called with `{}` (empty body)
- THEN the response is 422 with validation errors for missing `app_slug` and `rol_slug`

---

### REQ-USRAPP-5: Admin endpoint to revoke an app

`DELETE /api/v1/usuarios/{id}/apps/{app_id}` MUST remove the matching `usuario_app` row.

**Given** an admin caller
**When** `DELETE /api/v1/usuarios/{id}/apps/{app_id}` is invoked with a valid `(id, app_id)` pair that has an existing assignment
**Then** the response is 200 with `success = true` (or 204 No Content)
**And** the matching row in `usuario_app` is gone
**And** no other rows are affected

#### Scenario: Admin revokes an existing assignment
- GIVEN Patricia has `usuario_app` row (id=4, app_id=6, rol_id=3) for `brp`
- WHEN `DELETE /api/v1/usuarios/4/apps/6` is called
- THEN the row is deleted
- AND subsequent `GET /usuarios/4/apps` returns only her `crm` assignment

#### Scenario: Revoking nonexistent assignment returns 404
- GIVEN no `usuario_app` row exists for (id=4, app_id=99)
- WHEN `DELETE /api/v1/usuarios/4/apps/99` is called
- THEN the response is 404 with `error: "assignment_not_found"`

#### Scenario: Non-admin caller is rejected
- GIVEN the caller is `lorena@test.com` (not super-admin)
- WHEN `DELETE /api/v1/usuarios/4/apps/6` is called with Lorena's token
- THEN the response is 403

#### Scenario: Revoke is reversible by re-assign
- GIVEN Patricia had `brp` revoked
- WHEN the admin then calls `POST /usuarios/4/apps` with `{"app_slug": "brp", "rol_slug": "operativo"}`
- THEN a new row is created
- AND it has the same effective state as before the revoke

---

### REQ-USRAPP-6: Super-admin bypass — any user with `es_super_admin=TRUE` may access any app

The system MUST grant implicit access to ALL apps (regardless of `usuario_app` rows) to a user whose `rol.es_super_admin = TRUE`. This bypass applies at the read endpoints (`/me/apps`, login response, `validate-token`) and at the `has-app:*` middleware check.

**Given** a user with `roles.es_super_admin = TRUE`
**When** any endpoint that lists user apps is queried
**Then** the response includes ALL active apps from the `apps` table
**And** no `usuario_app` rows need to exist for that user

#### Scenario: Super-admin sees all apps with zero pivot rows
- GIVEN a user with `roles.es_super_admin = TRUE`
- AND `usuario_app` is empty for that user
- WHEN `GET /api/v1/me/apps` is called with that user's token
- THEN the response's `data.apps[]` contains 6 entries (one per app in the catalog, filtered by `activo = TRUE`)
- AND each entry has a default `rol` derived from the user's role (`super-admin`)

#### Scenario: Super-admin bypass works in login response
- GIVEN a super-admin user with no `usuario_app` rows
- WHEN `POST /auth/login` is called with their credentials
- THEN `data.apps[]` still returns all active apps
- AND the bypass is transparent to the API consumer

#### Scenario: Super-admin bypass works in validate-token
- GIVEN a super-admin user with no `usuario_app` rows
- WHEN `GET /auth/validate-token` is called with their Bearer token
- THEN `data.apps[]` returns all active apps
- AND `data.permisos` returns the role's permissions

#### Scenario: Non-super-admin does NOT get the bypass
- GIVEN a user whose role has `es_super_admin = FALSE`
- AND `usuario_app` is empty for that user
- WHEN `GET /api/v1/me/apps` is called
- THEN `data.apps[]` returns `[]` (the empty pivot is honored, NOT bypassed)

#### Scenario: Super-admin bypass respects `activo = FALSE`
- GIVEN a super-admin user
- AND the `brp` app has `activo = FALSE`
- WHEN `GET /api/v1/me/apps` is called
- THEN `data.apps[]` contains 5 apps (not 6) — inactive `brp` is filtered out
- AND other filtering still applies (super-admin sees all active apps)

#### Scenario: Super-admin bypass ignores inactive pivot rows
- GIVEN a super-admin user has rows in `usuario_app` for inactive apps
- WHEN `GET /api/v1/me/apps` is called
- THEN the inactive app is still NOT shown (the bypass unions with active-app logic, doesn't override)

---

### REQ-USRAPP-7: Authorization on admin endpoints

The system MUST require super-admin role (`roles.es_super_admin = TRUE`) for ALL of:
- `GET /usuarios/{id}/apps`
- `POST /usuarios/{id}/apps`
- `DELETE /usuarios/{id}/apps/{app_id}`

Non-super-admin callers (including regular `comercial`, `operativo`, `brp-admin`) MUST receive 403.

**Given** the caller is logged in but not super-admin
**When** any of the three admin endpoints is invoked
**Then** the response is 403 with `error: "forbidden"` and a clear message

#### Scenario: Comercial role cannot list other users' apps
- GIVEN Lorena is `comercial` (not super-admin)
- WHEN `GET /usuarios/4/apps` is called with Lorena's token
- THEN response is 403

#### Scenario: Operativo role cannot assign new apps
- GIVEN Patricia is `operativo` (not super-admin)
- WHEN `POST /usuarios/1/apps` is called with Patricia's token
- THEN response is 403

#### Scenario: Super-admin can perform all admin operations
- GIVEN Vos is super-admin
- WHEN `POST /usuarios/1/apps` is called with Vos's token
- THEN the operation succeeds (regardless of the caller's role on the target user)

#### Scenario: Unauthenticated caller is rejected
- GIVEN no Bearer token is supplied
- WHEN any admin endpoint is called
- THEN response is 401
