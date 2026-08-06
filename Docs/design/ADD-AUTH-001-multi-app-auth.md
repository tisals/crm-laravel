# ADD-AUTH-001 — Multi-app Authentication & Per-app Permission Scoping

| Field | Value |
|-------|-------|
| **Status** | Implemented (Sprint 1 backend merged in PR #11, commit `1432465`) |
| **Date** | 2026-08-05 |
| **Author** | Architecture team |
| **Method** | ADD (Attribute-Driven Design) con utility tree + 4+1 views |
| **Related** | `PRD-MultiApp-Access.md`, `Docs/integrations/sailus-integration.md` (v2.0), `Docs/changes/multi-app-auth-identity/{proposal,specs/*}.md` |

---

## 1. Revisión de inputs (drivers)

### 1.1 Restricciones

| ID | Restricción | Fuente |
|----|-------------|--------|
| **R01** | Arquitectura actual sin gastos adicionales de licencias (no introducir vendor como Casbin, spatie/laravel-permission, Okta, etc) | Operativo / financiero |
| **R02** | Mantener el Clean Architecture existente + Read/Write split ya implementado en producción | Compatibilidad |

### 1.2 Preocupaciones arquitectónicas

| ID | Preocupación | Justificación |
|----|--------------|---------------|
| **PA01** | Reusar CQRS Táctico (no full CQRS) | Ya adoptado en dashboard snapshot. Probado en prod. Mantiene coherencia arquitectónica. |
| **PA02** | CRM como única fuente de verdad para apps, permisos, roles, usuarios, entidades, persona, servicios, productos | El HUB (BRP, Indicadores, HE, EM) NO debe tener su propia DB de identidad. Centralización para auditoría, GDPR, single source of truth. |

### 1.3 Propósito

| ID | Propósito |
|----|-----------|
| **PR01** | Mejora de la app para soportar multi-tenant y multi-apps. Hoy es monocliente-ish; en 12 meses puede tener 10-24 clientes con 4 apps simultáneas. |

### 1.4 Funcionalidad principal (historias de usuario)

| ID | HU |
|----|-----|
| **HU01** | Como usuario del HUB-micro-saas (BRP, Indicadores, etc), quiero hacer login una sola vez y acceder a las apps que mi organización contrató, con permisos scopados a cada una. |
| **HU02** | Como admin del CRM, quiero asignar/revocar apps por entidad y los permisos default se propagan automáticamente a los usuarios de esa entidad. |
| **HU03** | Como admin del CRM, quiero ajustar permisos individuales por usuario dentro de una app (override del default del rol). |
| **HU04** | Como operador, quiero ver métricas de uso (latencias, cache hit ratio, sesiones activas) para validar SLOs. |

### 1.5 Escenarios de atributos de calidad (QAW)

Cada escenario sigue el formato **Stimulus · Source · Artifact · Environment · Response · Response Measure**.

| ID | Atributo | Imp. | Pri. | Stimulus | Source | Artifact | Environment | Response | Response Measure |
|----|----------|------|------|----------|--------|----------|-------------|----------|------------------|
| **AC01** | Performance | H | H | 1000 logins concurrentes desde BRP/SPA al peak | Clientes externos (BRP web SPA + mobile) | `POST /api/v1/auth/login` -> `LoginUseCase` | Normal, prod, MariaDB master + Redis | Todos los logins procesan exitosamente, devuelven token válido | p95 < 500ms, p99 < 1s, error rate < 0.1% |
| **AC02** | Security (Authorization) | H | H | Un usuario intenta `POST /api/v1/brp/create` sin tener BRP asignado | Atacante interno (usuario legítimo autenticado pero sin acceso) | `RbacMiddleware` + `MultiAppRbacService` | Normal, JWT válido, intento cross-app | Request denegado con 403 | 100% de intentos cross-app bloqueados; 0 falsos positivos para usuarios legítimos |
| **AC03** | Modifiability | H | M | Agregar nueva app "Indicadores" al HUB (1era vez post-BRP) | Product owner | Tabla `apps` + seed + default permisos | Normal, dev/staging | Nueva app visible en `/me/apps` para usuarios correspondientes, sin cambio de código en CRM core | Tiempo total < 4h (1 seed + 1 endpoint doc); 0 cambios en `permisos(rol_id, vista)` |
| **AC04** | Performance (read) | H | H | BRP llama `GET /me/identity` en cada request de API (cold start) | BRP backend | `MeController::identity()` | Cold cache (TTL 5min expirado) | Return bundle consolidado | p95 < 80ms; cache hit > 80% en operación normal |
| **AC05** | Security (Auth) | H | H | Atacante fuerza bruta con email conocido y 1000 passwords | Atacante externo | `LoginUseCase` + `throttle:auth` | Normal, prod | Rate limit corta, ataque bloqueado | 0 passwords probados por minuto sostenido; alerta después de 10 fallos en 5min |
| **AC06** | Availability | M | M | Redis cache down durante `validate-token` | Ops failure | `IdentityCache` fallback | Redis no responde | Caer a query directa del snapshot (< 200ms); degradación graceful | Service 100% disponible aun con Redis down; p95 < 300ms |
| **AC07** | Maintainability | M | M | Nuevo dev debe agregar una vista de permiso scopado sin leer 30 archivos | New developer | `PermisoSeeder` + doc ADD | Dev onboarding | Documento ADD da el path completo | 100% de tasks nuevas localizan config en < 15min leyendo solo ADD + tabla `permisos` |
| **AC08** | Concurrencia | M | M | 1000 sesiones activas simultáneamente | Usuarios reales | PHP-FPM + MariaDB connection pool + Redis | Peak business hours | Sin timeouts, sin dropped requests | p99 < 800ms; connection pool utilization < 80% |
| **AC09** | URL-safety (security) | H | H | Log de access captura URL completa (incluyendo query) | Logger accidental | Reverse proxy + load balancer | Normal | Token nunca aparece en URL | 100% de tokens en headers (zero en query string), validated en CI |
| **AC10** | Interoperability | H | M | Equipo Indicadores arranca integración con HUB; necesita consumir `/me/identity` sin pedir cambios al CRM | Nuevo equipo de desarrollo paralelo | Contrato de `GET /me/identity` (JSON schema) | dev/staging, una sola versión vigente del HUB | Indicadores integra leyendo + 1 endpoint sin tocar CRM | Tiempo de integración < 4h; 0 breaking changes de `/me/identity` en 6 meses sin bump major; schema versionado (`scope_label`); OpenAPI doc publicado |
| **AC11** | Interoperability (backward compat) | M | M | HUB cliente (BRP) está en campo con versión vieja del payload cuando CRM agrega un campo nuevo | BRP en producción | `GET /me/identity` con campo agregado | Prod, BRP no deployado | BRP sigue funcionando sin deploy | 100% de campos nuevos son opcionales en el payload; 0 breaking changes por adición de campo |

### 1.6 Restricciones derivadas (de drivers)

- **DR-1**: El método ADD es **in-process** (sin vendor nuevo). Cumple R01, R02.
- **DR-2**: Read-model pre-computado (snapshot) es la ÚNICA forma de cumplir AC01 con AC04 simultáneos. Cumple PA01.
- **DR-3**: Toda mutación de permisos debe invalidar caches downstream. Cumple AC02, AC09.
- **DR-4**: Toda respuesta de API pública DEBE incluir `scope_label` (ej: `'v1'`) y versión de API en URL prefix (`/api/v1/`). Cumple AC10.
- **DR-5**: Nuevos campos en payloads son siempre **opcionales con default razonable** (forward compat). Cumple AC11.

---

## 2. Objetivo del ADD

Diseñar el subsistema de **identidad multi-app del HUB-micro-saas** que:

1. Cumpla **HU01-HU04** sin sacrificar CQRS Táctico (PA01).
2. Mantenga al CRM como fuente única de verdad (PA02).
3. Crezca a 4 apps sin reescribir la capa de auth (AC03).
4. Cumpla todos los AC de seguridad (AC02, AC05, AC09) y performance (AC01, AC04).
5. No introduzca vendor lock-in (R01).

**Priorización H-H / H-M** (top 3 utility drivers):

1. **AC01 — Performance login < 1s** (H, H)
2. **AC02 — Permisos scopados por app** (H, H)
3. **AC04 — Performance read de identidad** (H, H)

Los otros AC son (H, M) o (M, M) — importantes pero no urgentes.

---

## 3. Elementos a refinar (drivers priorizados)

### 3.1 CQRS Táctico (PA01)

**Refinamiento**: el patrón ya está en producción para `dashboard_kpi_snapshot`. Lo extendemos al dominio de identidad:

- **Write side**: comandos (login, assign app, change perms) escriben a la DB normal
- **Read side**: tabla pre-computada `user_identity_snapshot` consultada por `MeController::identity()`
- **Refresh**: nightly 03:30am + on-demand invalidation por evento

**Trade-off**: la snapshot puede tener staleness de hasta 24h. Mitigación: invalidation on-key-events + endpoint CLI `crm:refresh-user-identity-snapshot --user=X`.

### 3.2 Apps-by-Entity transitivo (constraint existente)

El modelo actual:
```
user → entidad_usuario → entidad → app_entidad → apps
```
**NO se toca**. Lo que se agrega: cada user tiene permisos scopados POR APP (`usuario_app_permisos`). La query de "permisos efectivos" hace union entre permisos core (rol) + permisos app-scoped (user+app).

### 3.3 Authority source-of-truth (PA02)

Toda mutación de permisos, roles, apps, memberships pasa por el CRM. El HUB es read-only sobre identidad. La invalidación desde HUB es solo via re-login o TTL del cache.

---

## 4. Conceptos de diseño seleccionados

### 4.1 Estructuras

| Estructura | Aplicación | Justificación |
|------------|------------|---------------|
| **Layered architecture** | Backend (Handler → Service → Repository) | Ya existe, preservada |
| **Microkernel (plugins)** | Apps como módulos (BRP, Indicadores, etc) | El HUB carga apps via registry; cada app es un Adapter. AC03. |
| **Client-Server** | HUB (browser/mobile) ↔ CRM | Patrón clásico SaaS |

### 4.2 Estilos arquitecturales

| Estilo | Aplicación |
|--------|------------|
| **REST** (stateless, JSON) | HTTP API del CRM |
| **Cache-Aside** | `Redis` para `auth:identity:{sha256(jwt)}` |
| **Read-replica split** | Conexión `mysql_read` ya implementada en Phase 2 |
| **Asynchronous pre-computation** | Job nightly que escribe `user_identity_snapshot` |
| **API versioning** (URL prefix + scope) | `/api/v1/...` con payload `scope_label` |

### 4.3 Patrones de diseño

| Patrón | Cómo se aplica |
|--------|---------------|
| **Decorator** | `RbacMiddleware` envuelve a `Authenticate`. Aplica core + app-scoped check. |
| **Repository** | Para `UsuarioAppPermisoRepository` |
| **Strategy** | `PermissionLookupStrategy`: `CoreOnly` / `ScopedByUser` / `Merged` según contexto |
| **Adapter** | Cada app (BRP, Indicadores) es Adapter del HUB |
| **Saga** (compensación manual) | `AssignAppToEntidadUseCase`: si falla la propagación de permisos, log y rollback |
| **Snapshot** | `user_identity_snapshot` es el read-model (CQRS-Lite) |
| **API versioning** (forward + backward compat) | URL `/api/v1/` + campo `scope_label` en payload + nuevos campos opcionales |

### 4.4 Tácticas de diseño

| Táctica | Driver que satisface | Implementación |
|---------|----------------------|----------------|
| **Auth shunting** | AC05 (brute force), AC02 (authz) | Mock identity service en tests + `throttle:auth` 100/min/email |
| **Persistence migration** | AC03 (modifiability), R02 | Nuevas migrations `usuario_app_permisos` + `user_identity_snapshot` |
| **Caching** | AC01, AC04 | Redis cache (L1) + snapshot DB (L2) |
| **Replication** | AC01, AC04 | `mysql_read` connection para queries de identidad |
| **Resource pooling** | AC08 (concurrencia) | PHP-FPM workers + MariaDB max_connections |
| **Audit** | PA02 (single source of truth), HU04 | `AuthAuditService` ya implementado |
| **Sanitization (URL safety)** | AC09 | Reverse-proxy strips query tokens; Lint test en CI |
| **API versioning + OpenAPI** | AC10, AC11 | (a) URL prefix `/api/v1/`; (b) `scope_label: "v1"` en payloads; (c) schema OpenAPI auto-generado en `Docs/openapi/auth.yaml`; (d) CI lint test rechaza breaking changes sin bump major |

### 4.5 Elección de tecnologías

| Componente | Tecnología | Rationale |
|------------|------------|-----------|
| Backend | **Laravel 12 + PHP 8.2** (existing) | Cumple R02; no vendor |
| DB | **MariaDB 10.2+** (existing) | Sin migración |
| Cache | **Redis** (existing) | Ya configurado |
| Auth | **Sanctum Bearer + X-API-Key** (existing) | Sin vendor |
| RBAC | **In-house** `MultiAppRbacService` | Sin vendor (R01) |
| Snapshot pattern | **In-house** (`crm:refresh-user-identity-snapshot`) | Reusa patrón ya probado |

---

## 5. Instancias arquitecturales, responsabilidades e interfaces

### 5.1 Mapa de componentes

```
┌──────────────────────────────────────────────────────────────────────────┐
│                           CLIENT TIER                                    │
│  BRP-Web  │  Indicadores-Desktop  │  H.Svc-Mobile  │  CRM-Admin-SPA       │
└──────┬─────────────┬────────────────────┬────────────────┬──────────────┘
       │             │                    │                │
       │  Bearer     │ Bearer             │  Bearer        │ Bearer
       ▼             ▼                    ▼                ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                          HUB TIER (BRP, etc)                              │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │ AuthMiddleware (SailusAuthMiddleware)                              │    │
│  │  - lee JWT de Bearer                                              │    │
│  │  - calcula cacheKey (sha256)                                     │    │
│  │  - consulta IdentityCache (Redis)                                │    │
│  │  - on miss: GET /me/identity al CRM                              │    │
│  │  - aplica TenantIsolationMiddleware si N tenants                  │    │
│  └─────────────────────────────────────────────────────────────────┘    │
└──────────────────────────────────┬───────────────────────────────────────┘
                                   │
                                   ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                      CRM TIER (Laravel 12)                                │
│                                                                          │
│  ┌──────────────┐  ┌────────────────┐  ┌────────────────────────────┐    │
│  │ AuthCtrl    │  │ MeController   │  │ AppController              │    │
│  │ login       │  │ apps()         │  │ catalog, assignments       │    │
│  │ validateKey │  │ identity()     │  │                            │    │
│  │ logout      │  │ permisos()     │  │                            │    │
│  └──────┬───────┘  └────────┬───────┘  └─────────────┬──────────────┘    │
│         │                  │                       │                   │
│  ┌──────▼─────────┐ ┌──────▼────────────┐ ┌─────────▼──────────────┐    │
│  │ LoginUseCase  │ │ GetMyIdentity     │ │ AssignAppToEntidad     │    │
│  │               │ │ UseCase (snap)    │ │ UseCase (perm cascade) │    │
│  └──────┬─────────┘ └────────┬─────────┘ └─────────┬──────────────┘    │
│         │                    │                     │                    │
│  ┌──────▼────────────────────▼─────────────────────▼───────────────┐  │
│  │  DOMAIN: User · Persona · Entidad · EntidadUsuario · Apps ·   │  │
│  │         AppEntidad · Permisos · UsuarioAppPermisos · Rol       │  │
│  └────────────────────────┬──────────────────────────────────────┘  │
│                            │                                          │
│  ┌─────────────────────────▼──────────────────────────────────────┐  │
│  │  INFRASTRUCTURE: EloquentRepositories (mysql_read para read)    │  │
│  └────────────────────────┬──────────────────────────────────────┘  │
│                            │                                          │
│           ┌────────────────┼────────────────┐                       │
│           ▼                ▼                ▼                       │
│       master DB       replica DB         Redis                       │
│      (writes)         (reads, 5min        (cache,                      │
│                        cache TTL)         auth:* namespace)            │
└──────────────────────────────────────────────────────────────────────────┘
```

### 5.2 Responsabilidades e interfaces

| Componente | Responsabilidad | Interfaz |
|------------|-----------------|----------|
| `AuthController` | Endpoint login, logout, validate-key | HTTP JSON |
| `MeController` | Endpoints `/me/*` (apps, identity, permisos) | HTTP JSON |
| `AppController` | Admin: CRUD de apps + asignaciones | HTTP JSON |
| `LoginUseCase` | bcrypt verify + token emit | Método interno |
| `GetMyIdentityUseCase` | Lee snapshot, fallback live | Método interno |
| `AssignAppToEntidadUseCase` | Insert app_entidad + cascade permisos default + invalidar cache | Método interno |
| `RbacMiddleware` | Verifica route name contra permisos del user (core + app-scoped) | Middleware |
| `MultiAppRbacService` | Lookup core+scoped + cache | Servicio singleton |
| `IdentityCache` | Redis-backed cache, 5min TTL, key=sha256(jwt) | Servicio |
| `RefreshUserIdentitySnapshotUseCase` | Job nightly que computa todos los users | Artisan command |
| `user_identity_snapshot` (table) | Read-model pre-computado | DB |

---

## 6. Bocetos de vistas + tabla de responsabilidades

### 6.1 Vista Lógica (Logical)

Ver 5.1 (Mapa de Componentes). Capas:
- Client Tier (4 apps + 1 admin)
- HUB Tier (AuthMiddleware)
- CRM Tier (Controllers → UseCases → Domain → Infrastructure → DB)

### 6.2 Vista de Procesos (Process)

**Scenario**: Login de BRP user
1. BRP-Web → `POST /api/v1/auth/login {email, pwd}` → RateLimiter check
2. AuthController.login → LoginUseCase → bcrypt verify (cost 10, ~80ms)
3. Sanctum token created (1 INSERT en personal_access_tokens)
4. Response 200 `{token, usuario}`
5. BRP-Web stores token in HttpOnly cookie
6. BRP-Web → any request → `Authorization: Bearer <token>` header (NO query string)
7. SailusAuthMiddleware (BRP-side) → cache lookup → on miss `GET /me/identity` to CRM
8. CRM MeController.identity → IdentityCache.get → on miss GetMyIdentityUseCase → snapshot SELECT
9. Return bundle `{user, apps[], permisos scoped}`
10. BRP-SailusAuthMiddleware → write to Redis local cache (5min TTL)
11. TenantIsolationMiddleware → reads tenant from bundle, sets TenantContext
12. Controller downstream executes business logic

**Scenario**: Admin assigns new app to entity
1. Admin → `POST /api/v1/entidad/{id}/apps/{appId}`
2. AppController.assignAppToEntidad → AssignAppToEntidadUseCase
3. Insert `app_entidad` row (DB transaction)
4. **Cascade**: para cada `entidad_usuario` activo, insert en `usuario_app_permisos` los defaults del rol
5. Mark `user_identity_snapshot.is_stale=1` para todos los user_ids afectados
6. Invalidate `auth:identity:{sha256(jwt)}` for those users
7. Response 200 `{pivot_id}`

### 6.3 Vista de Despliegue (Deployment)

Ver 7 (Vista Física en 4+1). Una sola caja, multi-container con docker-compose. Read-replica point.

### 6.4 Vista de Implementación (Implementation)

Estructura de carpetas (delta vs current):
```
crm-laravel/
  app/
    Application/
      UseCases/
        Auth/             (existing)
        Me/
          GetMyIdentityUseCase.php       ← NEW
          GetMyAppsUseCase.php           (existing)
        App/             (existing)
        Persona/         (existing)
    Console/
      Commands/
        RefreshUserIdentitySnapshot.php   ← NEW
    Domain/
      Entities/
        Persona.php      (existing)
        UsuarioAppPermiso.php             ← NEW
      Repositories/
        UsuarioAppPermisoRepositoryInterface.php  ← NEW
    Http/
      Controllers/
        API/
          MeController.php                ← MODIFIED (+identity())
      Middleware/
        RbacMiddleware.php                ← MODIFIED (core + scoped check)
    Infrastructure/
      Services/
        MultiAppRbacService.php           ← NEW (replaces RbacService)
      Persistence/
        EloquentPersonaRepository.php     (existing)
        EloquentUsuarioAppPermisoRepository.php  ← NEW
  database/
    migrations/
      2026_08_06_*_create_usuario_app_permisos_table.php       ← NEW
      2026_08_06_*_create_user_identity_snapshot_table.php      ← NEW
      2026_08_06_*_migrate_default_permisos_to_scoped.php       ← NEW
    seeders/
      PermisoSeeder.php                  ← MODIFIED (+ nuevas vistas)
  docs/
    design/
      ADD-AUTH-001-multi-app-auth.md      ← THIS DOCUMENT
    integrations/
      sailus-integration.md             ← UPDATE v2 -> v3
  routes/
    modules/CRM/routes/api.php            ← MODIFIED (+ /auth/me, /me/identity, /me/permisos)
  routes/console.php                      ← MODIFIED (schedule RefreshUserIdentitySnapshot)
```

### 6.5 Vista de Datos (Data)

```sql
-- Nueva tabla (NÚCLEOS del modelo)
CREATE TABLE usuario_app_permisos (
  id          BIGINT UNSIGNED AUTO_INCREMENT,
  usuario_id  BIGINT UNSIGNED NOT NULL,
  app_id      BIGINT UNSIGNED NOT NULL,
  vista       VARCHAR(100) NOT NULL,
  created_at  TIMESTAMP,
  updated_at  TIMESTAMP,
  created_by  BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_user_app_vista (usuario_id, app_id, vista),
  KEY idx_user_app (usuario_id, app_id),
  CONSTRAINT fk_uap_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_uap_app FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE
);

-- Nueva tabla (read-model)
CREATE TABLE user_identity_snapshot (
  user_id      BIGINT UNSIGNED NOT NULL,
  payload      JSON NOT NULL,                -- {user, apps[], permisos scoped}
  scope_label  VARCHAR(20) DEFAULT 'v1',
  computed_at  TIMESTAMP,
  is_stale     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id),
  KEY idx_computed (computed_at)
);
```

### 6.6 Tabla de responsabilidades por componente

| Componente | Capas que toca | Lee | Escribe | Side effects |
|------------|----------------|-----|---------|--------------|
| `MeController::identity` | HTTP, UseCase, Cache | snapshot o live | marca stale | Redis cache hit/miss |
| `MultiAppRbacService::hasPermission` | Middleware | permisos core + scoped | Redis cache (5min) | logs |
| `AssignAppToEntidadUseCase::execute` | UseCase, Repository | defaults del rol | app_entidad, usuario_app_permisos, snapshot is_stale | logs, cache invalidation |
| `RefreshUserIdentitySnapshotUseCase` | Job, Repository | users, permisos | user_identity_snapshot | logs |
| `IdentityCache::get` | Cache | Redis | (none) | Redis hit/miss metric |
| `RbacMiddleware::handle` | HTTP | user, route, perms | (deny response) | logs |

---

## 7. Documentación 4+1

### 7.1 Vista Lógica (ya documentada en 6.1)

### 7.2 Vista de Procesos (ya documentada en 6.2)

### 7.3 Vista Física (Deployment)

```
                            ┌─────────────────┐
                            │   DNS / TLS     │
                            └────────┬────────┘
                                     │
                            ┌────────▼────────┐
                            │ Reverse Proxy   │
                            │ (nginx/caddy)   │
                            │ - strips query  │
                            │   token in logs │
                            └────────┬────────┘
                                     │
              ┌──────────────────────┼──────────────────────┐
              ▼                      ▼                      ▼
    ┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐
    │ Web servers (2)  │   │ Mail workers     │   │ Scheduler        │
    │ PHP-FPM 8.2      │   │ (queue=database) │   │ (cron)           │
    │ Laravel app      │   │ - RefreshUser... │   │ - RefreshDash... │
    │ ports 8000-8001  │   └────────┬─────────┘   │ - RefreshUser... │
    └────────┬─────────┘            │             └──────┬───────────┘
             │                      │                    │
             └──────────────────────┼────────────────────┘
                                    ▼
                          ┌──────────────────────┐
                          │   MariaDB primary    │
                          │   (writes + critical  │
                          │    reads)             │
                          └────────┬─────────────┘
                                   │ replication
                                   ▼
                          ┌──────────────────────┐
                          │   MariaDB replica    │
                          │   (dashboard + me/*  │
                          │    reads)             │
                          └──────────────────────┘

       ┌─────────┐
       │  Redis  │ (cache: auth:*, me:identity:*)
       └─────────┘
```

**Deployment constraints**:
- Web tier: 2 réplicas PHP-FPM (horizontal scaling per AC08)
- MariaDB: 1 master + 1 replica (read-after-write lag < 5s aceptable para `validate-token`)
- Redis: single instance (cache miss = graceful degradation per AC06)
- Scheduler: contenedor aislado para jobs (no comparte con web pool)

### 7.4 Vista de Implementación (ya documentada en 6.4)

### 7.5 Escenarios (ya documentados en 1.5)

---

## 8. Diseño detallado de módulos a implementar

### 8.1 Backend (Sprint 1)

**Module 1: Permission Scoping Infrastructure**

- **Files**:
  - `database/migrations/2026_08_06_*_create_usuario_app_permisos_table.php`
  - `app/Domain/Entities/UsuarioAppPermiso.php`
  - `app/Domain/Repositories/UsuarioAppPermisoRepositoryInterface.php`
  - `app/Infrastructure/Persistence/EloquentUsuarioAppPermisoRepository.php`
  - `database/migrations/2026_08_06_*_migrate_default_permisos_to_scoped.php`

- **API surface**: ninguna (data layer solamente)

- **Dependencies**: tabla `usuarios`, `apps`, `permisos`, `roles`

- **Acceptance**:
  - Schema correcto (migration reversible)
  - Data migration: para cada user existente, scopes se crean a partir del rol
  - Tests: 100% de users con scoped permissions al final

**Module 2: MultiAppRbacService**

- **Files**:
  - `app/Application/Services/MultiAppRbacService.php`
  - `app/Http/Middleware/RbacMiddleware.php` (MODIFICADO)
  - `tests/Unit/Application/MultiAppRbacServiceTest.php`

- **API surface**: servicio singleton, expone `hasPermission(userId, appId, vista)`

- **Acceptance**:
  - Lookup con cache 5min en Redis
  - Fallback graceful a query directa si Redis down (AC06)
  - Para super_admin → siempre `true`
  - Para otros: `permisos(rol_id) ∪ usuario_app_permisos(user_id, app_id)`

**Module 3: User Identity Snapshot**

- **Files**:
  - `database/migrations/2026_08_06_*_create_user_identity_snapshot_table.php`
  - `app/Application/UseCases/Me/GetMyIdentityUseCase.php`
  - `app/Application/UseCases/Me/RefreshUserIdentitySnapshotUseCase.php`
  - `app/Console/Commands/RefreshUserIdentitySnapshot.php`
  - `app/Http/Controllers/API/MeController.php` (MODIFICADO: +identity(), +permisos())
  - `Modules/CRM/routes/api.php` (MODIFICADO: + /me/identity, /me/permisos)
  - `routes/console.php` (MODIFICADO: schedule dailyAt('03:30'))
  - `database/seeders/PermisoSeeder.php` (MODIFICADO: + vistas)

- **API surface**:
  - `GET /api/v1/me/identity` — bundle consolidado
  - `GET /api/v1/me/permisos` — lista plana

- **Acceptance**:
  - Snapshot se computa nightly a las 03:30
  - On-demand via `php artisan crm:refresh-user-identity-snapshot --user=X`
  - Invalidation on key events (AssignAppToEntidad, role change, perm change)
  - Cache Redis wrapper: si row en `user_identity_snapshot` tiene `is_stale=1`, recompute
  - AC04: p95 < 80ms en cache hit

**Module 4: Admin Permissions API**

### 8.2 Frontend (Sprint 2)

**Module 5: Admin Permissions Matrix UI**

- **Files**:
  - `src/pages/UsuariosPage.tsx` (MODIFICADO: + botón "Ver permisos")
  - `src/pages/UsuarioPermisosPage.tsx` (NEW)
  - `src/components/PermissionsMatrix.tsx` (NEW: tabla apps × vistas)
  - `src/api/crmApi.ts` (+ funciones admin de permisos)
  - `src/api/types.ts` (+ tipos)

- **API surface**: consume endpoints del Module 4

- **Acceptance**:
  - Matriz con checkboxes por celda
  - Bulk: "Reset to role defaults" + "Grant all / Revoke all"
  - Preview del "permission efectivo final" (core ∪ scoped)

### 8.3 HUB integrations (Sprint 3)

**Module 6: BRP integration**

- **Coord. con equipo BRP** (ext scope, fuera de este repo):
  - `SailusAuthMiddleware` debe consultar `GET /auth/me` o `/me/identity` (1 call)
  - Cachear localmente 5min (similar a `brp:auth:sailus:{sha256(jwt)}` existente)

- **Acceptance**:
  - BRP funciona con el nuevo endpoint sin cambios en BRP business logic
  - Old endpoints (`/me/apps`, `/validate-key`) marcados como `Deprecated: use /me/identity`

### 8.4 Documentación (Sprint 4)

- `Docs/integrations/sailus-integration.md`: bump v2 → v3, documentar `/auth/me`, `/me/identity`, `/me/permisos`
- `Docs/design/ADD-AUTH-001-multi-app-auth.md` (este doc): marcar Status=Draft → Status=Implemented
- `Docs/integrations/integration-guide.md` (Nuevo): cómo integrar nueva app
- `Docs/openapi/auth.yaml` (Nuevo): OpenAPI spec de los endpoints de auth
- `tools/openapi-lint.sh` (Nuevo): CI guard contra breaking changes

---

## 9. Tabla de seguimiento de drivers

| ID | Driver | Estado decisión | Estado implementación | Notas |
|----|--------|-----------------|------------------------|-------|
| R01 | Sin vendor lock-in | ✅ Decidido: todo in-house (no Casbin/spatie) | Pendiente Sprint 1 | Validación: PR review debe rechazar cualquier `composer require` con paquete RBAC |
| R02 | Mantener arquitectura actual | ✅ Decidido: CQRS Táctico + Clean Architecture existentes | Pendiente Sprint 1 | |
| PA01 | Reusar CQRS Táctico | ✅ Decidido: snapshot pattern igual a `dashboard_kpi_snapshot` | Pendiente Sprint 1 | Mismo job scheduler, misma cache layer |
| PA02 | CRM como fuente única | ✅ Decidido: HUB solo lee via API | Pendiente Sprint 3 | Endpoints deprecated marcan el path de migration |
| PR01 | Multi-tenant multi-apps | ✅ Decidido: scopíng por app | Pendiente Sprint 1-2 | |
| HU01 | Login una sola vez | ✅ Decidido: `/auth/login` + `/auth/me` | Pendiente Sprint 1 | |
| HU02 | Asignar app → permisos default | ✅ Decidido: cascade en `AssignAppToEntidadUseCase` | Pendiente Sprint 1 | |
| HU03 | Admin ajusta permisos individuales | ✅ Decidido: endpoints admin en Module 4 + UI matriz | Pendiente Sprint 1-2 | |
| HU04 | Métricas de uso | ✅ Decidido: contadores Redis + Telescope | Pendiente Sprint 4 | |
| AC01 | Login < 1s p95 | ✅ Decidido: bcrypt cost 10 + cache 30s en user lookup | ✅ MITIGATED (PR #2 b61cf2f) | Verificar en load test |
| AC02 | Authz scopado por app | ✅ Decidido: `RbacMiddleware` extendido | Pendiente Sprint 1 | Tests cross-app = objetivo del Sprint 1 |
| AC03 | Agregar app sin tocar CRM | ✅ Decidido: tabla `apps` + permisos default por app | Pendiente Sprint 2 | Validar con 1ra nueva app (Indicadores) |
| AC04 | `/me/identity` p95 < 80ms | ✅ Decidido: snapshot + Redis cache 5min | Pendiente Sprint 1 | |
| AC05 | Brute force prevention | ✅ Decidido: `throttle:auth` 100/min + alerta | Pendiente Sprint 4 | |
| AC06 | Redis down = graceful | ✅ Decidido: `MultiAppRbacService` con fallback DB | Pendiente Sprint 1 | |
| AC07 | Onboarding 15min | ✅ Decidido: este ADD doc | ✅ Documentado | |
| AC08 | 1000 concurrentes | ⏳ TBD (load test pendiente) | Pendiente Sprint 4 | |
| AC09 | Zero tokens en URL | ✅ Decidido: header-only + reverse proxy strips | Pendiente Sprint 1 | Lint test en CI |
| AC10 | Nueva app se integra < 4h | ✅ Decidido: OpenAPI spec + integration-guide + scope_label | Pendiente Sprint 4 | Mide el "time-to-market" de cada nueva app |
| AC11 | Backward compat por 6 meses | ✅ Decidido: campos nuevos opcionales, breaking = bump /api/v2/ | Pendiente Sprint 4 | Validar con BRP en prod |

**Leyenda**: ✅ Decidido | ⏳ TBD | 🟡 En implementación | ❌ Bloqueado

**Estado agregado**: 15/17 decididos, 0/17 bloqueados, 16/17 pendiente implementación.
