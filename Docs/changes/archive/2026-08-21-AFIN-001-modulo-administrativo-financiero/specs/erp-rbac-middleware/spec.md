# Spec: erp-rbac-middleware

**Capability**: erp-rbac-middleware
**Change**: AFIN-001-modulo-administrativo-financiero
**Status**: Draft
**Date**: 2026-08-19

## Purpose

Provee el middleware `erp.auth` (alias registrado en `bootstrap/app.php`) que actúa como muro de **separación ERP/CRM** (AC05): valida que el usuario autenticado vía Sanctum tenga un `rol_id` dentro de la lista permitida configurable, y devuelve `403 Forbidden` en cualquier intento cross-access. La lista de roles permitidos se lee de `config('erp.allowed_roles')` con env override (`ERP_ALLOWED_ROL_IDS`), permitiendo modificar el control de acceso sin redeploy. Cada rechazo se audita con un log estructurado.

## Requirements

### REQ-RBAC-001: Allow request when rol_id is in the allowed list

The system SHALL allow the request to proceed when the authenticated user's `rol_id` is contained in `config('erp.allowed_roles')` (default `[4, 5]`).

#### Scenarios

**Scenario: user with rol_id=4 passes**
  Given a `Usuario` with `rol_id=4` authenticated via Sanctum
  And `config('erp.allowed_roles') = [4, 5]`
  When any `erp.auth`-protected route is hit
  Then the request SHALL proceed with `200` (or whatever the controller returns)
  And no audit log entry SHALL be written

**Scenario: user with rol_id=5 passes**
  Given a `Usuario` with `rol_id=5` authenticated via Sanctum
  And `config('erp.allowed_roles') = [4, 5]`
  When any `erp.auth`-protected route is hit
  Then the request SHALL proceed with `200`

**Scenario: admin user with rol_id=1 is rejected (not in default allowed list)**
  Given a `Usuario` with `rol_id=1` authenticated via Sanctum
  And `config('erp.allowed_roles') = [4, 5]`
  When any `erp.auth`-protected route is hit
  Then the response SHALL be `403` with `{ success: false, error: "ERP access requires rol_id IN [4,5]" }`

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_allows_rol_4`
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_allows_rol_5`
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_rejects_rol_1`
- [ ] Endpoint: any `/api/v1/erp/*`

### REQ-RBAC-002: Reject with 403 when rol_id is not allowed (with audit log)

The system SHALL reject with HTTP `403` when `rol_id` is outside the allowed list, and MUST write a structured audit log entry (`Log::warning('erp.auth.rejected', [...])`) containing: `user_id`, `rol_id`, `path`, `method`, `ip`, `user_agent`, `timestamp`.

#### Scenarios

**Scenario: CRM user rol_id=3 is rejected and audited**
  Given a `Usuario` with `rol_id=3`, email `crm@tecnoinnsoft.dev`, authenticated via Sanctum
  When the client sends `GET /api/v1/erp/cuentas` from IP `192.168.1.42`
  Then the response SHALL be `403` with `{ success: false, error: "ERP access requires rol_id IN [4,5]" }`
  And a log entry SHALL exist with level=warning, channel=stack, message=`erp.auth.rejected`, and context containing `{ user_id, rol_id: 3, path: "api/v1/erp/cuentas", method: "GET", ip: "192.168.1.42", user_agent, timestamp }`

**Scenario: multiple rejections are logged independently**
  Given a `Usuario` with `rol_id=3` makes 3 cross-access attempts
  When each is rejected
  Then 3 distinct log entries SHALL be written (one per rejection)

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_403_includes_rol_id_in_error`
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_rejection_writes_audit_log`
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_multiple_rejections_logged`
- [ ] Log channel: `stack` (Laravel default), with `Log::warning()` level

### REQ-RBAC-003: Reject with 401 when no authenticated user

The system SHALL defer the unauthenticated case to Laravel's `auth:sanctum` middleware (returns `401`). The `erp.auth` middleware MUST run AFTER `auth:sanctum` so a request without a valid token never reaches `erp.auth`.

#### Scenarios

**Scenario: request without Authorization header returns 401**
  Given no `Authorization: Bearer` header
  When the client sends `GET /api/v1/erp/cuentas`
  Then the response SHALL be `401` (returned by `auth:sanctum` BEFORE `erp.auth` runs)
  And no `erp.auth.rejected` log entry SHALL be written (the user was never authenticated)

**Scenario: expired/invalid Sanctum token returns 401**
  Given an expired Sanctum token
  When the client sends `GET /api/v1/erp/cuentas`
  Then the response SHALL be `401`
  And no `erp.auth.rejected` log entry SHALL be written

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_unauthenticated_returns_401`
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_expired_token_returns_401_without_audit_log`
- [ ] Middleware ordering: `['auth:sanctum', 'erp.auth']` in route group

### REQ-RBAC-004: Configurable allowed_roles via config/erp.php + env

The system SHALL read the allowed-roles list from `config('erp.allowed_roles')` (an array of integers). The config file SHALL support an env override `ERP_ALLOWED_ROL_IDS` (comma-separated, e.g. `ERP_ALLOWED_ROL_IDS=4,5,7`). When env is unset, the config file MUST default to `[4, 5]`.

#### Scenarios

**Scenario: default allowed roles are [4, 5] when env is unset**
  Given `ERP_ALLOWED_ROL_IDS` is not set in `.env`
  And `config/erp.php` declares `allowed_roles` with default `[4, 5]`
  When `php artisan config:clear` and a request is processed
  Then `config('erp.allowed_roles')` SHALL equal `[4, 5]`

**Scenario: env overrides config file**
  Given `ERP_ALLOWED_ROL_IDS=4,5,7` in `.env`
  When `php artisan config:clear` and a request is processed
  Then `config('erp.allowed_roles')` SHALL equal `[4, 5, 7]`
  And a user with `rol_id=7` SHALL pass `erp.auth`

**Scenario: empty env value falls back to default**
  Given `ERP_ALLOWED_ROL_IDS=` (empty) in `.env`
  When `php artisan config:clear`
  Then `config('erp.allowed_roles')` SHALL equal `[4, 5]` (default)

**Scenario: malformed env value (non-numeric) raises config:cache error**
  Given `ERP_ALLOWED_ROL_IDS=abc,def` in `.env`
  When `php artisan config:cache` runs
  Then the command SHALL fail with `InvalidArgumentException` (caught at boot time, not request time)

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_default_allowed_roles_when_env_unset`
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_env_overrides_config`
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_empty_env_falls_back_to_default`
- [ ] Test: `tests/Unit/Config/ErpConfigTest.php::test_invalid_env_raises_at_config_cache`
- [ ] Config file: `config/erp.php` with `allowed_roles` array

### REQ-RBAC-005: Middleware registered with alias `erp.auth` in bootstrap/app.php

The system SHALL register the middleware class `App\Infrastructure\Auth\ErpAuthMiddleware` under the alias `erp.auth` in `bootstrap/app.php` so it can be referenced as a string in route definitions.

#### Scenarios

**Scenario: middleware alias is registered**
  Given `bootstrap/app.php` has `->withMiddleware(function (Middleware $middleware) { $middleware->alias(['erp.auth' => \App\Infrastructure\Auth\ErpAuthMiddleware::class]); })`
  When `php artisan route:list --columns=method,uri,middleware` is executed
  Then every `/api/v1/erp/*` route SHALL show `erp.auth` in the middleware column

**Scenario: middleware class can be invoked directly via container**
  Given the `ErpAuthMiddleware` is bound in the container
  When `app(\App\Infrastructure\Auth\ErpAuthMiddleware::class)` is resolved
  Then the instance SHALL be returned without error

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_middleware_alias_resolves`
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_routes_list_includes_erp_auth`
- [ ] File: `bootstrap/app.php` registers alias

### REQ-RBAC-006: All `/api/v1/erp/*` routes are protected by `erp.auth`

The system SHALL group all ERP endpoints under `Route::prefix('erp')->middleware(['auth:sanctum', 'erp.auth'])` in `routes/api.php`, so that adding a new ERP route inherits the middleware automatically.

#### Scenarios

**Scenario: new ERP route inherits erp.auth automatically**
  Given a new route `Route::get('test', ...)` added inside the `Route::prefix('erp')->middleware(...)` group
  When a request is sent to `/api/v1/erp/test`
  Then the request SHALL be subject to `auth:sanctum` and `erp.auth`

**Scenario: a route outside the prefix is NOT protected by erp.auth**
  Given `Route::get('api/v1/oportunidades', ...)` (no erp prefix)
  When a user with `rol_id=3` requests `/api/v1/oportunidades`
  Then the request SHALL succeed (no 403 from `erp.auth`)

#### Acceptance criteria
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_new_route_in_erp_group_inherits_middleware`
- [ ] Test: `tests/Feature/ERP/ErpRbacMiddlewareTest.php::test_non_erp_route_not_affected`

## Out of scope (this spec)
- Per-resource granular permissions (only role-list check; granular `erp_scope` JWT claim is forward-compat, not enforced in sprint 1)
- Rate limiting per role (covered by global `throttle-mutations`)
- Audit log persistence to a separate table (logs go to `storage/logs/laravel.log` via the `log` driver in dev)

## Dependencies
- Existing `auth:sanctum` middleware
- Existing `Usuario.rol_id` column
- `config/erp.php` file (created in this change)
- All other ERP capabilities depend on this middleware