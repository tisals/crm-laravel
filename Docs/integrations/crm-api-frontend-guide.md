# crm-laravel → dashboard-crm Frontend Integration Guide

> **Audience**: maintainers of the `dashboard-crm` Vue frontend
> (`D:\sitios desarrollo\dashboard-crm`), consuming the `crm-laravel` REST API.
>
> **Owner**: CRM team (this repo). Frontend integration owner: dashboard-crm
> team.
>
> **Status** (Sept 2026): stable. Mirrors the actual call surface in
> `dashboard-crm/src/api/crmApi.ts`. If you change a route, controller, or
> resource field, update this doc in the same PR — the front trusts it as
> the source of truth.
>
> **Source files** (when verifying facts):
>
> | File | Why |
> |------|-----|
> | `routes/api.php` | Top-level Sanctum-protected routes (auth, dashboard, RBAC CRUD, ICS, cotizacion). |
> | `Modules/CRM/routes/api.php` | Module-loaded routes (pipelines, contactos, personas, apps, admin granular permissions). |
> | `app/Http/Controllers/API/*.php` | Legacy controllers still wired by `routes/api.php`. |
> | `Modules/CRM/app/Http/Controllers/*.php` | Module controllers (the new home for CRM resources). |
> | `app/Http/Resources/*.php` | Response shape per resource. Many honour `?depth=` — see [api-conventions.md §3](./api-conventions.md#3-depth123-projection). |
> | `app/Http/Requests/*.php` | Validation rules per write endpoint. |
> | `dashboard-crm/src/api/crmApi.ts` | The actual axios client. Every endpoint documented here is one the front calls. |

---

## 0. Audience & scope

This doc covers the REST endpoints the `dashboard-crm` Vue front
consumes. It is **per-resource**: each section lists every endpoint, with
the request shape and the fields the front should care about.

For the shared concerns — response envelope, auth modes, `?depth=` semantics,
HTTP status code conventions, validation error structure — see
[`api-conventions.md`](./api-conventions.md).

For Mercury-specific contracts (the outbound `personas.snapshot.sync` and
`entidades.snapshot.sync` webhooks, plus the full `?depth=1|2|3` semantics)
see [`mercurio-webhook-contracts.md`](./mercurio-webhook-contracts.md).
The TL;DR for `?depth=` is in
[`api-conventions.md §3`](./api-conventions.md#3-depth123-projection).

For the SAIlus multi-app auth integration (legacy but still in use), see
[`sailus-integration.md`](./sailus-integration.md).

---

## 1. Base URL & version prefix

Every endpoint is prefixed with `/api/v1`. In local dev:

```
http://localhost:8001/api/v1
```

The default port is **8001** (not 8000) — see `AGENTS.md` for the rationale
(FastAPI default). The base URL is configurable from the front via the
`VITE_API_BASE_URL` env var:

```ts
// dashboard-crm/src/api/crmApi.ts line 40
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8001/api/v1'
```

In production, override `VITE_API_BASE_URL` at build time to point at the
EasyPanel-hosted instance.

The version prefix is **mandatory**. There is no `/api/v0`, no `/api/v2`
yet — the v2 auth multitenant multiapp architecture is planned but not
shipped (see `routes/api.php` line 66 comment).

---

## 2. Auth flow

The front uses **Sanctum Bearer tokens**. The X-API-Key flow is reserved for
server-to-server integrations and is NOT used by the Vue front. See
[`api-conventions.md §2.2`](./api-conventions.md#22-x-api-key-server-to-server-integrations)
for the M2M flow if you need to script against the API.

### 2.1 Login

```http
POST /api/v1/auth/login
Content-Type: application/json
```

```json
{
  "email":    "ada@tecnoinnsoft.dev",
  "password": "SuperSecret123"
}
```

Validation rules are in `AuthController::login()` (`app/Http/Controllers/API/AuthController.php`
lines 29-39):

| Field | Rule |
|-------|------|
| `email` | required, valid email |
| `password` | required, string, min 8 chars |

Success (200):

```json
{
  "success": true,
  "data": {
    "token": "1|abc123def456...",
    "usuario": {
      "id":      1,
      "nombre":  "Ada Lovelace",
      "email":   "ada@tecnoinnsoft.dev",
      "rol_id":  1,
      "estado":  "Activo"
    }
  }
}
```

Failure (401):

```json
{ "success": false, "error": "Credenciales inválidas." }
```

The token prefix is the standard Sanctum `1|<random>` shape. There is no
expiry field — Sanctum tokens are valid until explicitly revoked. To check
token validity, call `GET /api/v1/auth/validate-key` (see §2.4 below).

The rate limit is **5 attempts/min per IP** (`throttle:auth` middleware,
`routes/api.php` line 60).

### 2.2 Token storage

The front stores the token in `localStorage` under the key `auth_token`
(see `dashboard-crm/src/api/crmApi.ts` line 53):

```ts
const token = localStorage.getItem('auth_token')
if (token && config.headers) {
  config.headers.set('Authorization', `Bearer ${token}`)
}
```

For production, **prefer server-side storage** (HttpOnly cookie via BFF,
or a session proxy). `localStorage` is acceptable for the dashboard dev
build but the standard XSS-warning applies: any injected script can read
the token.

### 2.3 Header injection

Every axios request automatically gets the bearer header from the request
interceptor. The interceptor runs on every call, including logout, login,
and ICS downloads (the ICS endpoints accept the token either via the
`Authorization` header OR as a `?token=` query param — see §3.7).

### 2.4 Logout

```http
POST /api/v1/auth/logout
Authorization: Bearer <token>
```

The endpoint revokes the current token (Sanctum `currentAccessToken()->delete()`)
and returns:

```json
{ "success": true, "message": "Sesión cerrada." }
```

Always 200, even if the token was already revoked (idempotent). The front
removes the token from `localStorage` after the response
(`dashboard-crm/src/api/crmApi.ts` lines 64-69 also handles the 401 case
where the token is already invalid).

### 2.5 Token verification / refresh

There is **no refresh endpoint**. Sanctum tokens are long-lived until
revoked. To check whether the current token is still valid:

```http
GET /api/v1/auth/validate-key
X-API-Key: <api_key>
```

This is the **wrong endpoint** for Sanctum token validation — it uses
`X-API-Key`, not a bearer. Instead, just call any authenticated endpoint
(the cheapest one is `GET /api/v1/me/permisos`) and watch for a 401
response. The axios interceptor already handles the 401 by clearing the
token and redirecting to `/login` (`dashboard-crm/src/api/crmApi.ts` lines
60-73).

### 2.6 Password reset

Two public endpoints, rate-limited at **5 attempts / 10 min**:

```http
POST /api/v1/auth/forgot-password
{ "email": "ada@tecnoinnsoft.dev" }

POST /api/v1/auth/reset-password
{ "email": "ada@tecnoinnsoft.dev", "token": "<from email>", "password": "NewSecret123", "password_confirmation": "NewSecret123" }
```

The front does not currently consume these (`crmApi.ts` does not export
helpers), but the endpoints are available and follow the standard envelope.

---

## 3. Resource sections

### 3.1 Dashboard

`GET /api/v1/dashboard?comercial_id=&fecha_inicio=&fecha_fin=`

KPIs snapshot. All params are optional. Auth: any authenticated user (no
RBAC). Returns the consolidated snapshot used by the home dashboard cards
(ventas, oportunidades activas, seguimientos pendientes, etc.).

Query params (all optional, all filters):

| Param | Type | Notes |
|-------|------|-------|
| `comercial_id` | int | Filter the snapshot to one comercial's pipeline. |
| `fecha_inicio` | date (Y-m-d) | Start of the date window. |
| `fecha_fin` | date (Y-m-d) | End of the date window. |

Response (200, shape varies — the use case `GetDashboardSnapshotUseCase`
builds the payload):

```json
{
  "success": true,
  "data": {
    "kpis":       { "...": "..." },
    "by_stage":   [{ "stage": "Borrador", "count": 12 }, { "...": "..." }],
    "recent":     [{ "...": "..." }]
  }
}
```

The front treats `data` as opaque `DashboardData` and lets the UI pick the
fields it needs.

---

### 3.2 Entidades (Directorio Empresarial)

CRUD for entities (companies). The resource (`app/Http/Resources/EntidadResource.php`)
is depth-aware — see the `?depth=` matrix in
[`api-conventions.md §3`](./api-conventions.md#3-depth123-projection) for the
canonical list endpoints. For most front calls, omit `?depth`.

#### 3.2.1 List — `GET /api/v1/entidad`

Query params:

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `search` | string | — | Free text across `nombre`, `nombre_comercial`, `identificacion`. |
| `per_page` | int | `15` | Capped at 100. |
| `estado` | string | — | Filter by `estado` (e.g., `Activo`, `Propia`, `Prospecto`, `Cliente`, `Inactivo`). |
| `tipo_persona` | string | — | Filter `Natural` / `Juridica`. |
| `sort_by` | string | `created_at` | Field to sort by. |
| `sort_order` | string | `desc` | `asc` / `desc`. |
| `page` | int | `1` | 1-indexed. |

Response (200, paginated):

```json
{
  "success": true,
  "data": [
    { "id": 17, "tipo_persona": "Juridica", "identificacion": "900123456-7",
      "nombre": "Acme S.A.", "nombre_comercial": "Acme",
      "direccion": "Calle 100 #15-20", "email": "contacto@acme.test",
      "telefono": "+57-1-555-1234", "cantidad_empleados": 42,
      "estado": "Cliente", "...": "..." }
  ],
  "total":        1439,
  "current_page": 1,
  "last_page":    96,
  "per_page":     15
}
```

The Comercial role has a row-level tenant filter (`EntidadController::ensureCanAccessEntidad`,
lines 45-80): Comercial users only see entities they're assigned to via the
`entidad_persona` pivot. SuperAdmin / Operaciones / Finanzas see everything.

#### 3.2.2 Show — `GET /api/v1/entidad/{id}`

Returns the full entidad resource at default depth (includes `direccion`,
`email`, `telefono`, `dominio`, `cantidad_empleados`, `estado`, audit
stamps, counts). At `?depth=3` you also get the nested `direcciones[]`,
`emails[]`, `telefonos[]`, `presenciaOnline[]`, `documentos[]`, `relaciones[]`
collections — see [`api-conventions.md §3`](./api-conventions.md#3-depth123-projection).

Returns 404 if the entity doesn't exist. Returns 403 if a Comercial user
requests an entity they're not assigned to.

#### 3.2.3 Create — `POST /api/v1/entidad`

Validation rules from `app/Http/Requests/EntidadRequest.php`:

| Field | Rule |
|-------|------|
| `tipo_persona` | required, `in:Natural,Juridica` |
| `tipo_id` | nullable, string, max 20 |
| `identificacion` | nullable, string, max 50, unique on `entidad` (excludes self on PUT) |
| `nombre` | required, string, max 255 |
| `nombre_comercial` | nullable, string, max 255 |
| `linea_negocio` | nullable, string, max 100 |
| `direccion` | nullable, string, max 255 |
| `ciudad_cod` | nullable, string, max 10, must exist in `ciudades` |
| `dominio` | nullable, string, max 255 |
| `email` | nullable, email, max 255 |
| `telefono` | nullable, string, max 50 |
| `cantidad_empleados` | nullable, int, min 0 |
| `rut` | nullable, string, max 255 |
| `logo` | nullable, string, max 255 |
| `estado` | nullable, string, max 50 |

Returns 201 with the new entidad resource. Also dispatches an outbound
`entidad.created` webhook (Mercury flow) and writes an `ActividadLogger`
entry.

#### 3.2.4 Update — `PUT /api/v1/entidad/{id}`

Same validation rules as create (the `EntidadRequest` rules apply to both
POST and PUT — `identificacion`'s unique constraint excludes the current
row on update).

Returns 200 with the updated resource. 404 if missing. 403 for Comercial
cross-tenant.

#### 3.2.5 Delete — `DELETE /api/v1/entidad/{id}`

Soft-deletes. Returns 200 with `{success: true, message: "Entidad eliminada exitosamente."}`.
Also fires the `entidad.deleted` outbound webhook.

#### 3.2.6 Marcas (Propia) — list & create via Entidad endpoints

The "Marcas Propias" view is a filtered list and a POST to the same
endpoints:

```ts
// dashboard-crm/src/api/crmApi.ts lines 520-530
export async function getMarcas(params?: { search?: string; per_page?: number; page?: number }) {
  return getEntidades({ ...params, estado: 'Propia' })
}

export async function createMarca(payload: MarcaCreate) {
  return createEntidad({ ...payload, estado: 'Propia' })
}
```

So you never call a dedicated "marcas" endpoint — just use the Entidad
endpoints with `estado='Propia'`. Updates and deletes reuse the Entidad
CRUD methods (`updateEntidad`, `deleteEntidad`).

---

### 3.3 Oportunidades (CRM deals)

CRUD plus three workflow actions: `ganar`, `clonar`, `version`. Plus a
bulk-move action used by the Kanban view.

#### 3.3.1 List — `GET /api/v1/oportunidades`

Query params:

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `search` | string | — | Free text. |
| `estado` | string | — | Pipeline etapa name (`Borrador`, `Enviada`, `Negociada`, `Aceptada`, `Ganada`, `Perdida`, etc.). |
| `entidad_id` | int | — | Filter by entidad. |
| `producto_id` | int | — | Filter by product in `detalles.producto_id`. |
| `fecha_desde` | date | — | `oportunidad.fecha >=`. |
| `fecha_hasta` | date | — | `oportunidad.fecha <=`. |
| `codigo` | string | — | Exact match. |
| `pipeline_id` | int | — | Pipeline. |
| `is_latest` | bool | `true` | `false` returns ALL versions for history views. |
| `page` / `per_page` | int | `1` / `50` | Capped at 100. |
| `sort_by` / `sort_order` | string | `created_at` / `desc` | |

Response (200, paginated). At default `depth=2` the items include
`entidad_nombre`, `entidad_identificacion`, `valor` (sum of `vr_total`),
and a flat `detalles[]` (without nested `producto`). At `?depth=3` the
nested `entidad` object and each `detalles[].producto` are added — see
`OportunidadResource::toArray()` lines 30-132.

#### 3.3.2 Show — `GET /api/v1/oportunidades/{id}`

Default detail shape. Same `?depth=` semantics as list. Returns 404 if
not found.

#### 3.3.3 Create — `POST /api/v1/oportunidades`

Validation rules from `app/Http/Requests/OportunidadRequest.php`:

| Field | Rule |
|-------|------|
| `entidad_id` | required, int, exists in `entidad` |
| `contacto_id` | nullable, int, exists in `contacto` |
| `pipeline_id` | nullable, int, exists in `pipelines` |
| `pipeline_etapa_id` | nullable, int, exists in `pipeline_etapas` |
| `fecha` | required, date |
| `codigo` | nullable, string, max 100, unique (server auto-generates if missing) |
| `fuente_canal` | nullable, string, max 100 |
| `estado` | nullable, string, max 100 (relaxed to allow custom CRM states) |
| `observaciones`, `aclaraciones` | nullable, string |
| `validez_oferta` | nullable, int, min 1 |
| `tiempo_entrega`, `forma_pago`, `garantia` | nullable, string, max 255 |

Returns 201 with the full `OportunidadResource` (default depth, includes
`detalles[]` and `valor`).

#### 3.3.4 Update — `PUT /api/v1/oportunidades/{id}`

Same validation rules, but `entidad_id` and `fecha` become **nullable** on
PUT (so partial updates don't have to re-send them). When `estado` changes,
the controller fires the `oportunidad.estado_changed` outbound webhook in
addition to `oportunidad.updated`.

#### 3.3.5 Delete — `DELETE /api/v1/oportunidades/{id}`

Soft-deletes. Returns 200 with `{success: true, message: "..."}`. Fires
`oportunidad.deleted` webhook.

#### 3.3.6 Ganar — `POST /api/v1/oportunidades/{id}/ganar`

Marks the oportunidad as won. **State guard**: only works when the
oportunidad is in the `Aceptada` pipeline etapa — otherwise returns 422:

```json
{ "success": false, "error": "Solo oportunidades aceptadas pueden marcarse como ganadas." }
```

On success: 200 with the updated oportunidad. Side effect: a `Servicio`
is created and the linked entidad is promoted to `cliente`.

#### 3.3.7 Clonar — `POST /api/v1/oportunidades/{id}/clonar`

Clones the oportunidad (and its `detalles`). Returns 201 with the new
oportunidad. No body required.

#### 3.3.8 Versionar — `POST /api/v1/oportunidades/{id}/version`

Creates a new version of the oportunidad. Returns 201 with the new
`OportunidadResource`. The original stays at `is_latest=false`; the new
row is the latest.

#### 3.3.9 Kanban drag — `PUT /api/v1/oportunidades/{id}` with `pipeline_etapa_id`

Reuses the standard update endpoint with a single field:

```ts
// dashboard-crm/src/api/crmApi.ts lines 236-239
await updateOportunidad(id, { pipeline_etapa_id })
```

#### 3.3.10 Bulk move — `POST /api/v1/oportunidades/bulk-move-pipeline`

For the Kanban "move to stage" action across many cards:

```json
{
  "oportunidad_ids":         [4242, 4243, 4244],
  "target_pipeline_etapa_id": 7
}
```

Validation from `app/Http/Requests/BulkMoveOportunidadesRequest.php`: both
fields required, `oportunidad_ids` must be a non-empty array of ints,
`target_pipeline_etapa_id` must exist.

Returns 200 with the bulk-move summary. On any invalid IDs in the array,
returns 422 with `{success: false, invalid_ids: [4242, ...]}`.

---

### 3.4 Pipelines + Etapas

The pipeline configuration endpoints. Pipelines have ordered stages
(`etapas`); the order is drag-and-dropable.

#### 3.4.1 Pipelines

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/pipelines` | Lists all pipelines with their nested `etapas` collection (`PipelineResource`). |
| `GET` | `/api/v1/pipelines/{id}` | Single pipeline. |
| `POST` | `/api/v1/pipelines` | Create. Body validated by `StorePipelineRequest`. Returns 201. |
| `PUT` | `/api/v1/pipelines/{id}` | Update. `UpdatePipelineRequest`. Returns 200. |
| `DELETE` | `/api/v1/pipelines/{id}` | Delete. 200. |

The pipeline resource shape (`app/Http/Resources/PipelineResource.php`):

```json
{
  "id":         1,
  "nombre":     "Comercial",
  "codigo":     "COM",
  "habilitado": true,
  "etapas": [
    { "id": 1, "nombre": "Borrador", "orden": 1, "...": "..." },
    { "id": 2, "nombre": "Enviada",  "orden": 2, "...": "..." }
  ],
  "created_at": "2026-01-15T10:00:00+00:00",
  "updated_at": "2026-09-09T14:23:01+00:00"
}
```

#### 3.4.2 Etapas (per pipeline)

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/pipelines/{pipeline}/etapas` | List etapas for one pipeline. |
| `POST` | `/api/v1/pipelines/{pipeline}/etapas` | Create etapa under pipeline. `pipeline_id` is forced from the route param. |
| `GET` | `/api/v1/pipelines/etapas/{id}` | Show one etapa (no pipeline scoping). |
| `PUT` | `/api/v1/pipelines/etapas/{id}` | Update. |
| `DELETE` | `/api/v1/pipelines/etapas/{id}` | Delete. |

#### 3.4.3 Reorder — `PUT /api/v1/pipelines/{pipeline}/etapas/reorder`

Drag-and-drop reorder. Body is `{ordered_ids: [id1, id2, ...]}`:

```ts
// dashboard-crm/src/api/crmApi.ts lines 230-233
await reorderEtapas(pipelineId, [3, 1, 2, 4])
```

Returns 200 with `{success: true, message: "Etapas reordenadas exitosamente."}`.
If the `ordered_ids` array contains IDs that don't belong to the pipeline,
returns 422 with `{success: false, error: "<reason>"}`.

---

### 3.5 Contactos

CRUD plus two workflow actions: `reasignar` (move to another entidad) and
`acciones` (register a follow-up action like a phone call).

#### 3.5.1 CRUD

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/contacto` | List. Query params: `search`, `per_page` (default 15, capped 100), `page`, `entidad_id`, `estado`. |
| `GET` | `/api/v1/contacto/{id}` | Show. |
| `POST` | `/api/v1/contacto` | Create. Validation from `app/Http/Requests/ContactoRequest.php`: `nombres` required, `apellidos`/`email_contacto`/`email_secundario` validated as nullable. |
| `PUT` | `/api/v1/contacto/{id}` | Update. |
| `DELETE` | `/api/v1/contacto/{id}` | Delete. 200. |

The contacto resource is depth-aware (`ContactoResource`). At default depth
the `entidad_nombre` and `entidad_id` (resolved from `entidad_persona`
pivot, NOT from the dropped `contacto.entidad_id` column) are included. At
`?depth=3` you get nested `persona` and `entidad` snapshots.

#### 3.5.2 Reasignar — `POST /api/v1/contacto/{id}/reasignar`

Move the contacto to a different entidad:

```json
{
  "entidad_id": 42,
  "merge":      false
}
```

`merge: true` will fuse the contacto with an existing one if there's an
email collision. Returns 200 on success. On collision-without-merge,
returns **409 Conflict** (NOT 422) with the conflicting contacto inline:

```json
{
  "success":             false,
  "error":               "conflict",
  "conflicting_contacto": { "id": 88, "email_contacto": "dup@acme.test", "...": "..." },
  "message":             "..."
}
```

#### 3.5.3 Acciones — `POST /api/v1/contacto/{contactoId}/acciones`

Register a follow-up action (`Llamada`, `Correo`, `Reunion`, `Nota`) on
this contacto. Optionally schedule the next seguimiento in the same call.

```json
{
  "tipo":         "Llamada",
  "notas":        "Cliente confirma interés en propuesta",
  "fecha":        "2026-09-15",
  "hora":         "10:30",
  "estado":       "Pendiente"
}
```

Returns 200 with `{success: true, data: {seguimientos: [...]}}` — the
created (and optionally future-scheduled) seguimiento rows. The
`ActividadLogger` records the action; an admin notification fires for
follow-up assignments.

---

### 3.6 Personas (Party Model)

CRUD on the canonical identity axis (PR-G / PR-I in `AGENTS.md`).
Validation is split: `PersonaStoreRequest` (full, POST) and
`PersonaUpdateRequest` (partial, PATCH — all rules `sometimes`). **Update
uses `PATCH`, not `PUT`**, per REQ-PRAPI-004
(`Modules/CRM/routes/api.php` line 80).

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/personas` | List. Query params: `search`, `ciudad`, `pais`, `per_page` (default 15, capped 100), `page`. |
| `GET` | `/api/v1/personas/{id}` | Show. Tenant-aware: 404 if missing, **403 if cross-entidad + non-admin** (`PersonaController::show` lines 78-98). |
| `POST` | `/api/v1/personas` | Create. `PersonaStoreRequest`. Returns 201. |
| `PATCH` | `/api/v1/personas/{id}` | Update. `PersonaUpdateRequest` — all rules `sometimes` for true partial updates. |
| `DELETE` | `/api/v1/personas/{id}` | Delete. 200. |

The persona resource (`app/Http/Resources/PersonaResource.php`) is depth-
aware. At default depth it includes `email_principal`, `telefono_principal`,
`direccion`, `ciudad`, `pais`, and a `relations` block
(`{contacto_id, colaborador_id, proveedor_id, entidad_id}` — all nullable).
At `?depth=3` it also includes a flat `entidad` snapshot. Note that
`relations.entidad_id` is **derived from `entidad_persona` pivot** (post-
commit fe99f70), NOT from the dropped `contacto.entidad_id` column.

---

### 3.7 Seguimientos

List/show endpoints + ICS exports + per-user calendar view + the
"register action" entry point (which is actually under `/contacto/{id}/acciones`
— see §3.5.3).

#### 3.7.1 CRUD

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/seguimientos` | List. Query params: `oportunidad_id`, `persona_id` (NOT `contacto_id` — that legacy key was dropped in PR-H), `entidad_id`, `tipo`, `estado`, `fecha_desde`, `fecha_hasta`, `per_page`. |
| `POST` | `/api/v1/seguimientos` | Create. Body: `{tipo, notas?, fecha?, hora?, estado?, oportunidad_id?, contacto_id?, entidad_id?}`. |
| `GET` | `/api/v1/seguimientos/{id}` | Show. |
| `PUT` | `/api/v1/seguimientos/{id}` | Update. |
| `DELETE` | `/api/v1/seguimientos/{id}` | Delete. |

The seguimiento resource is depth-aware (`SeguimientoResource`). At default
depth it resolves four accessors (`entidad_nombre`, `contacto_nombre`,
`oportunidad_codigo`, `autor_nombre`) — note `contacto_nombre` actually
resolves from `persona.nombres + persona.apellidos` (the JSON key is kept
for backward compat with existing front code).

#### 3.7.2 Mis seguimientos — `GET /api/v1/seguimientos/mios`

Shortcut for the current user. RBAC-scoped:

- **Comercial**: only seguimientos on entities mapped via `entidad_persona`
  (resolved through `usuarios.persona_id`).
- **Admin / SuperAdmin**: all.

Query params: `search`, `estado`, `fecha_desde`, `fecha_hasta`, `tipo`,
`page`, `per_page` (default 50, capped 100).

#### 3.7.3 Per-user calendar — `GET /api/v1/usuarios/{usuarioId}/calendario?mes=YYYY-MM`

Returns seguimientos grouped by day for a given month.

| Param | Type | Notes |
|-------|------|-------|
| `mes` | string | Required. Format `YYYY-MM`. Anything else returns 422. Defaults to current month. |

Authorization: self, OR Admin / SuperAdmin. Non-admin cross-user returns
**403** with `{success: false, error: "No autorizado a ver el calendario de otro usuario."}`.

Response:

```json
{
  "success": true,
  "data": {
    "mes":  "2026-06",
    "dias": {
      "2026-06-01": [ { "id": 88, "tipo": "Llamada", "...": "..." } ],
      "2026-06-15": [ { "id": 89, "...": "..." } ]
    }
  }
}
```

#### 3.7.4 ICS exports

Both endpoints return `text/calendar; charset=utf-8` — NOT the standard
envelope.

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/seguimientos/{id}/ics` | Single seguimiento as `.ics`. Filename: `seguimiento-{id}-{YYYYMMDD}.ics`. |
| `GET` | `/api/v1/seguimientos/calendar.ics?mes=YYYY-MM` | All pending seguimientos in the month. Optional `persona_id` OR `entidad_id` filter. Filename: `seguimientos-{YYYY-MM}.ics`. |

These endpoints use a custom `extract-token` middleware (not the standard
`throttle-mutations`) because they need to accept the bearer token either
via the `Authorization` header OR via a `?token=<token>` query param. The
query-param variant is needed for browser `<a download>` links, which
can't set headers.

The front generates the URLs via two helpers
(`dashboard-crm/src/api/crmApi.ts` lines 452-470):

```ts
export function downloadIcsUrl(seguimientoId: number) {
  const base = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8001/api/v1'
  const token = localStorage.getItem('auth_token')
  const sep = base.includes('?') ? '&' : '?'
  return `${base}/seguimientos/${seguimientoId}/ics${sep}token=${token}`
}

export function downloadMonthlyCalendarIcsUrl(mes: string, params?: {
  contacto_id?: number   // legacy alias (PR-H re-routes to persona_id server-side)
  entidad_id?: number
}) {
  // ... builds the URL with ?mes=...&token=...
}
```

---

### 3.8 My Apps (multi-app)

The "apps" endpoints split into three groups: **catalog** (admin-facing),
**assignments** (admin-facing, ties entities to apps), and **`/me/*`**
(self-service, what the auth user can access).

#### 3.8.1 Catalog

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/apps` | List. Query params: `search`, `tipo` (`internal` / `external` / `customer`), `activo` (bool), `per_page` (default 25, capped 100), `page`. |
| `GET` | `/api/v1/apps/{id}` | Show one. |
| `POST` | `/api/v1/apps` | Create. `AppRequest` validation. |
| `PUT` | `/api/v1/apps/{id}` | Update. |
| `DELETE` | `/api/v1/apps/{id}` | Delete. |

The `AppResource` shape:

```json
{
  "id":          1,
  "slug":        "crm",
  "nombre":      "CRM",
  "tipo":        "internal",
  "auth_type":   "sanctum",
  "activo":      true,
  "descripcion": "...",
  "created_at":  "...",
  "updated_at":  "..."
}
```

#### 3.8.2 Assignments (entidad ↔ app)

**The actual routes are entidad-centric, not app-centric** — the prompt
description of these endpoints is slightly different from the implementation.
Verify against `Modules/CRM/routes/api.php` lines 89-94.

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/apps/{appId}/entidades` | List entities that have this app. Returns array of `EntidadConApp`. |
| `GET` | `/api/v1/entidad/{entidadId}/apps` | List apps assigned to this entity. |
| `POST` | `/api/v1/entidad/{entidadId}/apps/{appId}` | Grant an app to an entity. **Idempotent** — re-running with the same pair is a no-op. Optional body: `{fecha_contrato, fecha_vencimiento, estado, notas, perfil}`. The `perfil` field is a closed allow-list (Hermes profile binding, REQ-HPBN-005) — invalid values return 422. Returns `{success: true, data: {pivot_id}, message}`. |
| `DELETE` | `/api/v1/entidad/{entidadId}/apps/{appId}` | Revoke. **Idempotent** — returns `{removed: true|false}`. |

#### 3.8.3 My apps (self-service) — under `/me/*`, not `/apps/me/*`

**The actual paths are `/me/apps`, NOT `/apps/me`**. The prompt's reference
to `/apps/me` is not the implemented route — verify against
`Modules/CRM/routes/api.php` lines 35-40 and `MeController` (`app/Http/Controllers/API/MeController.php`).

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/me/apps` | Apps the auth user has access to (transitively via `entidad_persona ⨝ app_entidad`). No admin rights required. Returns `{apps: MyApp[], total}`. |
| `GET` | `/api/v1/me/apps/{slug}/permisos` | Entities where the auth user has access to a specific app. Returns the `MyAppDetail` bundle. |
| `GET` | `/api/v1/me/identity` | Consolidated identity bundle (user + apps + scoped permisos + rol defaults). Powered by `user_identity_snapshot` (CQRS-Lite) with Redis cache. |
| `GET` | `/api/v1/me/permisos` | Flat list of all effective permisos for the user (deduped union of core + scoped). Returns `{permisos, scope_label, total}`. |

These endpoints are NOT behind the `rbac` middleware (the user is always
allowed to ask about themselves) but ARE behind `auth:sanctum` +
`throttle:api`.

---

### 3.9 Admin — granular permisos + identity bundles

The dashboard-crm admin matrix UI consumes the per-`(user, app)` permission
endpoints that were originally specced for SAIlus (see
[`sailus-integration.md §3.7`](./sailus-integration.md) for the full
contract). These are admin-only and require the calling user to hold an
elevated `rol` (`Admin` / `SuperAdmin`).

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/v1/usuarios/{userId}/apps/{appId}/permisos` | List the user's effective + scoped permisos for one app. Returns `UserAppPermisos`. |
| `POST` | `/api/v1/usuarios/{userId}/apps/{appId}/permisos` | **Replace-all sync**. Body: `{vistas: string[]}`. Returns the resulting `UserAppPermisos`. |
| `POST` | `/api/v1/usuarios/{userId}/apps/{appId}/permisos/grant` | Grant a single vista (idempotent). Body: `{vista: string}`. |
| `DELETE` | `/api/v1/usuarios/{userId}/apps/{appId}/permisos/{vista}` | Revoke one vista. URL-encode the `vista` (dots and slashes are valid in vista names). Throws on 404 (vista not granted). |
| `POST` | `/api/v1/usuarios/{userId}/apps/{appId}/permisos/reset-to-role-defaults` | Clear all scoped overrides for `(user, app)` — effective permisos revert to rol defaults. Returns `{removed_count}`. |
| `GET` | `/api/v1/usuarios/{userId}/identity` | Admin-only identity bundle: user + apps (each with scoped permisos + effective union) + rol defaults + `scope_label` + `cache_ttl_seconds`. Used by the admin matrix UI to show what the rol would give + what was scoped on top. |

All six endpoints return the standard envelope. The OpenAPI spec at
`Docs/openapi/auth.yaml` (CI-lint guarded against breaking changes per
[`sailus-integration.md §3.8`](./sailus-integration.md)) is the
machine-readable source of truth for the request/response schemas.

The front helpers are at `dashboard-crm/src/api/crmApi.ts` lines 678-773:
`getUserAppPermisos`, `syncUserAppPermisos`, `grantUserAppPermiso`,
`revokeUserAppPermiso`, `resetUserAppPermisosToRoleDefaults`,
`getUserIdentity`.

---

## 4. Common patterns

### 4.1 Pagination

Most list endpoints accept `?page=N&per_page=N` and return:

```json
{
  "success": true,
  "data":            [...],
  "total":           1439,
  "current_page":    1,
  "last_page":       96,
  "per_page":        15
}
```

The default `per_page` differs by resource (15 for entities/contactos/personas,
50 for oportunidades/seguimientos, 25 for apps) but the **cap is always 100**.
Anything above 100 is silently clamped.

The front extracts pagination via the `PaginationInfo` interface
(`dashboard-crm/src/api/crmApi.ts` lines 125-130). When you build the
pagination UI:

- `total` is the unfiltered-or-filtered count for the current query
  (depending on the controller's filter handling).
- `last_page` is `ceil(total / per_page)`.
- If the response has no `data` (e.g., page beyond `last_page`), the
  `axios` interceptor will throw — guard for it in the UI.

### 4.2 Sorting

Most list endpoints accept `?sort_by=field&sort_order=asc|desc`. The default
is `created_at` / `desc`. Sorting is whitelisted per controller — passing a
field that isn't sortable returns 200 with the default order, NOT a 4xx
(defensive — same principle as `?depth=`).

### 4.3 Filters

| Pattern | Example | Notes |
|---------|---------|-------|
| Text search | `?search=acme` | Match across multiple columns per controller. |
| Single FK | `?entidad_id=42`, `?comercial_id=7`, `?producto_id=12` | Most have an `exists:` rule on the FK. |
| Date range | `?fecha_inicio=2026-01-01&fecha_fin=2026-12-31` (dashboard), `?fecha_desde=&fecha_hasta=` (oportunidades / seguimientos). | Some endpoints use `fecha_inicio/_fin`, others `fecha_desde/_hasta` — verify per resource. |
| Enum | `?estado=Activo`, `?tipo_persona=Juridica`, `?tipo=Llamada`. | Enum fields are validated; invalid values return 422. |
| Boolean | `?is_latest=false` on oportunidades. | Parsed via `filter_var($raw, FILTER_VALIDATE_BOOLEAN)` so `"true"`, `"1"`, `"yes"`, `"on"` all work. |

### 4.4 Special state actions (POST to sub-resource)

Several state-machine transitions are exposed as `POST` to a sub-resource
endpoint instead of `PATCH` on the parent:

| Action | Endpoint | Why POST |
|--------|----------|---------|
| Ganar oportunidad | `POST /oportunidades/{id}/ganar` | Has a side effect (creates a Servicio, promotes entidad to cliente) — not a plain field update. |
| Clonar oportunidad | `POST /oportunidades/{id}/clonar` | Creates a new resource as a side effect. |
| Versionar oportunidad | `POST /oportunidades/{id}/version` | Same. |
| Reasignar contacto | `POST /contacto/{id}/reasignar` | Cross-entity move; can fail with 409 on collision. |
| Acciones contacto | `POST /contacto/{id}/acciones` | Creates a `Seguimiento` (or two) — side effect. |
| Reorder etapas | `PUT /pipelines/{id}/etapas/reorder` | Bulk update; not a single field. |
| Bulk move oportunidades | `POST /oportunidades/bulk-move-pipeline` | Same. |
| Assign app to entidad | `POST /entidad/{id}/apps/{appId}` | Creates a pivot row. |

The convention: **if the operation creates a new row, moves the parent to
another container, or has non-trivial side effects, it's a POST to a
sub-resource path**. Plain field edits stay on `PUT/PATCH /resource/{id}`.

### 4.5 Token refresh / re-auth

There is no refresh flow. When the Sanctum token expires (admin revokes it
or it's deleted server-side), the next authenticated request returns 401.
The axios interceptor (`dashboard-crm/src/api/crmApi.ts` lines 60-73) catches
that, clears `auth_token` + `auth_user` from `localStorage`, and redirects to
`/login`. The user re-authenticates, gets a fresh token, and continues.

Don't try to "preemptively" validate the token by hitting an endpoint on
app load — the 401-redirect pattern already handles this and avoids the
extra round-trip on every page.

---

## 5. Cross-references

- **Shared conventions**: [`api-conventions.md`](./api-conventions.md)
  — response envelope, auth modes, `?depth=`, status codes, validation
  error shapes.

- **`?depth=1|2|3` semantics**: [`mercurio-webhook-contracts.md §8`](./mercurio-webhook-contracts.md#8-contract-c-depth123-api-projection)
  — full per-resource depth matrix.

- **Mercury outbound webhooks**: [`mercurio-webhook-contracts.md`](./mercurio-webhook-contracts.md)
  — Contracts A (`personas.snapshot.sync`) and B (`entidades.snapshot.sync`),
  retry / replay / kill-switch behaviour.

- **SAIlus multi-app auth**: [`sailus-integration.md`](./sailus-integration.md)
  — the OpenAPI spec at `Docs/openapi/auth.yaml` covers the auth + `/me/*` +
  `/usuarios/{id}/apps/{appId}/permisos` endpoints, and the CI lint guard
  against breaking changes.

- **HUBS apps integration**: [`hub-apps-integration-guide.md`](./hub-apps-integration-guide.md)
  — how the HUBS front consumes the apps catalog and assignments (different
  from `dashboard-crm`).

- **Project context**: [`../../AGENTS.md`](../../AGENTS.md) — repo
  conventions, local dev commands, testing quirks. Especially relevant
  for the default port (8001) and the `crm:generate-token` command for
  scripting.
