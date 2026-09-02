# Spec: Me Endpoints (`/me`, `/me/apps`, `/me/apps/{slug}/permisos`)

## Purpose

Define the three `/me/*` endpoints that let an authenticated user inspect their own identity and app authorizations. These endpoints provide:

1. `GET /me` — full user profile (including apps)
2. `GET /me/apps` — just the apps list (lightweight check)
3. `GET /me/apps/{slug}/permisos` — granular permissions for one app

All three require a Sanctum Bearer token. The `EnsureUserHasApp` middleware MAY be attached (via `has-app:{slug}` route param) to gate `/me/apps/{slug}/permisos` access — users without access to that app receive 403.

---

## Requirements

### REQ-ME-1: `GET /api/v1/me` returns the authenticated user's data

The endpoint MUST return the user's profile (id, email, names, role, AND apps).

**Given** a valid Sanctum token
**When** `GET /api/v1/me` is called
**Then** the response is HTTP 200 with `success: true`
**And** `data.usuario` is the user's profile
**And** `data.apps` mirrors the same array as login response

#### Scenario: Vos gets full profile with 6 apps
- GIVEN Vos (super-admin) is authenticated
- WHEN `GET /api/v1/me` is called with his Bearer token
- THEN `data.usuario.id === 5`
- AND `data.usuario.email === 'admin@tecnoinnsoft.dev'`
- AND `data.apps` has 6 entries

#### Scenario: Lorena gets 1 app
- GIVEN Lorena is authenticated
- WHEN `GET /api/v1/me` is called
- THEN `data.apps` has 1 entry (crm)

#### Scenario: Patricia gets 2 apps
- GIVEN Patricia is authenticated
- WHEN `GET /api/v1/me` is called
- THEN `data.apps` has 2 entries (crm, brp)

#### Scenario: Jaime gets 2 apps (with super-admin role)
- GIVEN Jaime is authenticated with `crm` + `marketing` assignments
- WHEN `GET /api/v1/me` is called
- THEN `data.apps` has 2 entries
- AND both have `rol === 'super-admin'`

#### Scenario: Super-admin with no pivot rows still gets all apps
- GIVEN a user with `es_super_admin = TRUE` and ZERO `usuario_app` rows
- WHEN `GET /api/v1/me` is called
- THEN `data.apps` still returns all 6 active apps (per REQ-USRAPP-6)

#### Scenario: User with zero apps returns empty array
- GIVEN a user with zero `usuario_app` rows and non-super-admin role
- WHEN `GET /api/v1/me` is called
- THEN `data.apps === []`

#### Scenario: Token from another user is rejected
- GIVEN a stolen or copied token from another user
- WHEN `GET /api/v1/me` is called
- THEN `data.usuario.id` matches the TOKEN's user, NOT the request IP / device

#### Scenario: Inactive user still returns 401 (cannot hit /me)
- GIVEN the token's user has `estado = 'Inactivo'`
- WHEN `GET /api/v1/me` is called
- THEN response is 401 (matching Sanctum's default behavior)

---

### REQ-ME-2: Unauthenticated `/me` returns 401

The system MUST require a Sanctum token for `/me`. No token = 401.

**Given** no Bearer token in the request
**When** `GET /api/v1/me` is called
**Then** the response is HTTP 401

#### Scenario: Missing Authorization header
- GIVEN no `Authorization` header
- WHEN `GET /api/v1/me` is called
- THEN response is 401

#### Scenario: Bogus token
- GIVEN `Authorization: Bearer fake-token-abc`
- WHEN `GET /api/v1/me` is called
- THEN response is 401

#### Scenario: Expired token returns 401
- GIVEN a token with `expires_at < NOW()`
- WHEN `GET /api/v1/me` is called
- THEN response is 401

---

### REQ-ME-3: `GET /api/v1/me/apps` returns only the apps list

The endpoint is a lightweight variant of `/me` returning only the `apps[]` array.

**Given** a valid Sanctum token
**When** `GET /api/v1/me/apps` is called
**Then** the response is HTTP 200 with `success: true`
**And** `data.apps[]` is the user's apps
**And** other fields (id, email, role) are NOT required in the response

#### Scenario: Patricia gets {crm, brp}
- GIVEN Patricia is authenticated
- WHEN `GET /api/v1/me/apps` is called
- THEN `data.apps` has 2 entries with slugs `crm` and `brp`

#### Scenario: Lorena gets {crm} only
- GIVEN Lorena is authenticated
- WHEN `GET /api/v1/me/apps` is called
- THEN `data.apps` has exactly 1 entry with slug `crm`

#### Scenario: User with no apps gets empty list
- GIVEN a user with zero apps and non-super-admin
- WHEN `GET /api/v1/me/apps` is called
- THEN response is 200 with `data.apps === []`

#### Scenario: Response shape is consistent with login
- GIVEN a fresh login for Patricia
- WHEN comparing `data.apps` from login vs `data.apps` from `/me/apps`
- THEN the contents are identical (same array entries, same `rol` fields)
- AND the apps are sorted in a stable order (by slug or app id)

#### Scenario: Super-admin sees all apps
- GIVEN Vos is super-admin
- WHEN `GET /api/v1/me/apps` is called
- THEN `data.apps` has 6 entries (same as login response)

#### Scenario: Inactive apps filtered out
- GIVEN Patricia has assignments to `crm` (active) and `brp` (inactive)
- WHEN `GET /api/v1/me/apps` is called
- THEN `data.apps` contains only `crm`

#### Scenario: Endpoint does NOT require EnsureUserHasApp middleware
- GIVEN the `/me/apps` route
- WHEN inspecting the middleware list
- THEN it does NOT include `has-app:*` (no app-scoped check; the user sees their own list)
- AND Sanctum is the only auth middleware

---

### REQ-ME-4: `GET /api/v1/me/apps/{slug}/permisos` returns granular permissions

The endpoint MUST return the user's permissions for the app identified by `{slug}`.

**Given** a valid token
**And** the user has access to the app with `slug = {slug}` (or is super-admin)
**When** `GET /api/v1/me/apps/{slug}/permisos` is called
**Then** the response is HTTP 200 with `success: true`
**And** `data.permisos` is an array of permission strings
**And** `data.app` describes the app (`slug`, `nombre`, `rol`)

#### Scenario: Patricia asks for brp permisos
- GIVEN Patricia is authenticated
- AND she has a `usuario_app` row linking her to `brp`
- WHEN `GET /api/v1/me/apps/brp/permisos` is called
- THEN response is 200
- AND `data.app.slug === 'brp'`
- AND `data.app.rol === 'operativo'` (her role on brp)
- AND `data.permisos` is an array of permission strings (e.g., `brp.sesiones.marcar`, `brp.asistencias.registrar`)

#### Scenario: Lorena asks for crm permisos
- GIVEN Lorena is authenticated with `crm` role `comercial`
- WHEN `GET /api/v1/me/apps/crm/permisos` is called
- THEN response is 200
- AND `data.app.slug === 'crm'`
- AND `data.app.rol === 'comercial'`
- AND `data.permisos` reflects comercial permissions

#### Scenario: User without access to app returns 403
- GIVEN Lorena is authenticated (only has `crm`)
- WHEN `GET /api/v1/me/apps/brp/permisos` is called
- THEN response is 403 with `error: "forbidden"` and a message indicating she has no access to `brp`

#### Scenario: Unknown app slug returns 404
- GIVEN no app exists with `slug = 'unknown'`
- WHEN `GET /api/v1/me/apps/unknown/permisos` is called
- THEN response is 404 with `error: "app_not_found"`

#### Scenario: Inactive app returns 403
- GIVEN Patricia has assignment to `brp` but `brp.activo = FALSE`
- WHEN `GET /api/v1/me/apps/brp/permisos` is called
- THEN response is 403 (inactive apps are treated as inaccessible)

#### Scenario: Super-admin bypass on permisos endpoint
- GIVEN Vos is super-admin and has zero `usuario_app` rows
- WHEN `GET /api/v1/me/apps/crm/permisos` is called
- THEN response is 200 (super-admin bypass grants access)
- AND `data.permisos` reflects his role's permissions (all of them)

#### Scenario: Permisos array contains the union of permissions across the user's roles
- GIVEN Patricia has 2 roles for the crm app: `comercial` and `operativo` (hypothetical multi-role scenario)
- WHEN `GET /api/v1/me/apps/crm/permisos` is called
- THEN `data.permisos` is the deduplicated union of both roles' permissions
- AND duplicates are removed

#### Scenario: User with no permisos rows for an app returns empty array
- GIVEN Lorena's `comercial` role has zero permission rows in `permisos` table
- WHEN `GET /api/v1/me/apps/crm/permisos` is called
- THEN `data.permisos === []`

---

### REQ-ME-5: Middleware `EnsureUserHasApp` enforces the 403 above

The system MUST register `EnsureUserHasAppMiddleware` with alias `has-app` (or `has-app:slug`) so it can be applied via route parameters.

**Given** a route `GET /me/apps/{slug}/permisos` with `->middleware('has-app:{slug}')`
**When** the request enters the pipeline
**Then** the middleware checks `Auth::user()->hasApp($slug)` (or equivalent)
**And** allows the request if the user has the app or is super-admin
**And** returns 403 if neither

#### Scenario: EnsureUserHasApp allows when user has the app
- GIVEN Patricia has `crm` access
- WHEN a request enters the route with `has-app:crm` middleware
- THEN the middleware calls `next()`
- AND the controller runs

#### Scenario: EnsureUserHasApp denies 403 when user lacks the app
- GIVEN Lorena has only `crm`
- WHEN a request enters the route with `has-app:brp` middleware
- THEN the middleware short-circuits with 403
- AND the controller's logic is NOT executed

#### Scenario: EnsureUserHasApp allows for super-admin even without app assignment
- GIVEN a super-admin user with NO `usuario_app` row for the requested app
- WHEN the middleware runs
- THEN the middleware calls `next()` (super-admin bypass)
- AND the controller runs

#### Scenario: EnsureUserHasApp handles missing slug param
- GIVEN a route uses the middleware without a parameter
- WHEN the middleware fires
- THEN either (a) it returns 500 with a clear config error, OR (b) it gracefully returns 403
- AND the failure mode is logged for debugging

> Implementation detail: middleware MUST receive `{slug}` from route-param binding. The route definition is responsible for declaring the param.

#### Scenario: EnsureUserHasApp is registered in bootstrap/app.php
- GIVEN the middleware alias system in Laravel 12
- WHEN `bootstrap/app.php` is inspected
- THEN the alias `has-app` is registered to `EnsureUserHasAppMiddleware::class`
- AND it can be applied via `->middleware('has-app:slug')` in route definitions

#### Scenario: The middleware uses the cached apps list (no extra DB call)
- GIVEN the user's apps are loaded already (e.g., from `/me` or `/me/apps`)
- WHEN the middleware checks `hasApp($slug)`
- THEN it does not re-query the DB on each request
- AND if needed, uses an in-request attribute or cached value

> This is an optimization. Even if the middleware runs its own DB query, it MUST be in the same transaction/request scope, NOT a separate round-trip with a noticeable delay.

---

### REQ-ME-6: Endpoint response includes `data.usuario.rol` (the user's CRM role)

The `/me` endpoint MUST include `data.usuario.rol` so the consumer can render CRM-side UI controls.

**Given** a user logs in
**When** `/me` is called
**Then** `data.usuario.rol` is the role object (at minimum `id`, `slug`, `nombre`)

#### Scenario: User has a rol with slug
- GIVEN Vos has `roles.es_super_admin = TRUE`
- WHEN `GET /api/v1/me` is called
- THEN `data.usuario.rol.slug === 'super-admin'`

#### Scenario: User has a rol without slug (legacy state)
- GIVEN a rol has `slug IS NULL` (legacy scenario before pre-migration PM-1 runs)
- WHEN `GET /api/v1/me` is called
- THEN `data.usuario.rol.slug === null` (no exception raised)
- AND `data.usuario.rol.id` is still populated

#### Scenario: rol.es_super_admin flag is exposed
- WHEN `/me` is called
- THEN `data.usuario.rol.es_super_admin` is `true|false`

---

### REQ-ME-7: `/me` is idempotent and cache-friendly

The `/me` and `/me/apps` endpoints MUST be idempotent: the same call with the same token returns the same data within a short window (no DB writes, no side effects).

**Given** two consecutive calls
**When** they are made
**Then** both return the same `data` payload
**And** no DB writes happen (no `created_at` updates on the user, no token rotation)

#### Scenario: Two calls in a row return identical data
- GIVEN Patricia is authenticated
- WHEN `GET /api/v1/me` is called at T=0
- AND `GET /api/v1/me` is called at T=10s
- THEN both responses have `success: true` and the same `data.usuario.id`
- AND the response time is comparable (no N+1 issues)

#### Scenario: No accidental writes
- GIVEN Patricia is authenticated
- WHEN `GET /api/v1/me` is called
- AND the `usuarios.updated_at` column is checked BEFORE and AFTER
- THEN the value is unchanged (no row updated)

---

### REQ-ME-8: Endpoint is part of `/api/v1/` and uses sanctum middleware

The routes for `/me` MUST be defined in `routes/api.php` under the prefix `/api/v1/`.

**Given** the route file is loaded
**When** inspecting
**Then** the routes are:
- `GET /api/v1/me` → `MeController@show` (or equivalent)
- `GET /api/v1/me/apps` → `MeController@apps`
- `GET /api/v1/me/apps/{slug}/permisos` → `MeController@permisos` (with `has-app:{slug}` middleware)
- Each with `auth:sanctum` (or equivalent) middleware
- The `permisos` route additionally has `has-app:{slug}` middleware
- All routes are throttled (e.g., `throttle:auth` or default API throttle)

#### Scenario: Route definitions exist
- GIVEN `routes/api.php` is parsed
- WHEN grepping for `/me/apps`
- THEN three routes match: `/me`, `/me/apps`, `/me/apps/{slug}/permisos`
- AND they are inside the API prefix group

#### Scenario: Auth middleware is applied
- GIVEN a request without `Authorization` header
- WHEN ANY `/me/*` route is hit
- THEN the response is 401 (sanctum middleware fires)

#### Scenario: has-app middleware fires before the controller
- GIVEN `has-app:brp` is applied to `/me/apps/brp/permisos`
- WHEN Lorena (no brp) hits the route
- THEN response is 403
- AND the controller's body is NOT executed
- AND no DB query for permisos is made (the 403 short-circuits)

---

### REQ-ME-9: Response payload does not leak unnecessary fields

The `/me` response MUST NOT include:
- `password_hash`
- `personal_access_tokens` list
- Any internal column that the user should not see (e.g., secret tokens, audit-only fields)

**Given** a `/me` response is inspected
**When** looking for sensitive fields
**Then** none of the above are present

#### Scenario: No password_hash in /me
- GIVEN Patricia is authenticated
- WHEN `/me` is called
- THEN `data.usuario` does NOT have `password_hash` key
- AND no other sensitive fields are exposed

#### Scenario: No tokens list in /me
- GIVEN Patricia has multiple Sanctum tokens
- WHEN `/me` is called
- THEN the response does NOT list all of Patricia's tokens
- AND only the issued token's information (e.g., a count) MAY be included — not the actual token values
