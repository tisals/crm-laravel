# Proposal: Multi-app Access & Party Model

> **Phase:** propose  
> **Date:** 2026-07-30  
> **Change:** `multi-app-access`  
> **Driver:** Cliente Banco de Bogotá (3.000 empleados) — BRP necesita autenticar psicólogos contra el CRM en ≤15 días

---

## 1. Intent

Convertir al CRM de **un backend con auth propia** a una **plataforma de identidad compartida** para 6 apps del ecosistema Tecnoinnsoft (CRM, SAIlus, Marketing, Plugin WP, La Llave, BRP). Un usuario con un único login podrá acceder a N apps con roles distintos por app, y apps externas (BRP, La Llave) podrán validar tokens Sanctum contra el CRM con <100ms de latencia.

Esto resuelve el problema actual de **silos de identidad**: el caso Patricia (contacto → staff → datos dispersos) ya no será posible porque una `Persona` centraliza nombre, identificación y contacto, mientras que `Usuario` mantiene la cuenta técnica que accede a las apps.

---

## 2. Scope

### In Scope

**Schema (DB)**
- 3 tablas nuevas: `apps`, `usuario_app`, `personas`.
- 3 columnas nuevas en tablas existentes: `contacto.identificacion_tipo`, `contacto.identificacion_numero`, `contacto.persona_id`.
- 2 columnas nuevas en `roles`: `slug`, `es_super_admin` (bloqueante — ver §3.4).
- Backfill de datos: poblar `identificacion_*` y enlazar `contacto → personas` vía `email_contacto`.

**API (nueva, prefijo `/api/v1/`)**
- `POST /auth/login` — **modificado**: retorna apps del usuario en la misma respuesta.
- `GET /auth/validate-token` — **nuevo**, auth Bearer, para apps externas (BRP).
- `GET /me` — **nuevo**, datos del usuario autenticado (incluye apps).
- `GET /me/apps` — **nuevo**, lista de apps del usuario.
- `GET /me/apps/{slug}/permisos` — **nuevo**, permisos del usuario en una app.
- `GET /usuarios/{id}/apps` — **nuevo**, admin lista asignaciones.
- `POST /usuarios/{id}/apps` — **nuevo**, admin asigna app+rol.
- `DELETE /usuarios/{id}/apps/{app_id}` — **nuevo**, admin quita asignación.

**Middleware**
- `EnsureUserHasApp` (alias `has-app:slug`) — gate por app, registrado en `bootstrap/app.php`.

**Casos seed confirmados** (usuarios reales de MariaDB, NO los literales del PRD):
- Vos (admin) `id=5` → 6 apps con `super-admin`.
- Lorena `id=1` → solo `crm` con rol `comercial`.
- Patricia `id=4` → `crm` + `brp` con rol `operativo`.
- Jaime `id=3` → `crm` + `marketing` con `super-admin`.

**Tests**
- Feature: `LoginResponseIncludesAppsTest`, `ValidateTokenEndpointTest`, `MeAppsEndpointTest`, `MeAppPermisosEndpointTest`, `UsuarioAppAdminTest`.
- Unit: `LoginUseCaseIncludesAppsTest`, `ValidateTokenUseCaseTest`, `EnsureUserHasAppMiddlewareTest`, `CachedTokenValidatorTest`.

### Out of Scope (diferido a cambios posteriores)

- `persona_contactos` (multi-email/multi-teléfono).
- `proveedor` y `colaborador` referenciando `personas` (quedan independientes en MVP).
- UI admin para gestionar asignaciones (en MVP: seeder + endpoints API).
- Webhooks outbound a apps externas.
- OAuth2 / OIDC.
- 2FA.
- Soft-delete en `contacto` (DA-7).
- Multi-tenant SaaS del CRM.

---

## 3. Approach

### 3.1 Module Placement

| Asset | Ubicación |
|---|---|
| Migrations (todas) | `database/migrations/` (convención global existente) |
| Models Eloquent | `Modules/Shared/app/Models/` (`App`, `UsuarioApp`, `Persona`) |
| Wrappers deprecated | `app/Models/App.php`, `app/Models/UsuarioApp.php`, `app/Models/Persona.php` |
| Repositories Eloquent | `app/Infrastructure/Persistence/EloquentAppRepository.php` (match existing pattern) |
| Use cases | `app/Application/UseCases/Auth/` + subfolder `Me/` + `Admin/` |
| Controllers | `app/Http/Controllers/API/` (`MeController`, `ValidateTokenController`, `UsuarioAppController`) |
| Middleware | `app/Infrastructure/Auth/EnsureUserHasAppMiddleware.php` |
| Seeders | `database/seeders/` (`AppsSeeder`, `BrpRolesSeeder`, `UsuarioAppSeeder`) |
| Factories | `database/factories/` (`AppFactory`, `UsuarioAppFactory`, `PersonaFactory`) |
| Tests | `tests/Feature/API/` + `tests/Unit/Application/UseCases/Auth/` + `tests/Unit/Infrastructure/Auth/` |

**Por qué `Modules/Shared` y no un módulo nuevo:** `apps`/`usuario_app`/`personas` son infraestructura de identidad al mismo nivel que `usuarios`/`roles`/`permisos`, que ya viven en Shared. Crear un módulo `Core`/`Identity` para 3 tablas es overkill y rompe el patrón de `Modules/Proyectos` (placeholder vacío).

### 3.2 Schema Changes

#### Tablas NUEVAS

**`apps` (catálogo de las 6 apps)**
| Columna | Tipo | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `slug` | VARCHAR(50) | NOT NULL, **UNIQUE** |
| `nombre` | VARCHAR(100) | NOT NULL |
| `tipo` | ENUM | NOT NULL DEFAULT `'internal'` — `('internal','external','customer')` |
| `auth_type` | ENUM | NOT NULL DEFAULT `'sanctum'` — `('sanctum','api_key')` |
| `activo` | BOOLEAN | NOT NULL DEFAULT TRUE |
| `descripcion` | TEXT | NULL |
| timestamps | — | — |

Seed: `crm`, `sailus`, `marketing`, `wp-plugin`, `la-llave`, `brp`.

**`usuario_app` (pivot)**
| Columna | Tipo | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `usuario_id` | BIGINT UNSIGNED | NOT NULL, FK → `usuarios.id` ON DELETE CASCADE |
| `app_id` | BIGINT UNSIGNED | NOT NULL, FK → `apps.id` ON DELETE CASCADE |
| `rol_id` | BIGINT UNSIGNED | NOT NULL, FK → `roles.id` ON DELETE RESTRICT |
| timestamps | — | — |

Constraints: `UNIQUE(usuario_id, app_id)`. Índices: `(usuario_id)`, `(app_id)`.

**`personas` (Party Model)**
| Columna | Tipo | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `identificacion_tipo` | VARCHAR(10) | NULL |
| `identificacion_numero` | VARCHAR(20) | NULL, **UNIQUE (parcial cuando no NULL)** |
| `nombres` | VARCHAR(100) | NOT NULL |
| `apellidos` | VARCHAR(100) | NOT NULL |
| `email_principal` | VARCHAR(150) | NULL |
| `telefono_principal` | VARCHAR(30) | NULL |
| timestamps | — | — |

Índice: `(identificacion_numero)` para acelerar el JOIN del backfill.

#### Tablas EXISTENTES — ALTER

**`contacto`** (+3 columnas + FK)
- `identificacion_tipo VARCHAR(10) NULL` (después de `apellidos`).
- `identificacion_numero VARCHAR(20) NULL` (después de `identificacion_tipo`).
- `persona_id BIGINT UNSIGNED NULL` (después de `id`).
- FK `fk_contacto_persona → personas(id) ON DELETE SET NULL`.
- Índice `idx_contacto_persona (persona_id)`.

**`roles`** (+2 columnas — **BLOQUEANTE**, ver §3.4)
- `slug VARCHAR(50) NULL UNIQUE` (después de `nombre`).
- `es_super_admin BOOLEAN NOT NULL DEFAULT FALSE` (después de `slug`).

### 3.3 API Changes

| Método | Path | Auth | Cambio |
|---|---|---|---|
| POST | `/auth/login` | público | **Modificado**: añade `data.apps[]` a la respuesta |
| GET | `/auth/validate-key` | `X-API-Key` | Sin cambios (contrato SAIlus) |
| GET | `/auth/validate-token` | Bearer Sanctum | **Nuevo** (no reutiliza `validate-key`) |
| GET | `/me` | sanctum | **Nuevo** |
| GET | `/me/apps` | sanctum | **Nuevo** |
| GET | `/me/apps/{slug}/permisos` | sanctum | **Nuevo** |
| GET | `/usuarios/{id}/apps` | sanctum + `rbac` | **Nuevo** (admin) |
| POST | `/usuarios/{id}/apps` | sanctum + `throttle-mutations` | **Nuevo** (admin) |
| DELETE | `/usuarios/{id}/apps/{app_id}` | sanctum + `throttle-mutations` | **Nuevo** (admin) |

**Contrato `validate-token` (consumido por BRP):**

Request: `GET /api/v1/auth/validate-token` con `Authorization: Bearer <token>`.

Response 200:
```json
{ "success": true, "data": { "valid": true, "usuario_id": 42, "email": "...", "apps": [{"slug":"brp","rol":"psicologo","rol_id":5}], "permisos": ["brp.sesiones.marcar"], "cached": false, "validated_at": "2026-07-30T14:23:45Z" } }
```

Response 401 (token inválido/expirado/revocado):
```json
{ "success": false, "error": "invalid_token", "message": "..." }
```

**Cache:** `auth:validate_token:{sha256(token)}` con TTL 300s (5 min). Header `X-Cache: HIT|MISS` para debug. **Bypass super-admin:** usuario con rol `es_super_admin=true` accede a cualquier app sin fila en `usuario_app`.

### 3.4 Pre-Migrations (BLOQUEANTES — corren antes de cualquier feature code)

Estas 3 correcciones de schema son prerequisito del feature code. Sin ellas, los seeders y endpoints fallan.

**PM-1: `add_identificacion_columns_to_contacto_table`**
- Agrega `identificacion_tipo` y `identificacion_numero` (ambas NULL).
- No backfilea aún — eso va en una migración de datos posterior.

**PM-2: `add_slug_and_es_super_admin_to_roles_table`**
- Agrega `slug` (NULL UNIQUE) y `es_super_admin` (BOOLEAN DEFAULT FALSE).
- Data migration en el `up()`: 
  - `UPDATE roles SET slug = LOWER(REPLACE(nombre, ' ', '-')) WHERE slug IS NULL`.
  - `UPDATE roles SET es_super_admin = TRUE WHERE id = 1` (SuperAdmin existente).
- **Implementación:** usar `DB::statement('ALTER TABLE roles …')` (no `doctrine/dbal` instalado, ver nota explore §6.2 #5).

**PM-3: `backfill_identificacion_and_link_personas` (data migration)**
- Inserta `personas` por cada `contacto` activo usando `email_contacto` como proxy de identidad (las columnas `identificacion_*` quedan NULL por ahora — la PRD no exige llenarlas en MVP).
- Actualiza `contacto.persona_id` por match de `email_contacto`.
- Dry-run en log: contar `INSERT`/`UPDATE` antes de commit.

> **Decisión de diseño (pre-resuelta):** las columnas `identificacion_*` en `contacto` se agregan como nullable para preservar los 2 828 registros existentes. La unicidad en `personas.identificacion_numero` es parcial (solo cuando no NULL).

### 3.5 Implementation Phases

| Fase | Días | Entregables |
|---|---|---|
| **1. Schema** | 1-2 | Pre-migrations §3.4 + tablas nuevas (`apps`, `usuario_app`, `personas`) + FK en `contacto` + models Eloquent + factories + seeders (`AppsSeeder`, `BrpRolesSeeder`, `UsuarioAppSeeder`) |
| **2. Multi-app access** | 3-4 | `EnsureUserHasAppMiddleware` (con alias `has-app:slug`) + `MeController` (`/me`, `/me/apps`, `/me/apps/{slug}/permisos`) + `LoginUseCase` modificado (incluye apps) + `LoginResponse` DTO extendido + tests de los 4 casos seed (Vos/Lorena/Patricia/Jaime) |
| **3. Token validation + admin** | 5 | `ValidateTokenController` + `ValidateTokenUseCase` + `CachedTokenValidator` (TTL 5min, key prefix `auth:validate_token:{hash}`) + `UsuarioAppController` admin (CRUD) + tests Feature/Unit + verificación E2E con BRP (mock) |

---

## 4. Risks

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | Migración `contacto → personas` pierde datos | Baja | Alto | Backup pre-migración, dry-run log, test con dataset real (2 828 contactos), UNIQUE constraint en `email_contacto` evita duplicados |
| R2 | Token cacheado sigue válido 5min tras revocación | Baja | Medio | Trade-off aceptado (DA-5). TTL corto + bypass `es_super_admin` para emergencias |
| R3 | `validate-token` degrada con miles de req/s | Media | Alto | Cache obligatorio desde día 1 (`auth:validate_token:{hash}`); load test antes de release |
| R4 | Soft-delete en `contacto` colisiona con Party Model | Baja | Bajo | Diferido a change posterior (DA-7) |
| R5 | Roles mal asignados → acceso cross-app no autorizado | Baja | Alto | Tests de autorización estrictos en cada endpoint admin; `rbac` middleware en admin; bypass solo con `es_super_admin=TRUE` |
| R6 | Acoplamiento con BRP retrasa este PRD | Media | Alto | Contrato público freezeado en §3.3; BRP puede mockear `validate-token` en dev mientras se implementa |
| R7 | **NEW** PRD usa user IDs `(1,2,3,4)` pero la realidad es `(5,1,4,3)` | Alta | Alto | Seed usa los IDs reales de MariaDB documentados en explore §4 |
| R8 | **NEW** `validate-key` y `validate-token` colisionan en nombre | Media | Alto | Rutas separadas (`/auth/validate-key` vs `/auth/validate-token`); cache prefix distinto |
| R9 | **NEW** BRP PRD todavía referencia `validate-key` | Alta | Medio | Downstream coordination item (§5): actualizar BRP PRD antes del release |
| R10 | **NEW** `composer test` requiere MySQL (no SQLite como dice AGENTS.md) | Media | Bajo | Documentar en AGENTS.md + verificar MySQL corriendo antes de CI |
| R11 | **NEW** Test suite con `StrictTDD` requiere escribir tests ANTES de cada feature | Baja | Bajo | Phase 2 y 3 adoptan red-green-refactor desde el primer commit |

---

## 5. Downstream Coordination Items

Estos puntos requieren acción fuera del scope de este cambio:

1. **BRP PRD** (`Asistencia BRP/Back-BRP/Docs/PRD-BRP.md`) **debe actualizarse** antes del release:
   - Cambiar todas las referencias a `validate-key` por `validate-token`.
   - Confirmar el header `Authorization: Bearer <token>` (NO `X-API-Key`).
   - El shape de respuesta se mantiene (`{valid, usuario_id, email, apps, permisos, cached, validated_at}`) — no cambia.
   - **Owner:** equipo BRP. **Bloqueante para producción** (BRP no puede integrar contra el endpoint equivocado).

2. **`AGENTS.md` del repo CRM** — agregar al stack section:
   - Auth flow multi-app + bypass `es_super_admin`.
   - Nuevo endpoint `/auth/validate-token` (distinguir de `/auth/validate-key`).
   - Aclarar que `composer test` requiere MySQL (corregir nota desactualizada sobre SQLite).

3. **`Modules/Shared/Database/Seeders/`** — si existen seeders de permisos/roles allí, asegurar que `BrpRolesSeeder` no duplique los 3 roles BRP si ya existen.

4. **DatabaseSeeder.php** — agregar `AppsSeeder`, `BrpRolesSeeder`, `UsuarioAppSeeder` a la cadena para que `migrate:fresh --seed` produzca un entorno listo para demos BRP.

5. **Caché en producción** — verificar que el driver `database` (default) soporta TTL de 300s. Si se cambia a `redis`, ajustar `config/cache.php`.

---

## 6. Acceptance Criteria

El cambio está **done** cuando:

- [ ] Las 3 pre-migrations corren sin error en DB fresca (`migrate:fresh`) **y** en DB con 2 828 contactos existentes.
- [ ] `GET /auth/validate-token` retorna shape correcto para los 4 casos: token válido / inválido / expirado / sin apps asignadas.
- [ ] `GET /me/apps` retorna apps correctas para los 4 usuarios seed (Vos=6, Lorena=1, Patricia=2, Jaime=2).
- [ ] Cache `auth:validate_token:{hash}` hit/miss funciona: 1ª llamada MISS, 2ª llamada HIT dentro de 300s, MISS después de TTL expirar.
- [ ] Los 4 casos seed en `UsuarioAppSeeder` matchean exactamente lo documentado en §3 (no los IDs literales del PRD).
- [ ] `POST /usuarios/{id}/apps` rechaza si `app_id` o `rol_id` no existen (FormRequest validation).
- [ ] `DELETE /usuarios/{id}/apps/{app_id}` borra solo la asignación correcta.
- [ ] Bypass `es_super_admin=TRUE` permite al rol 1 (SuperAdmin) acceder a cualquier app sin fila en `usuario_app`.
- [ ] Todos los tests pasan: `composer test` (MySQL up).
- [ ] Cero regresión en el flujo de auth existente (login/logout/forgot-password).
- [ ] Cero regresión en `/auth/validate-key` (SAIlus sigue funcionando con `X-API-Key`).

---

## 7. Open Questions

Estas preguntas deberían confirmarse con el usuario ANTES de implementar. Si el PRD es lo suficientemente explícito, las últimas 3 son opcionales.

1. **`persona_id` en `contacto`:** ¿el backfill por `email_contacto` debe ejecutarse automáticamente en `migrate`, o como comando artisan separado (`crm:backfill-personas` con `--dry-run`)? **Recomendado:** comando artisan con `--dry-run` por defecto para control fino sobre 2 828 filas.

2. **Bypass `es_super_admin`:** ¿solo aplica a `apps` o también a endpoints globales del CRM (ej. `/api/v1/admin/*`)? **Recomendado:** bypass universal — si `rol.es_super_admin=TRUE`, bypasea TODOS los checks `has-app:*` y `rbac`.

3. **Cache TTL `validate-token`:** ¿300s es aceptable o se requiere invalidación explícita al revocar token? **Recomendado:** 300s sin invalidación (DA-5 ya confirmado en PRD). Si se requiere invalidación, agregar listener `TokenRevoked` que haga `Cache::forget`.

4. **BRP roles en seed:** ¿`brp-admin`/`brp-lider`/`brp-psicologo` se crean con permisos vacíos (`permisos` = `[]`) o se les asignan permisos seed inmediatamente? **Recomendado:** crearlos con permisos vacíos; el seeding de permisos BRP vive en otro change.

5. **Migración de `contacto.identificacion_*`:** ¿se llenan los datos de alguna fuente externa (CSV, API) en este change, o se quedan NULL? **Recomendado:** NULL en MVP; llenado manual posterior.

---

## Appendix A: Decisiones arquitectónicas pre-resueltas

Estas decisiones vienen del explore y NO requieren re-evaluación:

| # | Decisión | Razón |
|---|---|---|
| DR-1 | `contacto` recibe `identificacion_tipo` + `identificacion_numero` (nullable) | PRD §6.3 referencia columnas inexistentes; se agregan como nullable para preservar 2 828 filas |
| DR-2 | `roles` recibe `slug` + `es_super_admin` (con data migration) | BRP PRD §13 y DA-3 dependen de estas columnas |
| DR-3 | Endpoint nuevo `/auth/validate-token` (NO reutilizar `/auth/validate-key`) | `/auth/validate-key` ya existe con `X-API-Key` (SAIlus); reutilizar rompería SAIlus |
| DR-4 | Seed usa IDs reales de MariaDB (Vos=5, Lorena=1, Patricia=4, Jaime=3) | PRD §6.2 literales no matchean con DB real |
| DR-5 | Cache prefix `auth:validate_token:{hash}` (no `auth:validate:{hash}`) | Evita colisión con cualquier cache futuro de SAIlus |

---

## Appendix B: Glossary

- **Party Model:** patrón donde una entidad (`Persona`) representa la identidad humana centralizada, y otras tablas (`contacto`, `colaborador`, `proveedor`) la referencian opcionalmente.
- **Multi-app access:** capacidad de un usuario de pertenecer a N aplicaciones con roles distintos por app, con un único login.
- **Pivot `usuario_app`:** tabla intermedia N:M entre `usuarios` y `apps` con un `rol_id` por asignación.
- **Bypass `es_super_admin`:** flag en `roles` que exime al usuario de la verificación `has-app:*` y `rbac` (DA-3).
- **Cache key `auth:validate_token:{hash}`:** clave de cache donde `{hash}` es SHA-256 del token Bearer entrante (NO del token hasheado en DB — Sanctum presenta unhashed al emitir).

---

**Próximo paso:** aprobación de esta propuesta → `sdd-spec` (escribir specs Given/When/Then) o `sdd-design` (decisiones técnicas detalladas) — ver decisión del orchestrator.