# Spec: Modified Login Endpoint (`POST /api/v1/auth/login`)

## Purpose

Modify the existing `POST /api/v1/auth/login` endpoint so that the response includes the list of apps assigned to the authenticated user. Before this change, the response carried only `{ token, usuario }`. After the change, it carries `{ token, usuario, apps[] }` — letting consumers render the post-login landing (CRM dashboard vs BRP dashboard vs Marketing dashboard) without an extra round-trip to `/me/apps`.

This is the entry point for the "one login, N apps" multi-app experience. Login still uses Sanctum token issuance; the only change is the response payload.

---

## Requirements

### REQ-LOGIN-1: Login response now includes apps

The system MUST include a `data.apps` field in the successful login response.

Successful login (HTTP 200) response shape:

```json
{
  "success": true,
  "data": {
    "token": "1|abc123def456...",
    "usuario": {
      "id": 5,
      "email": "admin@tecnoinnsoft.dev",
      "nombres": "Admin",
      "apellidos": "User"
    },
    "apps": [
      { "slug": "crm",       "nombre": "CRM Tecnoinnsoft",  "rol": "super-admin",  "rol_id": 1 },
      { "slug": "sailus",    "nombre": "SAIlus Gateway",    "rol": "super-admin",  "rol_id": 1 },
      { "slug": "marketing", "nombre": "Marketing Manager", "rol": "super-admin",  "rol_id": 1 },
      { "slug": "wp-plugin", "nombre": "Plugin WordPress",  "rol": "super-admin",  "rol_id": 1 },
      { "slug": "la-llave",  "nombre": "La Llave Documental","rol": "super-admin","rol_id": 1 },
      { "slug": "brp",       "nombre": "BRP Asistencia",    "rol": "super-admin",  "rol_id": 1 }
    ]
  }
}
```

**Given** an active user with valid credentials
**When** `POST /api/v1/auth/login` is called with correct email and password
**Then** the response is HTTP 200
**And** `success === true`
**And** `data.token` is a Sanctum plain-text token (format `id|hash`)
**And** `data.usuario` carries at least `{id, email, nombres, apellidos}`
**And** `data.apps` is an array of app assignments

#### Scenario: Vos (admin, super-admin) returns 6 apps
- GIVEN `admin@tecnoinnsoft.dev` exists with the password `password123`
- AND Vos has 6 `usuario_app` rows (one per app)
- WHEN `POST /api/v1/auth/login` is called with valid credentials
- THEN response is 200
- AND `data.apps` has exactly 6 entries
- AND the 6 distinct slugs are: `crm`, `sailus`, `marketing`, `wp-plugin`, `la-llave`, `brp`
- AND each entry has `rol_slug = 'super-admin'` (or equivalent)

#### Scenario: Lorena returns 1 app (crm only)
- GIVEN Lorena exists with valid credentials
- AND Lorena has 1 `usuario_app` row linking her to `crm` with `comercial` role
- WHEN `POST /api/v1/auth/login` is called
- THEN `data.apps` has exactly 1 entry
- AND that entry has `slug = 'crm'`, `rol = 'comercial'`

#### Scenario: Patricia returns 2 apps (crm + brp)
- GIVEN Patricia exists with valid credentials
- AND Patricia has 2 `usuario_app` rows (crm + brp) with `operativo` role
- WHEN `POST /api/v1/auth/login` is called
- THEN `data.apps` has exactly 2 entries
- AND the slugs are `crm` and `brp`
- AND both have the same `rol = 'operativo'`

#### Scenario: Jaime returns 2 apps (crm + marketing, super-admin)
- GIVEN Jaime exists with valid credentials
- AND Jaime has 2 `usuario_app` rows for crm + marketing with `super-admin` role
- WHEN `POST /api/v1/auth/login` is called
- THEN `data.apps` has exactly 2 entries
- AND the slugs are `crm` and `marketing`

#### Scenario: Token format unchanged
- GIVEN a successful login
- WHEN inspecting `data.token`
- THEN the format is the same Sanctum `id|hash` plain-text token
- AND it can be reused on the very next request via `Authorization: Bearer <token>`

#### Scenario: The same response is returned via `/auth/login`
- GIVEN the route `POST /api/v1/auth/login`
- WHEN called
- THEN the handler is the same `AuthController@login`
- AND the response body matches the documented shape

---

### REQ-LOGIN-2: Apps include `rol` slug/name and `rol_id`

Each entry in `data.apps[]` MUST carry enough information for the consumer to render UI (which app icon, which role badge) without a second round-trip.

Required keys: `slug`, `nombre`, `rol` (rol slug OR rol nombre), `rol_id`.

**Given** a user with assignments
**When** login succeeds
**Then** each app entry has `slug`, `nombre`, `rol`, and `rol_id` populated

#### Scenario: Apps entry has all required fields
- GIVEN Lorena has 1 assignment with role `comercial` (id=2, slug='comercial')
- WHEN login succeeds
- THEN `data.apps[0]` contains:
  - `slug`: `"crm"`
  - `nombre`: `"CRM Tecnoinnsoft"`
  - `rol`: `"comercial"` (the role's slug OR name)
  - `rol_id`: `2`

#### Scenario: Apps entries are deduplicated by app_id
- GIVEN a user with 2 assignments to the same app (should not happen due to UNIQUE, but defensively)
- WHEN login succeeds
- THEN `data.apps` contains each app at most once
- AND no duplicate `slug` values

---

### REQ-LOGIN-3: Apps list honors activo = FALSE filter

An app with `activo = FALSE` MUST NOT appear in the login response, even if the user has a `usuario_app` row to it.

**Given** a user has assignments to active AND inactive apps
**When** login succeeds
**Then** `data.apps` only contains entries for `activo = TRUE` apps

#### Scenario: Inactive app is excluded from login response
- GIVEN Patricia has assignments to `crm` (active) and `brp` (inactive)
- WHEN login succeeds
- THEN `data.apps` contains only the `crm` entry
- AND no `brp` entry

#### Scenario: Super-admin bypass respects activo flag
- GIVEN Vos has super-admin and `brp` is inactive
- WHEN login succeeds
- THEN `data.apps` contains 5 entries (the 6 apps minus the inactive `brp`)

#### Scenario: All apps inactive → empty apps
- GIVEN Patricia's apps are all `activo = FALSE`
- WHEN login succeeds
- THEN `data.apps === []`

---

### REQ-LOGIN-4: Invalid credentials return 401 with no `apps` field

The system MUST continue to return HTTP 401 for invalid credentials, and MUST NOT include any `apps` field in the error response (to avoid leaking app existence).

**Given** an invalid email/password combination
**When** `POST /api/v1/auth/login` is called
**Then** the response is HTTP 401
**And** `success === false`
**And** no `data.apps` is included

#### Scenario: Wrong password returns 401
- GIVEN `admin@tecnoinnsoft.dev` exists
- WHEN `POST /auth/login` is called with that email and wrong password
- THEN response is 401
- AND no `apps` field is leaked

#### Scenario: Unknown email returns 401 (no enumeration)
- GIVEN no user with `notexist@x.com`
- WHEN `POST /auth/login` is called with that email and any password
- THEN response is 401
- AND the response time is comparable to a wrong-password scenario (constant time)

#### Scenario: Inactive user (estado = Inactivo) returns 401
- GIVEN a user exists with `estado = 'Inactivo'`
- WHEN `POST /auth/login` is called with their correct credentials
- THEN response is 401
- AND the user is treated as not-logged-in

#### Scenario: Missing fields return 422
- GIVEN the request body is `{}`
- WHEN `POST /auth/login` is called
- THEN response is 422
- AND validation errors mention `email` and `password`

---

### REQ-LOGIN-5: Login still issues a valid Sanctum token (no regression)

The system MUST issue a Sanctum token usable on the very next request, identical to the pre-change behavior.

**Given** a user logs in successfully
**When** they call `GET /api/v1/me` with the issued token in `Authorization: Bearer`
**Then** the request is authenticated
**And** the user record is the same that was returned in `data.usuario`

#### Scenario: Token issued can immediately call /me
- GIVEN a successful login
- WHEN the issued token is used to call `GET /api/v1/me`
- THEN response is 200 with the user's data
- AND `data.usuario.id` matches `data.usuario.id` from the login response

#### Scenario: Existing login test suite still passes
- GIVEN the existing `tests/Feature/API/AuthTest.php` test for login
- WHEN `php artisan test --filter AuthTest` runs after the change
- THEN all pre-existing assertions still pass
- AND only one NEW assertion is needed: `$response->assertJsonStructure(['data' => ['token', 'usuario', 'apps']])`

#### Scenario: Multiple devices / repeated logins issue independent tokens
- GIVEN a user logs in twice from two different devices
- WHEN both tokens are used on subsequent requests
- THEN both are independently valid
- AND both appear in `personal_access_tokens`

---

### REQ-LOGIN-6: Rate limiting on login is preserved

The system MUST retain the `throttle:auth` middleware on the login route. Existing rate-limit behavior is unchanged.

**Given** the route definition
**When** inspecting `routes/api.php`
**Then** the login route is wrapped with `throttle:auth` middleware

#### Scenario: 6th failed login within a minute returns 429
- GIVEN the rate limit is, e.g., 5 attempts/minute per IP
- WHEN a 6th login attempt is made in 60 seconds
- THEN response is 429
- AND the consumer can retry after the rate-limit window

> The exact limit is a deployment choice; what matters is that the `throttle:auth` group still applies.

---

### REQ-LOGIN-7: Empty password or empty email returns 422 (validation)

The system MUST validate the request body before consulting the DB. Empty `email` or missing `password` returns HTTP 422.

**Given** an incomplete body
**When** `POST /auth/login` is called
**Then** response is 422 with validation errors

#### Scenario: Email missing returns 422
- GIVEN `POST /auth/login` with `{ "password": "x" }` (no email)
- WHEN called
- THEN response is 422 with `errors.email` populated

#### Scenario: Password missing returns 422
- GIVEN `POST /auth/login` with `{ "email": "x@y.com" }` (no password)
- WHEN called
- THEN response is 422 with `errors.password` populated

#### Scenario: Password is a string, not array
- GIVEN `POST /auth/login` with `{ "email": "x@y.com", "password": ["x"] }`
- WHEN called
- THEN the FormRequest validates `password` is a string
- AND response is 422 if the type is wrong

#### Scenario: Email is well-formed
- GIVEN `POST /auth/login` with `{ "email": "not-an-email", "password": "x" }`
- WHEN called
- THEN response is 422 (email format invalid)

---

### REQ-LOGIN-8: Response includes explicit `apps` count for debugging (no PII leak)

The response MUST NOT include sensitive data (password hashes, tokens other than the issued one, internal IDs not documented in the contract). The `apps` array MUST NOT include `personal_access_tokens` or any other user-secret.

**Given** a successful login
**When** the response body is inspected
**Then** no fields outside the documented contract are present

#### Scenario: No password_hash in response
- GIVEN a successful login
- WHEN inspecting the response
- THEN `data.usuario` does NOT include `password_hash`
- AND `data` does NOT include other users' passwords

#### Scenario: No personal_access_tokens leaked
- GIVEN a successful login
- WHEN inspecting the response
- THEN `data` does NOT include `personal_access_tokens`
- AND `data.usuario` does NOT include `tokens`

---

### REQ-LOGIN-9: User data carries the names of the user (not just email)

The login response MUST include at least `nombres` and `apellidos` so the consumer can greet the user without a follow-up call.

**Given** a user logs in
**When** the response is parsed
**Then** `data.usuario.nombres` and `data.usuario.apellidos` are present and non-empty (unless the underlying user record has them NULL — edge case below)

#### Scenario: Names are populated
- GIVEN Lorena exists with `nombres='Lorena'`, `apellidos='Pérez'`
- WHEN login succeeds
- THEN `data.usuario.nombres === 'Lorena'`
- AND `data.usuario.apellidos === 'Pérez'`

#### Scenario: User with NULL nombres (edge)
- GIVEN a user exists with `nombres IS NULL`
- WHEN login succeeds
- THEN response is still 200 (login logic does NOT validate names)
- AND `data.usuario.nombres === null` (or empty string `""` — implementation choice)
- AND no 500 error is raised

---

### REQ-LOGIN-10: Backend contract is observable for BRP integration

The login endpoint MUST work with any HTTP client (curl, Postman, FastAPI, etc.). No SPA-specific assumptions.

**Given** a curl command
**When** `curl -X POST https://crm/api/v1/auth/login -H 'Content-Type: application/json' -d '{"email":"x","password":"y"}'` is run
**Then** the response is JSON
**And** CORS allows external origins (BRP backend domain)

#### Scenario: JSON content type in request
- GIVEN `Content-Type: application/json`
- WHEN `POST /auth/login` is called
- THEN Laravel recognizes the JSON body and parses it
- AND validation rules are applied to the parsed JSON

#### Scenario: Form-encoded body is also accepted (defensive)
- GIVEN `Content-Type: application/x-www-form-urlencoded`
- WHEN `POST /auth/login` is called
- THEN the body is parsed
- AND behavior is the same as JSON
- AND the FormRequest handles both

> Defensive: this lets BRP integrate with a standard form-encoded POST as well as JSON. Not strictly required for v1 — noted as a robustness scenario.
