# API Conventions — crm-laravel

> **Audience**: Any consumer of the `crm-laravel` REST surface — Mercurio
> service-to-service calls, the `dashboard-crm` Vue frontend, internal admin
> tools, scripts.
>
> **Owner**: CRM team.
>
> **Status** (Sept 2026): stable. Backed by `App\Http\Controllers\API\Concerns\ApiResponse`,
> the Form Request validation pipeline, and the Sanctum + API-Key auth
> middleware in `routes/api.php` and `Modules/CRM\routes\api.php`.
>
> **Scope**: this document captures the **cross-cutting concerns every
> consumer needs** — response envelope, auth, depth projection, status codes,
> error shapes. Resource-specific endpoints live in:
>
> - [`crm-api-frontend-guide.md`](./crm-api-frontend-guide.md) — `dashboard-crm`
>   Vue front consumer (per-resource).
> - [`mercurio-webhook-contracts.md`](./mercurio-webhook-contracts.md) — Mercurio
>   CQRS mirror + outbound webhook contracts (`personas.snapshot.sync`,
>   `entidades.snapshot.sync`, `?depth=1|2|3` projection detail).

---

## 1. Response envelope (canonical)

**Every JSON response from `crm-laravel` is wrapped in a normalized envelope**.
The shape is enforced by the `ApiResponse` trait
(`app/Http/Controllers/API/Concerns/ApiResponse.php`), which is mixed into
every controller:

```jsonc
// Happy path
{
  "success": true,
  "data":    { /* resource payload */ },
  "message": "Oportunidad creada exitosamente."   // optional, present on most create/update responses
}

// Error path (application-level: 4xx, 5xx)
{
  "success": false,
  "error":   "Oportunida no encontrada.",
  "message": "..."                                  // optional, used by some flows
}
```

The `data` key is omitted when there is no payload to return (deletes,
no-op endpoints). The `message` key is omitted when the controller has no
human-readable status to surface. The `error` key is reserved for the error
path.

### 1.1 Happy 200 — list endpoint

```http
GET /api/v1/entidad?page=1&per_page=15
```

```json
{
  "success": true,
  "data": [
    { "id": 17, "nombre": "Acme S.A.", "tipo_persona": "Juridica", "identificacion": "900123456-7", "...": "..." },
    { "id": 18, "nombre": "Marca Nueva", "tipo_persona": "Juridica", "identificacion": "900999888-1", "...": "..." }
  ],
  "total":        1439,
  "current_page": 1,
  "last_page":    96,
  "per_page":     15
}
```

Note the **paginated wrapper is flattened into the envelope** (no nested
`meta`/`links` block). When a list is returned, the response is
`{success, data: [...], total, current_page, last_page, per_page}`. Some
controllers omit the paginator fields on detail endpoints.

### 1.2 Happy 201 — resource created

```http
POST /api/v1/oportunidades
```

```json
{
  "success": true,
  "data":    { "id": 4242, "codigo": "OP-2026-0001", "estado": "Borrador", "...": "..." },
  "message": "Oportunidad creada exitosamente."
}
```

### 1.3 Error 422 — application rule violation

The controller explicitly rejected the operation because a business rule
fired (not a missing/malformed field). Returned by `ApiResponse::errorResponse()`.

```http
POST /api/v1/oportunidades/4242/ganar
```

```json
{
  "success": false,
  "error":   "Solo oportunidades aceptadas pueden marcarse como ganadas."
}
```

Status code is `422 Unprocessable Entity`. The error is a flat string under
`error` (NOT a structured `errors` map).

### 1.4 Error 422 — Form Request validation failure

A field-level validation rule failed. Returned by Laravel's default
`ValidationException` renderer, NOT by `ApiResponse`. The shape is **different**:

```http
POST /api/v1/entidad
{ "nombre": "" }
```

```json
{
  "message": "El nombre es obligatorio. (and 1 more error)",
  "errors":  {
    "tipo_persona": ["El tipo de persona es obligatorio."],
    "nombre":       ["El nombre es obligatorio."]
  }
}
```

The `errors` map is `{field_name: [messages...]}`. There is no `success: false`
key — Laravel's stock renderer doesn't know about our envelope convention. See
section 5 for how to handle both shapes.

### 1.5 Error 401 — no/invalid token

```http
GET /api/v1/dashboard
```

```json
{
  "success": false,
  "error":   "Unauthenticated."
}
```

### 1.6 Error 500 — server error

```http
GET /api/v1/dashboard
```

```json
{
  "success": false,
  "error":   "Server Error"
}
```

In local dev (`APP_DEBUG=true`) the payload also includes `exception`,
`file`, `line`, and a stack trace. In production those are stripped.

---

## 2. Auth modes

Two authentication mechanisms coexist. Pick the one that matches the call
shape — mixing them in the same request is not supported.

### 2.1 Sanctum Bearer token (frontend user sessions)

For interactive user flows (login → cookie/session-less API calls).

```http
GET /api/v1/dashboard
Authorization: Bearer 1|abc123def456...
Accept: application/json
```

| Step | Endpoint | Notes |
|------|----------|-------|
| Login | `POST /api/v1/auth/login` | Body: `{email, password}`. Returns `{success, data: {token, usuario: {...}}}`. See `crm-api-frontend-guide.md §2`. |
| Use the token | every protected endpoint | Header `Authorization: Bearer <token>`. |
| Logout | `POST /api/v1/auth/logout` | Revokes the current token. Idempotent. |
| Verify | `GET /api/v1/auth/validate-key` | Different flow (X-API-Key, not Sanctum) — see §2.2. |

The middleware chain is `auth:sanctum` (parses the bearer, resolves the
`User` model from `personal_access_tokens`) plus `throttle-mutations` for
write endpoints and `throttle:api` for some hot read endpoints. Sanctum
guards live in `config/sanctum.php`; token issuance is through
`$user->createToken('name')`.

The user object returned by `POST /auth/login` is the slim DTO at
`app/Application/DTOs/LoginResponse.php`:

```json
{
  "success": true,
  "data": {
    "token":   "1|abc123...",
    "usuario": { "id": 1, "nombre": "Ada Lovelace", "email": "ada@tecnoinnsoft.dev", "rol_id": 1, "estado": "Activo" }
  }
}
```

### 2.2 X-API-Key (server-to-server integrations)

For machine-to-machine integrations (Mercurio inbound projection queries,
legacy `entidad.dominio`-based callers). The header is checked by
`App\Infrastructure\Auth\ValidateApiKeyMiddleware`, NOT by Sanctum:

```http
GET /api/v1/auth/validate-key
X-API-Key: crm_live_<entidad_dominio>
```

```json
{
  "valid":          true,
  "entidad_id":     17,
  "permissions":    ["read:entidades", "read:oportunidades", "..."]
}
```

The response shape is intentionally **different from the standard envelope**
(no `success`/`data`) because it predates the convention and is consumed by
Mercurio's projection layer.

### 2.3 Choosing between them

| Use case | Auth mode |
|----------|-----------|
| Browser frontend with logged-in user | Sanctum Bearer |
| CLI scripts running as a specific user | Sanctum Bearer (issue a token via `php artisan crm:generate-token --email=...`) |
| Mercurio mirror projection calls | X-API-Key |
| `n8n` / `cron` / automation that is NOT a user | X-API-Key |

Don't use Sanctum Bearer for service-to-service — the `User` resolution is
expensive and unnecessary for M2M. Don't use X-API-Key for interactive
frontend flows — there is no user identity, so RBAC checks would silently
fail.

### 2.4 Rate limits and middleware ordering

The route file applies different throttles depending on the endpoint:

| Middleware | Applies to | Rate | Where |
|------------|-----------|------|-------|
| `throttle:auth` | `POST /auth/login` | 5/min per IP | `routes/api.php` line 60 |
| `throttle:5,10` | `POST /auth/forgot-password`, `POST /auth/reset-password` | 5 / 10 min | lines 72-77 |
| `throttle:10,1` | `POST /auth/token-exchange` (Mercurio legacy) | 10 / 1 min | line 67 |
| `throttle:120,1` | `GET /auth/roles` (SAIlus catalog) | 120 / 1 min | line 86 |
| `throttle:api` | `GET /users/{id}/brands`, `GET /me/*`, several others | Laravel default `RateLimiter::for('api')` (~60/min) | per route |
| `throttle-mutations` | the whole Sanctum-protected `Route::middleware(['auth:sanctum', 'throttle-mutations'])` group | mutations-only bucket | line 96 |

The `throttle-mutations` middleware is a custom middleware that only counts
mutating verbs (`POST`, `PUT`, `PATCH`, `DELETE`). `GET` requests in the same
group are NOT throttled at this layer — they fall through to any endpoint-
specific `throttle:api`.

Source ordering matters: middleware on the route group applies **before**
middleware on the individual route. So an endpoint with
`Route::middleware(['auth:sanctum', 'throttle-mutations'])->group(...)`
followed by `Route::post('/foo', ...)->middleware('rbac')` resolves to
`['auth:sanctum', 'throttle-mutations', 'rbac']` in declaration order.

---

## 3. `?depth=1|2|3` projection

This is **Contract C** from [`mercurio-webhook-contracts.md §8`](./mercurio-webhook-contracts.md#8-contract-c-depth123-api-projection).
The full semantic spec lives there. This section is a TL;DR for callers
who just need to know which knob to turn.

### TL;DR

| Param | Behaviour | When to use |
|-------|-----------|-------------|
| `?depth=1` | Bare identity only. NO relations, NO side queries. Cheapest. | List endpoints, dropdowns, infinite-scroll pages. |
| `?depth=2` (default if omitted) | Entity + direct first-degree relations. The canonical detail shape. | Detail views, edit panels. |
| `?depth=3` | Entity + direct + second-degree relations (nested collections). | Webhook snapshots, Mercury mirror, deep-read pages. |
| absent / non-integer / out-of-range | Clamped silently to `depth=2`. **Never returns 4xx**. | Default behaviour. |

The projection is centralised in `App\Enums\ProjectionLevel::fromRequest()`
(`filter_var($raw, FILTER_VALIDATE_INT)` rejects `"1.5"`, `"abc"`,
`"1; DROP TABLE"`). The whole point is that it's a **hint, not a contract** —
callers cannot break the API by passing garbage.

Per-resource behaviour (shallow / default / deep per resource) is documented
under the **Per-resource behaviour** subheading in
[`mercurio-webhook-contracts.md §8`](./mercurio-webhook-contracts.md#8-contract-c-depth123-api-projection).
For the `dashboard-crm` Vue front, the practical rule of thumb is:

- **Lists** (`/entidad`, `/contacto`, `/personas`, `/oportunidades`,
  `/seguimientos`, `/apps`): call WITHOUT `?depth` — you get default shape
  and the resource is already wrapped to honour `?depth=1` if you ask for
  it explicitly. For very long lists where relation fields aren't rendered,
  use `?depth=1`.
- **Details** (`/entidad/{id}`, `/contacto/{id}`, `/personas/{id}`,
  `/oportunidades/{id}`, `/seguimientos/{id}`): call WITHOUT `?depth` —
  default shape includes the canonical relations.
- **Snapshot / bulk export** (rare, used by webhook emitters and the
  Mercury mirror): use `?depth=3`.

---

## 4. HTTP status code conventions

| Code | When | Envelope |
|------|------|----------|
| `200 OK` | Successful GET, PUT, PATCH. | `{success: true, data, message?}` |
| `201 Created` | Successful POST that created a resource. | `{success: true, data, message}` |
| `204 No Content` | Not currently used — Laravel still returns 200 with `{success: true}` from `successResponse(null, 200, ...)`. Don't write clients that depend on 204. | n/a |
| `401 Unauthorized` | No / invalid / expired Sanctum bearer. | `{success: false, error: "Unauthenticated."}` |
| `403 Forbidden` | Token valid but the user lacks permission (RBAC deny, cross-tenant access, role-based block). | `{success: false, error: "<reason>"}` |
| `404 Not Found` | Resource ID doesn't exist OR user can't see it (security-relevant cases use 403 instead — see §3 of `crm-api-frontend-guide.md` for examples). | `{success: false, error: "<resource> no encontrado."}` |
| `422 Unprocessable Entity` | Two distinct shapes — see §5. | application: `{success: false, error}`. Form Request: `{message, errors: {field: [...]}}`. |
| `429 Too Many Requests` | Throttle bucket exhausted. | Laravel default `Retry-After` header; body shape is `{message: "Too Many Attempts."}` (Laravel default, NOT the standard envelope). |
| `500 Server Error` | Uncaught exception. | `{success: false, error: "Server Error"}` plus stack trace under `APP_DEBUG=true`. |

The `404` vs `403` distinction is **security-driven**. A non-admin user
requesting a `persona` owned by a different entidad gets a 403
(`Persona no pertenece a su entidad.`), not a 404 — leaking existence
through status code would be an information disclosure. See
`PersonaController::show` for the canonical example
(`app/Modules/CRM/Http/Controllers/PersonaController.php` lines 78-98).

---

## 5. Validation error structure

There are **two distinct 422 shapes** because Laravel's Form Request pipeline
and the `ApiResponse::errorResponse()` helper are two different code paths.
Consumers must handle both.

### 5.1 Application-level 422 (business rule)

When the controller explicitly rejects an operation based on business logic
(state machine violation, missing pre-condition, etc.), the shape is:

```json
{
  "success": false,
  "error":   "Solo oportunidades aceptadas pueden marcarse como ganadas."
}
```

Produced by `$this->errorResponse($message, 422)` in
`app/Http/Controllers/API/Concerns/ApiResponse.php`. The `error` is a flat
string. No `data`, no `errors`.

### 5.2 Form Request validation failure 422

When a field-level rule on a `FormRequest` class fails, Laravel's default
exception renderer fires:

```json
{
  "message": "El nombre es obligatorio. (and 1 more error)",
  "errors":  {
    "nombre":       ["El nombre es obligatorio."],
    "tipo_persona": ["El tipo de persona es obligatorio."]
  }
}
```

The `errors` map is `{field_name: [messages...]}`. `message` is Laravel's
auto-generated summary (the first message, with an `"(and N more errors)"`
suffix when there are multiple).

### 5.3 How consumers should handle both

A defensive client treats them as the same outcome:

```typescript
function isApiError(payload: unknown): boolean {
  if (!payload || typeof payload !== 'object') return false;
  const obj = payload as Record<string, unknown>;
  // Application 422: { success: false, error: string }
  if (obj.success === false && typeof obj.error === 'string') return true;
  // Form Request 422: { message: string, errors: { field: string[] } }
  if (typeof obj.message === 'string' && obj.errors && typeof obj.errors === 'object') return true;
  return false;
}
```

The shape difference matters for **field-level form errors** (Form Request
422) vs **toast / banner errors** (application 422). The dashboard's
`ApiResponse<unknown>` type is currently set up to receive the envelope
shape; the Form Request 422 will look "unwrapped" until you add a
top-level type-narrowing helper at the axios interceptor layer. See
`dashboard-crm/src/api/crmApi.ts` lines 60-73 for the existing 401 handling
pattern.

---

## 6. Cross-references

- **Per-resource guide (this repo's primary consumer)**:
  [`crm-api-frontend-guide.md`](./crm-api-frontend-guide.md) — what the
  `dashboard-crm` Vue front consumes, per endpoint, with request and
  response shapes.

- **Mercury-specific contracts (this is where `?depth=` semantics, the
  outbound webhook flow, retry / replay / kill-switch behaviour live)**:
  [`mercurio-webhook-contracts.md`](./mercurio-webhook-contracts.md) —
  Contracts A (`personas.snapshot.sync`), B (`entidades.snapshot.sync`),
  C (`?depth=1|2|3` API projection).

- **SAIlus multi-app auth integration (legacy but still in use)**:
  [`sailus-integration.md`](./sailus-integration.md) — describes the
  `/me/apps`, `/me/identity`, `/usuarios/{id}/apps/{appId}/permisos`
  endpoints, the OpenAPI spec at `Docs/openapi/auth.yaml`, and the
  CI lint guard against breaking changes.

- **HUBS apps integration**:
  [`hub-apps-integration-guide.md`](./hub-apps-integration-guide.md) —
  how the HUBS front (separate from `dashboard-crm`) consumes the apps
  catalog and assignment endpoints.
