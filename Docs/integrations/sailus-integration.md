# Integración SAIlus ↔ CRM (Multi-app Auth)

**Versión:** 3.0 (2026-08-06)
**Audience:** Equipo SAIlus (Python, gateway) y BRP (FastAPI)
**Mantenedor:** Equipo CRM (crm-laravel)
**Estado actual:** sincronizado con `main` al `d35eae7` (PR #13 OpenAPI spec)

> **Historial:**
> - v1.0 (2026-07-30): describía el diseño del branch `feature/multi-app-access-and-fixes`, **que nunca se mergeó** a `main`. Mencionaba endpoints como `POST /auth/token-exchange`, tabla `usuario_app` con `rol_id`. **NO USAR COMO REFERENCIA** — está obsoleta.
> - v2.0 (2026-08-05): primera versión sincronizada con `main` después del merge del sprint 1. Describía los endpoints `/me/apps`, `/me/apps/{slug}/permisos` y `/auth/validate-key`. **Está obsoleta** por la v3.0 (le faltan 5 endpoints nuevos + OpenAPI spec + CI guard).
> - **v3.0 (ESTA)**: cubre los 13 endpoints actuales (3 auth + 4 me + 6 admin), OpenAPI spec machine-readable en `Docs/openapi/auth.yaml`, CI lint guard contra breaking changes, y los 4 sprints del cambio `multi-app-auth-identity`.

## Cambios vs v2.0

| Cambio | Detalle |
|--------|---------|
| +5 endpoints | `GET /me/identity`, `GET /me/permisos`, `GET /usuarios/{id}/identity`, `POST/DELETE /usuarios/{id}/apps/{appId}/permisos`, `POST /usuarios/{id}/apps/{appId}/permisos/{grant,reset-to-role-defaults}` |
| +OpenAPI spec | `Docs/openapi/auth.yaml` (3.1, 13 endpoints, 11 schemas). Contract machine-readable para integraciones |
| +CI lint guard | `tools/openapi-lint.sh` corre en cada PR. Falla si hay breaking changes |
| +Backward compat (AC11) | Todos los responses incluyen `scope_label: 'v1'`. Nuevos campos son siempre opcionales |
| +Admin identity preview | El admin puede ver el bundle completo de cualquier user (rol defaults + scoped) |

## Sprint log (cambio `multi-app-auth-identity`)

| Sprint | PR | Descripción |
|--------|-----|-------------|
| 1 (backend) | crm-laravel #11 | 3 migrations + models + service + use cases + 38 tests |
| 2 (frontend matrix) | dashboard-crm #5 | UI admin matrix + `PermissionsMatrix` component |
| 2.5 (identity endpoint) | crm-laravel #12 | `GET /usuarios/{id}/identity` con rol defaults |
| 2.5b (frontend uses identity) | dashboard-crm `a24c848` | Frontend usa el endpoint identity, muestra defaults del rol |
| 4 (OpenAPI + CI lint) | crm-laravel #13 | OpenAPI 3.1 spec + bash linter contra breaking changes |

---

## 1. Resumen ejecutivo

El CRM es la **fuente de identidad** para el ecosistema multi-app de Tecnoinnsoft (crm, sailus, marketing, wp-plugin, la-llave, brp). Cualquier app externa — incluyendo BRP y SAIlus — autentica contra este CRM vía Sanctum tokens Bearer.

**Decisiones arquitectónicas clave** (tomadas en review multi-app 2026-08-05):

1. **Apps se contratan a nivel ENTIDAD, no a nivel usuario.** El modelo es:
   ```
   user → entidad_usuario → entidad → app_entidad (estado='Activo') → apps
   ```
   No existe la tabla `usuario_app`. La asignación de apps se hereda transitivamente. Esta decisión difiere del PRD original (`PRD-MultiApp-Access.md`) pero matchea el patrón SaaS real (Stripe, Salesforce).

2. **API Key (machine-to-machine) sigue siendo vía header `X-API-Key`.** Valida contra `entidad.dominio` y devuelve `{valid, bot_id, name}`. NO usa Sanctum.

3. **Login Sanctum devuelve solo `{token, usuario}`.** Las apps del usuario se consultan por separado vía `/api/v1/me/apps` (cached 5min).

4. **CQRS Táctico** (no full CQRS): cache + read replica + pre-computed snapshots. Decisión tomada en code review por costo/beneficio (1 dev team).

---

## 2. Modelo de actores

```
┌─────────────────────────────────────────────────────────────────┐
│                       CRM (este sistema)                       │
│                                                                 │
│  Usuario ─[usuario_app_entidad]─► Entidad ─[app_entidad]─► App │
│    (id)     (N:M pivot via       (id)      (N:M pivot   (id)   │
│              entidad_usuario)               with metadata)     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

- **Usuario**: login Sanctum. Token = `Bearer 1|abc123...`
- **Entidad**: empresa/cliente. Tiene `dominio` único (usado como API Key para integraciones).
- **App**: producto del ecosistema. 6 apps catalogadas: crm, sailus, marketing, wp-plugin, la-llave, brp.
- **entidad_usuario** (pivot N:M): qué entidades son miembros de un usuario. **Ya existía** en main.
- **app_entidad** (pivot N:M con metadata): qué apps tiene contratadas cada entidad. Tabla nueva.
- **NO existe `usuario_app`**: la asignación es derivada via la transitividad.

### Schema relevante

```sql
-- Existente
usuarios(id, email, password_hash, rol_id, estado, ...)
entidad(id, nombre, dominio, ...)
entidad_usuario(usuario_id, entidad_id, timestamps)
permisos(rol_id, vista, ...) -- RBAC por rol

-- Nuevas (este ciclo de trabajo)
personas(id, nombres, apellidos, email_principal, telefono_principal,
         direccion, ciudad, pais, softDeletes, timestamps)
apps(id, slug UNIQUE, nombre, tipo [internal|external|customer],
     auth_type [sanctum|api_key], activo, descripcion, softDeletes)
app_entidad(app_id, entidad_id, fecha_contrato, fecha_vencimiento,
            estado [Activo|Suspendido|Cancelado|Trial], notas, timestamps)
```

**Migrations nuevas que aplican** (de `php artisan migrate`):

```
2026_07_30_080400_add_identificacion_and_persona_id_to_contacto
2026_08_05_100000_create_personas_table
2026_08_05_100100_add_direccion_ciudad_pais_to_personas_table
2026_08_05_150000_add_performance_indexes
2026_08_05_150001_complete_performance_indexes
2026_08_05_200000_create_dashboard_kpi_snapshot_table
2026_08_05_220000_create_apps_table
2026_08_05_220100_create_app_entidad_table
```

**Seeds a correr en primer deploy**:

```bash
php artisan db:seed --class=AppsCatalogSeeder
# O todo desde cero (DESTRUCTIVO):
php artisan migrate:fresh --seed
```

---

## 3. Endpoints

### Base URL
`https://crm.tecnoinnsoft.com/api/v1` (prod) o `http://localhost:8001/api/v1` (dev)

### 3.1 Auth — Machine-to-Machine (API Key)

#### `GET /api/v1/auth/validate-key`

Headers:
```
X-API-Key: <entidad.dominio>
```

Response 200:
```json
{
  "valid": true,
  "bot_id": "bot_42",
  "name": "Acme S.A.",
  "permissions": []
}
```

Response 401 (key inválida):
```json
{
  "valid": false,
  "error": "API key inválida"
}
```

- **Cache**: 5 minutos (`auth:api_key:{sha256(key)}`) vía Redis.
- **Auth**: NINGUNA (es público, pero rate-limited).
- **Rate limit**: 60 req/min (configurable vía `RateLimiter::for('api', ...)` en `AppServiceProvider`).
- **Uso típico**: BRP, SAIlus, WP-plugin llaman esto en cada request para validar identidad antes de operar.

⚠️ **NO usa Bearer Sanctum aquí.** Es X-API-Key vs Entidad.dominio.

### 3.2 Auth — User login (Sanctum Bearer)

#### `POST /api/v1/auth/login`

Request:
```json
{
  "email": "user@empresa.com",
  "password": "password123"
}
```

Response 200:
```json
{
  "success": true,
  "data": {
    "token": "1|abc123def456...",
    "usuario": {
      "id": 5,
      "nombre": "Admin",
      "email": "user@empresa.com",
      "rol_id": 1,
      "estado": "Activo"
    }
  }
}
```

Response 401:
```json
{
  "success": false,
  "error": "Credenciales inválidas."
}
```

- **Token TTL**: configurado en `config/sanctum.php` (default: sin expiración).
- **Hash cost**: `BCRYPT_ROUNDS=10` (recomendado para SLO login < 1s). Default Laravel es 12.
- **User lookup cache**: 30s en Redis. Invalidado en logout.
- **NO devuelve apps aquí** (a diferencia del diseño v1). Las apps se obtienen con `/me/apps`.

### 3.3 Self-service — Apps del usuario (transitivas)

#### `GET /api/v1/me/apps`

Headers:
```
Authorization: Bearer <token>
```

Response 200:
```json
{
  "success": true,
  "data": {
    "apps": [
      {
        "id": 1,
        "slug": "crm",
        "nombre": "CRM Tecnoinnsoft",
        "tipo": "internal",
        "auth_type": "sanctum",
        "activo": true,
        "entidades_count": 3
      },
      {
        "id": 2,
        "slug": "sailus",
        "nombre": "SAIlus Gateway",
        "tipo": "internal",
        "auth_type": "sanctum",
        "activo": true,
        "entidades_count": 1
      }
    ],
    "total": 2
  }
}
```

- **Cache**: 5 minutos (`auth:me:apps:{user_id}:v1`).
- **Cómo se computa**: unión deduplicada a través de las entidades del usuario vía entidad_usuario, intersectada con app_entidad donde `estado='Activo'`.
- **Para SAIlus/BRP**: usar este endpoint después del login para saber qué apps tiene el usuario, en lugar de tener su propia tabla `usuario_app`.

#### `GET /api/v1/me/apps/{slug}/permisos`

Response 200:
```json
{
  "success": true,
  "data": {
    "app": {
      "id": 1,
      "slug": "crm",
      "nombre": "CRM Tecnoinnsoft",
      "tipo": "internal",
      "auth_type": "sanctum"
    },
    "permisos": [
      {
        "entidad_id": 7,
        "entidad_nombre": "Acme S.A.",
        "identificacion": "900123456-7",
        "estado": "Activo",
        "fecha_contrato": "2026-01-15",
        "fecha_vencimiento": "2027-01-15",
        "notas": null
      }
    ],
    "total_entidades": 1
  }
}
```

Response 404 si el usuario no tiene acceso o la app no existe.

### 3.4 Admin — Apps por entidad (CRUD interno)

Estos endpoints son para el dashboard CRM interno. **No consumidos por SAIlus/BRP directamente.**

#### `GET /api/v1/apps` — catálogo
#### `POST /api/v1/apps` — crear app en catálogo
#### `GET /api/v1/apps/{id}` — detalle de una app
#### `PUT /api/v1/apps/{id}` — editar
#### `DELETE /api/v1/apps/{id}` — eliminar (soft via SoftDeletes)
#### `GET /api/v1/apps/{appId}/entidades` — entidades con la app

#### `GET /api/v1/entidad/{entidadId}/apps` — apps de una entidad
#### `POST /api/v1/entidad/{entidadId}/apps/{appId}` — asignar (idempotente)
#### `DELETE /api/v1/entidad/{entidadId}/apps/{appId}` — remover (idempotente)

### 3.5 Auth endpoints legacy (del diseño v1, NO IMPLEMENTADOS)

Los siguientes endpoints **mencionados en la v1 de este doc NO EXISTEN en main**:

- ❌ `POST /api/v1/auth/token-exchange` (proxy SAIlus→CRM)
- ❌ `POST /api/v1/auth/login` con `apps[]` en el response
- ❌ Tabla `usuario_app`
- ❌ Tabla `apps_usuarios`

Si tu código apunta a alguno de estos, **falla**. Usá la API documentada en §3.2 y §3.3.

### 3.6 Self-service — Identity bundle (sprint 2.5)

**Estos endpoints son NUEVOS en v3.0** y son la forma recomendada de integración para apps modernas (BRP, Indicadores, HE, EM). Reemplazan el patrón "N+1 calls" del diseño v1.

#### `GET /api/v1/me/identity`

Bundle consolidado del auth user en **una sola llamada HTTP**.

**Headers**: `Authorization: Bearer <token>`

**Response 200**:
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 5,
      "nombre": "Juan Pérez",
      "email": "juan@empresa.com",
      "estado": "Activo",
      "rol": { "id": 2, "nombre": "Comercial", "es_super_admin": false }
    },
    "apps": [
      {
        "id": 1, "slug": "brp", "nombre": "BRP Asistencia", "tipo": "external",
        "auth_type": "sanctum", "activo": true, "entidades_count": 3
      }
    ],
    "scope_label": "v1",
    "snapshot_at": "2026-08-05T03:30:00Z",
    "ttl_seconds": 86400
  }
}
```

**Performance**:
- Cache Redis 5min (key `auth:me:identity:{userId}:v1`) → p95 < 80ms en cache hit
- Snapshot DB nocturna (job `crm:refresh-user-identity-snapshot` a las 03:30)
- Fallback graceful si Redis down: query directa al snapshot → p95 < 200ms

#### `GET /api/v1/me/permisos`

Lista plana de permisos efectivos (sin metadata de apps).

**Response 200**:
```json
{
  "success": true,
  "data": {
    "permisos": [
      "contacto.index", "contacto.update", "entidad.show",
      "brp.create", "brp.read", "indicador.dashboard"
    ]
  }
}
```

Útil para apps que solo necesitan verificar "puede hacer X" sin la metadata completa.

### 3.7 Admin — Permisos scopados (sprint 1)

**Solo admin (permiso `usuarios.apps.permisos.*`)**. Usados por la UI dashboard para ajustar permisos individuales.

#### `GET /api/v1/usuarios/{userId}/identity`

Bundle completo de un usuario arbitrario (admin preview). **El más útil para tooling de admin**: trae `rol_defaults[]` para que el admin vea qué permisos hereda el usuario del rol.

```json
{
  "success": true,
  "data": {
    "user": { "id": 5, "nombre": "...", "rol": { "es_super_admin": false } },
    "rol_defaults": ["contacto.index", "entidad.update", ...],
    "apps": [
      {
        "id": 1, "slug": "brp", "permisos_scoped": ["brp.admin"],
        "permisos_efectivos": ["brp.admin", "contacto.index", ...]
      }
    ],
    "scope_label": "v1",
    "computed_at": "2026-08-05T12:00:00Z",
    "cache_ttl_seconds": 60
  }
}
```

**Cache**: 60s en Redis (key `auth:user:identity:{userId}:{requestingId}:v1`).

#### Endpoints CRUD de permisos scopados

Todos bajo `Bearer + admin`. Idempotentes. Marcan `user_identity_snapshot.is_stale=1` en cada mutación.

| Método | Path | Body | Notas |
|--------|------|------|-------|
| GET | `/usuarios/{userId}/apps/{appId}/permisos` | — | Lista permisos scopados actuales |
| POST | `/usuarios/{userId}/apps/{appId}/permisos` | `{vistas: ["X", "Y"]}` | Sync replace-all |
| POST | `/usuarios/{userId}/apps/{appId}/permisos/grant` | `{vista: "X"}` | Grant uno (idempotent) |
| DELETE | `/usuarios/{userId}/apps/{appId}/permisos/{vista}` | — | Revoke uno (idempotent) |
| POST | `/usuarios/{userId}/apps/{appId}/permisos/reset-to-role-defaults` | — | Borra todos los overrides |

### 3.8 OpenAPI spec (machine-readable)

`Docs/openapi/auth.yaml` contiene el spec OpenAPI 3.1 completo:
- 13 paths (3 auth + 4 me + 6 admin)
- 11 component schemas
- 4 response types (Success, Unauthorized, NotFound, ValidationError, RateLimited)
- Todos los responses con `scope_label: 'v1'` (forward compat, AC11)

**Para generar un cliente**:
```bash
# Python (FastAPI client)
npx @openapitools/openapi-generator-cli generate \
  -i Docs/openapi/auth.yaml \
  -g python-fastapi \
  -o ./client-python

# TypeScript
npx @openapitools/openapi-generator-cli generate \
  -i Docs/openapi/auth.yaml \
  -g typescript-fetch \
  -o ./client-ts
```

**Para validar que tu cliente no va a romper** en el próximo release:
- Lint el spec localmente: `bash tools/openapi-lint.sh origin/main`
- En CI: el workflow `ci.yml` corre ese mismo lint en cada PR contra `origin/main`

---

## 4. Flujo end-to-end — Login de usuario (ACTUAL)

```
┌───────┐         ┌─────────┐         ┌─────────────┐
│ Front │         │ SAIlus  │         │ CRM Laravel │
└───┬───┘         └────┬────┘         └──────┬──────┘
    │                 │                    │
    │ POST /login     │                    │
    │ (email, pwd)    │                    │
    ├──────────────►  │                    │
    │                 │ POST /api/v1/auth/login
    │                 │   (email, pwd)    │
    │                 ├───────────────────►│
    │                 │                    │ 1. bcrypt verify
    │                 │                    │ 2. createToken()
    │                 │                    │ 3. ActividadLogger::login
    │                 │                    │
    │                 │  200 {token, usuario}
    │                 │◄───────────────────┤
    │                 │                    │
    │  200 {access_token, user}            │
    │◄──────────────┤ (SAIlus genera JWT │
    │                 │  propio, lo guarda │
    │  GET /api/v1/me/apps con Bearer JWT  │
    │  (SAIlus lo hace en nombre del user)│
    ├────────────────┤                    │
    │                 │ GET /me/apps (Bearer Sanctum)
    │                 ├───────────────────►│
    │                 │                    │ 200 {apps: [...]}
    │                 │◄───────────────────┤
    │                 │                    │
    │  Devuelve al FRONT qué apps tiene   │
    │◄──────────────┤                    │
    │                 │                    │
    │  Para cada app que el front abra,   │
    │  SAIlus valida contra el CRM vía     │
    │  GET /auth/validate-key (X-API-Key) │
    └─────────────────┘                    │
```

**Diferencia clave vs diseño v1**: SAIlus **NO hace proxy de credenciales al CRM**. El front hace login contra el CRM directamente (con CORS) o vía SAIlus que solo emite su propio JWT. **Login SaaS-style, no proxy.**

---

## 5. RBAC y permisos

### Modelo

Cada `rol` tiene N `permisos(vista)`. El RbacMiddleware (`app/Infrastructure/Auth/RbacMiddleware.php`) verifica por nombre de ruta. Vista = route name (`contacto.index`, `apps.store`, etc.).

`SuperAdmin` y `Admin` reciben `vista='*'` (wildcard).

### Permisos nuevos (ciclo multi-app)

```
personas.*       (index, store, show, update, destroy)
apps.*           (index, store, show, update, destroy)
apps.entidades   (index)
entidad.apps     (index, assign, remove)
me               (index)
me.apps          (index)
me.apps.permisos (index)
```

### Permisos para SAIlus/BRP

SAIlus y BRP no necesitan permisos especiales. Usan **API Key** (X-API-Key) o **Bearer Sanctum token** según el endpoint. El RbacMiddleware aplica solo a rutas internas del dashboard.

---

## 6. Performance y caching

### Cache layer (Redis, driver `cache`)

| Recurso | Cache key | TTL | Invalidación |
|---------|-----------|-----|--------------|
| Validate-key (`X-API-Key`) | `auth:api_key:{sha256(key)}` | 300s | TTL |
| Login user lookup | `auth:user_lookup:{sha256(email)}` | 30s | En logout |
| Me/apps | `auth:me:apps:{user_id}:v1` | 300s | TTL (manual vía version bump) |
| Me/apps/{slug}/permisos | `auth:me:apps:perms:{user_id}:{slug}:v1` | 300s | TTL |
| Roles list (`/auth/roles`) | `auth:roles:{sha256(token_hash)}` | 300s | TTL |
| Planes public | `public:planes:suscripciones` | 3600s | Manual (no se invalida automáticamente) |

### Read replica split

El CRM tiene 2 connections MySQL:
- `mysql` (master) — writes + reads críticos (auth)
- `mysql_read` (réplica) — dashboard, listados, reportes

Auto-detección: si `mysql_read.host` es **distinto** de `mysql.host`, se usa la réplica. Si es el mismo (dev / tests), se cae al master automáticamente (evita problemas con `RefreshDatabase` transaction isolation).

### Dashboard KPI snapshot

Para evitar 70+ queries agregadas por load de dashboard:
- `crm:refresh-dashboard-snapshot` nightly at 03:00 (America/Bogota)
- Tabla `dashboard_kpi_snapshot` con JSON pre-computado por `(scope, year)`
- `GET /api/v1/dashboard` lee del snapshot, fallback a live si missing/stale (>26h)

### Índices compuestos (performance)

```
idx_contacto_entidad_active (entidad_id, deleted_at)
idx_oportunidad_entidad_estado_active (entidad_id, estado, deleted_at)
idx_seguimiento_entidad_fecha (entidad_id, fecha)
idx_personas_active_recent (deleted_at, created_at)
```

---

## 7. Configuración operativa

### Variables de entorno

```env
# Requeridos
APP_URL=https://crm.tecnoinnsoft.com
DB_CONNECTION=mysql
DB_HOST=mariadb-prod
DB_DATABASE=tecnoinnsoft_crm
CACHE_STORE=redis          # CRÍTICO para SLO
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
BCRYPT_ROUNDS=10           # SLO login < 1s

# Read replica (opcional, si no se setea usa master)
DB_READ_HOST=replica.mariadb.internal
DB_READ_PORT=3306
DB_READ_DATABASE=tecnoinnsoft_crm
DB_READ_USERNAME=readonly
DB_READ_PASSWORD=...
```

### Performance budget

| Endpoint | Latencia esperada | Notas |
|----------|-------------------|-------|
| `POST /auth/login` | <500ms (con cache hot) | bcrypt cost 10 = ~70ms |
| `GET /auth/validate-key` | <10ms (cache hit) | 99% cache hit esperado |
| `GET /me/apps` | <20ms (cache hit) | Computar el join es ~80ms, cachea |
| `GET /me/apps/{slug}/permisos` | <20ms (cache hit) | Igual |
| `GET /dashboard` (snapshot) | <50ms | 1 SELECT del JSON snapshot |
| `GET /dashboard` (fallback live) | <800ms | 70+ agregaciones, acceptable |
| `GET /apps` (catálogo) | <50ms | Paginated 25/page |

---

## 8. Bugs conocidos y workarounds

### 8.1 Validación de tokens (PENDIENTE)

`GET /api/v1/auth/validate-key` actualmente **NO valida Bearer Sanctum tokens**, solo `X-API-Key`. Si querés que valide ambos:
- Crear `POST /api/v1/auth/validate-token` con `Authorization: Bearer ...`
- Devuelve `{user_id, email, apps: [...], entidades: [...], expires_at}`
- Cachea resultado 5min con invalidación en logout

Está en el PRD como pendiente, se priorizará según demanda de BRP.

### 8.2 Cache invalidation (PENDIENTE)

Los caches de `/me/apps` (5min TTL) **no se invalidan** cuando un admin asigna/remueve apps. Si un usuario obtiene un nuevo app, puede tardar hasta 5min en verlo.

Workaround temporal:
- Bajar el TTL a 60s (cambio en `GetMyAppsUseCase.php`)
- O forzar refresh manual desde el widget (`refreshApps()` ya está implementado en el contexto)

### 8.3 Multi-tenant data isolation

La regla Comercial-role-access-restriction (commit `595a814`) limita Comercial a sus entidades asignadas vía `entidad_usuario`. **Aplica a endpoints de entidad, contacto, oportunidad, seguimiento**. NO aplica a `apps` ni a `personas`. Si necesitás multi-tenant estricto para apps, agregar `EnsureUserHasApp` middleware.

### 8.4 Fixes recientes (documentados para evitar regresiones)

| Commit | Fix | Síntoma que arregla |
|--------|-----|---------------------|
| `6a297d3` | `LoginUseCase::USER_LOOKUP_*` constantes: private → public | Logout devolvía 500 "Cannot access private constant" |
| `6a297d3` | Migration `seguimiento` índice: `usuario_id` → `(entidad_id, fecha)` | `migrate` fallaba parcialmente en primer deploy |
| `6a297d3` | Migration idempotente `complete_performance_indexes` | Completa índices que la migration anterior falló |
| `b61cf2f` | bcrypt 12→10 (`BCRYPT_ROUNDS=10` en `.env.example`) | Login -200ms |
| `b61cf2f` | 4 composite indexes migration | Listados 50% más rápidos |
| `b61cf2f` | ValidateApiKey cache 5min | BRP/SAIlus auth -80ms/hit |
| `d7dba8d` | Dashboard KPI snapshot | Dashboard -650ms |

**Para deploys nuevos**: TODAS estas migrations deben aplicarse con `php artisan migrate`. Si `BCRYPT_ROUNDS=10` no está en `.env`, el default Laravel 12 (12) se usa y login será ~280ms más lento.

---

## 9. Runbook para operaciones

### Primer deploy de un cliente nuevo

```bash
# 1. Migrations (no destructivo)
php artisan migrate

# 2. Seed del catálogo de apps
php artisan db:seed --class=AppsCatalogSeeder

# 3. Roles + permisos (incluye los nuevos: personas, apps, me)
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=PermisoSeeder

# 4. Cache limpia
php artisan config:clear
php artisan cache:clear

# 5. Snapshot dashboard (opcional, precomputar antes del primer usuario)
php artisan crm:refresh-dashboard-snapshot

# 6. Verificar
php artisan tinker --execute="echo \App\Models\App::count() . ' apps';"
# Expected: 6
```

### Asignar apps a un usuario (workflow admin)

1. Login como admin en dashboard
2. Ir a **Sidebar → Configuración → Apps por Entidad** (URL `/settings/apps`)
3. Click en la entidad del usuario en la lista izquierda
4. La matriz derecha muestra las 6 apps; click **+** para asignar, **X** para remover
5. El usuario verá los cambios en su widget "Mis Apps" dentro de 5min (o al refrescar)

### Verificar acceso de un usuario (sin esperar el cache)

```bash
php artisan tinker --execute="
\$userId = 5;
\$apps = \DB::connection('mysql_read')->table('entidad_usuario')
    ->join('app_entidad', 'entidad_usuario.entidad_id', '=', 'app_entidad.entidad_id')
    ->join('apps', 'app_entidad.app_id', '=', 'apps.id')
    ->where('entidad_usuario.usuario_id', \$userId)
    ->where('app_entidad.estado', 'Activo')
    ->whereNull('apps.deleted_at')
    ->distinct()
    ->pluck('apps.slug')
    ->toArray();
echo 'User 5 tiene acceso a: ' . implode(', ', \$apps);
"
```

### Verificar identidad completa de un usuario (admin)

```bash
# Identity bundle con cache de 60s
curl -s -H "Authorization: Bearer <admin-token>" \
  http://localhost:8001/api/v1/usuarios/5/identity | jq .

# Forzar refresh de la snapshot nocturna (individual)
php artisan tinker --execute="
\$useCase = app(\App\Application\UseCases\Me\RefreshUserIdentitySnapshotUseCase::class);
\$useCase->invalidate(5);
echo 'Snapshot marcada como stale para user 5';
"

# Forzar refresh completa de TODAS las snapshots
php artisan crm:refresh-user-identity-snapshot

# Refrescar el cache de un solo endpoint
php artisan tinker --execute="
\Cache::forget('auth:me:identity:5:v1');
\Cache::forget('auth:user:identity:5:1:v1');
echo 'Cache cleared for user 5';
"
```

### Bajar el cache de `/me/apps` (forzar recompute)

```bash
php artisan tinker --execute="
// Borrar todo el cache de me/apps (todos los usuarios)
\Cache::tags('me.apps')->flush();  // tags no funciona con file/redis default, mejor:
foreach (\DB::table('usuarios')->pluck('id') as \$uid) {
    \Cache::forget(\"auth:me:apps:{\$uid}:v1\");
    \Cache::forget(\"auth:me:apps:perms:{\$uid}::*\");
}
"
```

(Mejor: agregar cache tags en v3 con `Redis::tags()` para invalidación atómica).

---

## 10. Roadmap (pendiente para esta integración)

| Item | Effort | Impacto |
|------|--------|---------|
| `POST /auth/validate-token` (Bearer Sanctum) que devuelva apps + entidades | 4h | Habilita que BRP use Sanctum directamente |
| Cache invalidation event-driven en `/me/apps` | 2h | Refresh instantáneo para usuarios (vs 5min) |
| Per-app roles (tabla `app_entidad_usuarios` con `rol_en_app`) | 1-2 días | Roles granulares dentro de cada app |
| `EnsureUserHasApp` middleware | 2h | Protege endpoints app-scoped end-to-end |
| OpenAPI spec actualizado para `/me/apps` + `/auth/validate-key` | 3h | SAIlus/BRP pueden auto-generar clients |
| Eliminar `updated_at`/`created_at` redundantes en `entidad_usuario` | 1h | Limpieza schema |

---

## 11. Changelog

### v3.0 (2026-08-06) — identidad + OpenAPI + CI guard

**Cambios principales**:
- Agregado §3.6: `GET /me/identity` (bundle consolidado) y `GET /me/permisos` (lista plana)
- Agregado §3.7: 5 endpoints admin de permisos scopados (`GET /usuarios/{id}/identity` + 4 CRUD sobre `/usuarios/{id}/apps/{appId}/permisos*`)
- Agregado §3.8: OpenAPI spec + ejemplo de generador de clientes
- Nueva sección "Cambios vs v2.0" al inicio con sprint log completo
- Nueva sección "Forward compat (AC11)" con `scope_label` + política de bump v2
- Runbook expandido: comandos para verificar identidad + forzar refresh de snapshot + limpiar cache

**Sprints completados**:
- Sprint 1 (backend full stack) — crm-laravel #11
- Sprint 2 (frontend matrix UI) — dashboard-crm #5
- Sprint 2.5 (identity endpoint) — crm-laravel #12
- Sprint 2.5b (frontend uses identity) — dashboard-crm `a24c848`
- Sprint 4 (OpenAPI spec + CI lint) — crm-laravel #13

**Pendientes**:
- Sprint 3: BRP integration (externo, fuera de este repo)
- Sprint 6: load test staging
- Sprint 7: bump `/api/v2/` cuando haya breaking change real

### v2.0 (2026-08-05) — reescritura completa (OBSOLETA — usar v3.0)

- Reescrito para reflejar el estado REAL de `main` al commit `b246c6f`
- Eliminada toda referencia al diseño `feature/multi-app-access-and-fixes` (que documentaba el branch unmerged)
- Corregido: apps son **transitivas** (user→entidad_usuario→app_entidad→apps), no directas
- Agregado: `/me/apps`, `/me/apps/{slug}/permisos`
- Agregado: `apps` catalog, `app_entidad` pivot
- Agregado: persona CRUD (background)
- Agregado: CQRS Táctico (bcrypt cost, cache, indexes, snapshot, replica)
- Documentado: bugs fijos (private const, migration schema)
- Documentado: bugs pendientes (validate-token Sanctum, cache invalidation)
- **Le faltan**: 5 endpoints nuevos, OpenAPI spec, CI lint guard → usar v3.0

### v1.0 (2026-07-30) — primera versión (OBSOLETA — usar v3.0)

- Diseño propuesto, no implementado en `main`
- Mencionaba `POST /auth/token-exchange`, `usuario_app` table, `persona_id` flow
- **NO USAR COMO REFERENCIA** — quedó obsoleta
