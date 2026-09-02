# Spec: Validate Token Endpoint (`GET /api/v1/auth/validate-token`)

## Purpose

Define the new public endpoint `GET /api/v1/auth/validate-token` that allows external apps (BRP, La Llave, future tenants) to validate a Sanctum Bearer token and retrieve the user's identity, apps, and permissions. This endpoint is the Bearer-token counterpart to the existing `X-API-Key` endpoint `/auth/validate-key` (which is reserved for SAIlus bots). Caching with a 5-minute TTL keeps p95 latency under 100ms even with thousands of requests per second.

This is the contract that BRP integrates against (see PRD-BRP.md §7). The contract is frozen for v1.

---

## Requirements

### REQ-VALTOK-1: Endpoint shape — happy path

The system MUST expose `GET /api/v1/auth/validate-token` accepting a Sanctum Bearer token in the `Authorization` header.

Successful response (HTTP 200):

```json
{
  "success": true,
  "data": {
    "valid": true,
    "usuario_id": 42,
    "email": "ana@psicologos.com",
    "apps": [
      { "slug": "brp", "rol": "psicologo", "rol_id": 5 }
    ],
    "permisos": ["brp.sesiones.marcar"],
    "cached": false,
    "validated_at": "2026-07-30T14:23:45Z"
  }
}
```

**Given** a valid Sanctum Bearer token for a user with at least one `usuario_app` row
**When** `GET /api/v1/auth/validate-token` is called
**Then** the response is HTTP 200
**And** `success === true`
**And** `data.valid === true`
**And** `data.usuario_id` matches the token owner
**And** `data.email` matches the user's email
**And** `data.apps` is an array (possibly empty)
**And** `data.permisos` is an array of permission strings
**And** `data.cached` is `false` on first call (no cache yet)
**And** `data.validated_at` is an ISO-8601 UTC timestamp

#### Scenario: Token for a user with one app returns the right shape (Patricia → BRP)
- GIVEN Patricia (id=4) has 2 assignments (`crm` + `brp`)
- AND Patricia has a valid Sanctum token in the request header
- WHEN `GET /api/v1/auth/validate-token` is called
- THEN the response is 200 with `success: true`
- AND `data.usuario_id === 4`
- AND `data.email === 'servicioalcliente.tis@gmail.com'` (or equivalent)
- AND `data.apps` is an array containing entries for `crm` and `brp`
- AND `data.cached === false`
- AND `data.validated_at` is parseable as ISO-8601 UTC

#### Scenario: Token for admin returns 6 apps
- GIVEN Vos (id=5) is super-admin
- WHEN `GET /api/v1/auth/validate-token` is called with Vos's token
- THEN `data.usuario_id === 5`
- AND `data.apps` contains 6 entries (one per app in the catalog)
- AND each entry has a `slug`, `rol`, `rol_id`

#### Scenario: Token for Lorena returns 1 app
- GIVEN Lorena (id=1) has only the `crm` assignment
- WHEN `GET /api/v1/auth/validate-token` is called with Lorena's token
- THEN `data.apps` contains exactly 1 entry
- AND that entry has `slug === 'crm'`

#### Scenario: Token for Jaime returns 2 apps (crm + marketing)
- GIVEN Jaime (id=3) has `crm` + `marketing` assignments
- WHEN `GET /api/v1/auth/validate-token` is called with Jaime's token
- THEN `data.apps` contains exactly 2 entries
- AND the slugs are `crm` and `marketing`

#### Scenario: Apps list respects activo = FALSE
- GIVEN the `brp` app has `activo = FALSE`
- AND Patricia has an assignment to `brp`
- WHEN `GET /api/v1/auth/validate-token` is called
- THEN `data.apps` does NOT include the `brp` entry
- AND only the active apps are returned

---

### REQ-VALTOK-2: Cache hit on repeated calls within 5 minutes

The system MUST cache the response per token for 300 seconds (5 minutes). A second call within the TTL returns the cached payload with `data.cached = true` and an `X-Cache: HIT` response header.

Cache key: `auth:validate_token:{sha256(token)}`. TTL: 300 seconds.

**Given** a token is validated once and the response is cached
**When** `GET /api/v1/auth/validate-token` is called again within 300 seconds
**Then** the response body equals the first call's body (modulo `cached` flag)
**And** `data.cached === true`
**And** the response includes an `X-Cache: HIT` header

#### Scenario: Second call within TTL returns cached
- GIVEN the first call to `validate-token` for Patricia at `T = 100s`
- WHEN `validate-token` is called again at `T = 200s` (within TTL)
- THEN the response shape matches the first call
- AND `data.cached === true`
- AND `data.validated_at` is the SAME timestamp as the first call (proving cache hit)
- AND the response header `X-Cache: HIT` is present

#### Scenario: Cache miss after TTL expires
- GIVEN the first call cached the response at `T = 0s`
- WHEN `validate-token` is called at `T = 301s` (after TTL)
- THEN the cache entry has expired
- AND `data.cached === false`
- AND `data.validated_at` is a NEW timestamp
- AND the response header `X-Cache: MISS` is present

#### Scenario: Different tokens get different cache entries
- GIVEN the validation cache has an entry for Patricia's token
- WHEN validate-token is called with Vos's token
- THEN the cache lookup is a miss (different token hash)
- AND `data.cached === false`
- AND Vos's payload is returned (not Patricia's)

#### Scenario: Cache key is namespaced to avoid collisions
- GIVEN the cache key prefix is `auth:validate_token:{hash}`
- WHEN the cache is shared with SAIlus's `X-API-Key` validation cache (if any)
- THEN there is no key collision
- AND namespaces are kept distinct (e.g., `auth:validate_key:` for SAIlus)

#### Scenario: First call header X-Cache: MISS
- GIVEN no cache entry exists for the token
- WHEN the first call is made
- THEN the response header `X-Cache: MISS` is present
- AND the response body is computed and then cached

---

### REQ-VALTOK-3: User with no apps returns 200 with empty arrays

The system MUST return HTTP 200 (NOT 401) for a valid token whose user has zero active apps. The `apps` and `permisos` arrays are empty.

**Given** a valid token for a user with NO `usuario_app` rows AND no `es_super_admin = TRUE` role
**When** `GET /api/v1/auth/validate-token` is called
**Then** the response is HTTP 200
**And** `data.valid === true`
**And** `data.apps === []`
**And** `data.permisos === []`

#### Scenario: Token for app-less user is 200, not 401
- GIVEN a user with 0 `usuario_app` rows and `es_super_admin = FALSE`
- WHEN `validate-token` is called with their valid token
- THEN HTTP 200
- AND `data.apps === []` (empty array)
- AND `data.permisos === []`
- AND `data.valid === true` (the token IS valid)

#### Scenario: Empty apps does NOT mean invalid token
- GIVEN the previous scenario's setup
- WHEN the consumer (BRP) reads `data.valid`
- THEN it is `true` (BRP must NOT redirect to login just because apps is empty)

#### Scenario: User with only inactive-app assignments returns empty apps
- GIVEN Patricia's only assignments are to apps with `activo = FALSE`
- WHEN `validate-token` is called
- THEN `data.apps === []`
- AND `data.valid === true` (token is valid, just apps are filtered)

---

### REQ-VALTOK-4: Invalid / expired / revoked token returns 401

The system MUST return HTTP 401 with `{ success: false, error: "invalid_token" }` for any unauthenticated request or a token that is no longer valid.

**Given** no Bearer token, or a malformed token, or an expired/revoked token
**When** `GET /api/v1/auth/validate-token` is called
**Then** the response is HTTP 401
**And** `success === false`
**And** `error === "invalid_token"`
**And** a human-readable `message` is included

#### Scenario: No Authorization header returns 401
- GIVEN no `Authorization` header in the request
- WHEN `GET /api/v1/auth/validate-token` is called
- THEN response is 401
- AND `success: false`, `error: 'invalid_token'`

#### Scenario: Malformed Bearer token returns 401
- GIVEN `Authorization: Bearer not-a-real-token`
- WHEN `GET /api/v1/auth/validate-token` is called
- THEN response is 401
- AND `error: 'invalid_token'`

#### Scenario: Expired token returns 401
- GIVEN a Sanctum token with `expires_at < NOW()`
- WHEN `validate-token` is called
- THEN response is 401
- AND the cache is NOT populated (no cache entry on failure)

#### Scenario: Revoked token returns 401
- GIVEN Patricia's token was deleted from `personal_access_tokens`
- WHEN `validate-token` is called with her old token
- THEN response is 401
- AND the token cannot be reused even if it has not "expired"

#### Scenario: Inactive user token returns 401
- GIVEN Patricia's user record has `estado = 'Inactivo'`
- WHEN `validate-token` is called with her token
- THEN response is 401 (or treat as 200 with `valid: false` — TBD; **default: 401**)

> The exact behavior for "inactive user with valid token" is a design call. The safe default is 401 because the user is no longer authorized.

#### Scenario: 401 path does NOT cache
- GIVEN the first 401 attempt
- WHEN a second attempt with the same bad token is made within TTL
- THEN the second call also returns 401 (no cache entry is created for invalid tokens)
- AND the system does not store failed validations

---

### REQ-VALTOK-5: X-API-Key header is rejected (this is the Bearer endpoint, not SAIlus)

The system MUST return HTTP 400 if the request carries an `X-API-Key` header (the SAIlus bot pattern). The `/auth/validate-key` endpoint is for SAIlus; this endpoint requires Bearer only.

**Given** the request has `X-API-Key` set (regardless of value)
**When** `GET /api/v1/auth/validate-token` is called
**Then** the response is HTTP 400
**And** `success: false`, `error: 'bearer_required'` (or similar)

#### Scenario: X-API-Key header present returns 400
- GIVEN `X-API-Key: some-key` is sent
- AND no `Authorization: Bearer` header is present
- WHEN `GET /api/v1/auth/validate-token` is called
- THEN response is 400
- AND `error` indicates that Bearer is required for this endpoint

#### Scenario: Both X-API-Key and Authorization headers
- GIVEN both `X-API-Key: x` and `Authorization: Bearer y` are sent
- WHEN the endpoint is called
- THEN the Bearer token takes precedence (`Authorization` is checked first)
- AND if the Bearer is valid → 200, regardless of `X-API-Key`

---

### REQ-VALTOK-6: Response `permisos` field aggregates from the user's roles across all apps

The system MUST compute `permisos` as the union of all permissions granted to the user's roles across all assigned apps (and super-admin's all-permissions).

**Given** a user with assignments in 2 apps, each with their own permissions
**When** `validate-token` is called
**Then** `data.permisos` is the deduplicated union of those permissions
**And** super-admin users get all permissions from the `permisos` table for their `rol_id`

#### Scenario: User with 2 apps gets union of permissions
- GIVEN Patricia has roles `operativo` for crm and `operativo` for brp
- WHEN `validate-token` is called
- THEN `data.permisos` contains both `crm.oportunidades.*` and `brp.sesiones.*` permissions

#### Scenario: Super-admin gets all permissions from rol 1
- GIVEN Vos has super-admin role (id=1) with `vista='*'` in `permisos`
- WHEN `validate-token` is called
- THEN `data.permisos` is the full permissions list for rol 1 (or `["*"]` if model permits)

#### Scenario: User with no permissions gets empty array
- GIVEN a user with roles that have no `permisos` rows
- WHEN `validate-token` is called
- THEN `data.permisos === []`

---

### REQ-VALTOK-7: Permisos and apps reflect current DB state on cache miss

The system MUST NOT cache stale data indefinitely: on cache miss (first call or after TTL), the apps and permisos come from live DB queries.

**Given** the cache for token X is empty
**When** `validate-token` is called
**Then** `data.apps` reflects the CURRENT state of `usuario_app` (fresh from DB)
**And** `data.permisos` reflects the CURRENT state of `permisos`
**And** the data is then cached for 300 seconds

#### Scenario: First call reflects fresh apps
- GIVEN Patricia's apps are {crm, brp} at the moment of the call
- AND no cache entry exists for her token
- WHEN `validate-token` is called
- THEN `data.apps` is [{crm, operativo}, {brp, operativo}] (the live state)

#### Scenario: After admin revokes an app, cached version still shows the app for 5 minutes
- GIVEN Patricia's apps were {crm, brp} and they were cached
- WHEN an admin revokes Patricia's brp assignment at `T = 100s`
- AND `validate-token` is called at `T = 200s` (within TTL)
- THEN `data.apps` STILL includes `brp` (cache is stale by design)
- AND `data.cached === true`

> This is a documented trade-off (R2 in proposal): cached responses may be stale up to TTL. The alternative is invalidating cache on revocation; not implemented in MVP.

#### Scenario: After TTL expires, the cache reflects revoked state
- GIVEN the previous scenario's state at `T = 200s`
- WHEN `validate-token` is called at `T = 305s` (past TTL)
- THEN `data.apps` does NOT include `brp`
- AND `data.cached === false`

---

### REQ-VALTOK-8: Rate limiting is applied per token

The system MUST apply rate limiting to `validate-token` to prevent abuse. External apps (BRP) calling at high frequency SHOULD be throttled but NOT blocked.

**Given** the BRP backend makes many requests per second
**When** the rate limit is exceeded
**Then** the response is HTTP 429 with `Retry-After` header

#### Scenario: Excessive requests get 429
- GIVEN the default rate limit is, e.g., 60 requests/minute per token
- WHEN a caller exceeds this rate
- THEN response is 429 (Too Many Requests)
- AND the response includes `Retry-After`

> The exact rate limit is a deployment choice; the spec documents the existence of the limit. The mechanism uses Laravel's `throttle:` middleware.

---

### REQ-VALTOK-9: Endpoint is part of `/api/v1/` prefix and has no other auth middleware beyond the Bearer check

**Given** the route definition
**When** `routes/api.php` is inspected
**Then** the route is `GET /api/v1/auth/validate-token`
**And** it uses ONLY Bearer token validation (no `auth:sanctum` middleware; the controller does it manually so it can return 401 cleanly without redirect)
**And** it is public (no rbac, no has-app)

#### Scenario: Route exists and matches the contract
- GIVEN `routes/api.php` is loaded
- WHEN inspecting route definitions
- THEN there is a route `GET /api/v1/auth/validate-token`
- AND its handler is `ValidateTokenController@__invoke` (or equivalent)
- AND its middleware list does NOT include `auth:sanctum` (the validation is done in-controller)

#### Scenario: SAIlus `/auth/validate-key` is unaffected
- GIVEN `/auth/validate-token` is a NEW endpoint
- WHEN testing `/auth/validate-key` (SAIlus pattern with `X-API-Key`)
- THEN the original endpoint still works as documented in `openspec/specs/validate-api-key/spec.md`
- AND no regression in the SAIlus flow
