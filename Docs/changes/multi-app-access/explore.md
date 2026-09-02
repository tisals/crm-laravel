# Exploration: Multi-app Access & Party Model

> **Phase:** explore
> **Date:** 2026-07-30
> **Investigation target:** `crm-laravel` (CRM Tecnoinnsoft) — preparing for `multi-app-access` change

---

## Executive Summary

The CRM has a **dual layer** for models: `App\Models\*` (deprecated thin wrappers extending the canonical `Modules\Shared\Models\*` and `Modules\CRM\Models\*`). Auth is Sanctum-based with a clean `LoginUseCase → LoginResponse` pattern. **Two critical conflicts with the PRD exist** and must be resolved before implementation: (1) the `contacto` table is **missing both `identificacion` and `tipo_id` columns** that the PRD's backfill SQL references, and (2) the `roles` table **lacks `slug` and `es_super_admin` columns** that the BRP roles seeder and super-admin flag depend on. The existing `GET /api/v1/auth/validate-key` endpoint uses `X-API-Key` (SAIlus bot pattern) — adding a Bearer-token variant means the endpoint must distinguish by header (or we add `/auth/validate-token` as a second endpoint). The natural home for `apps`, `usuario_app`, and `personas` is `Modules/Shared` (cross-cutting identity), with the `EnsureUserHasApp` middleware living in `app/Infrastructure/Auth/` to match existing conventions. **Recommended next phase: `propose`** — there is enough signal to write a proposal, but the two schema-conflicts must be flagged as design decisions first.

---

## Section 1: Current Auth Architecture

### Login flow

- **Endpoint:** `POST /api/v1/auth/login` → `App\Http\Controllers\API\AuthController::login` (`routes/api.php:58`)
- **Rate limit:** `throttle:auth`
- **Public (no auth):** `POST /auth/login`, `POST /auth/forgot-password`, `POST /auth/reset-password`, `GET /auth/validate-key` (uses `X-API-Key`)
- **Use case:** `app/Application/UseCases/Auth/LoginUseCase.php`
  ```php
  $usuario = Usuario::where('email', $request->email)->first();
  // checks estado === 'Activo'
  // verifies password with password_verify() against password_hash
  $token = $usuario->createToken('auth-token')->plainTextToken;
  return new LoginResponse(token: $token, usuario: $usuario);
  ```
- **Response DTO:** `App\Application\DTOs\LoginResponse.php` — currently returns `{ token, usuario: { id, nombre, email, rol_id, estado } }`. **NO `apps` field today** — needs to be modified per PRD §7.3.

### Token issuance

- **Mechanism:** Laravel Sanctum `HasApiTokens` trait on `Modules\Shared\Models\Usuario`
- **Table:** `personal_access_tokens` (Laravel default, created by Sanctum's published migration `2026_05_04_030644`)
- **CLI helper:** `app/Console/Commands/GenerateApiToken.php` (`crm:generate-token --email=...`) — creates a `Usuario` if not exists and issues a token named `fastapi-access`.

### Middleware stack (registered in `bootstrap/app.php`)

| Alias | Class | Purpose |
|-------|-------|---------|
| `api-key` | `App\Infrastructure\Auth\ValidateApiKeyMiddleware` | X-API-Key header validation |
| `rbac` | `App\Infrastructure\Auth\RbacMiddleware` | Route-level RBAC check via `RbacService` |
| `api-logger` | `App\Http\Middleware\ApiAccessLogger` | Logs all API hits |
| `extract-token` | `App\Http\Middleware\ExtractTokenFromQuery` | Allows Bearer token via query param (`?token=…`) for ICS downloads |
| `throttle-mutations` | `App\Http\Middleware\ThrottleMutations` | Rate-limits POST/PUT/DELETE separately |

**Important:** middleware used inside routes (`auth:sanctum`, `rbac`, `throttle-mutations`) is applied to route groups in `routes/api.php`. New middleware `EnsureUserHasApp` should follow the same alias pattern in `bootstrap/app.php`.

### Existing `/auth/validate-key` endpoint (CRITICAL CONFLICT)

- **Path:** `GET /api/v1/auth/validate-key` → `AuthController::validateKey`
- **Auth:** `X-API-Key` header (NOT Bearer)
- **Use case:** `App\Application\UseCases\ValidateApiKeyUseCase` — looks up `Entidad::where('dominio', $apiKey)`
- **Response shape:** `{ valid: true, bot_id, name, permissions: [] }`
- **Spec:** `openspec\specs\validate-api-key\spec.md` documents this contract.
- **CONFLICT with PRD §13:** BRP expects `Authorization: Bearer <sanctum-token>` and a different response shape (`{valid, usuario_id, email, apps, permisos, cached, validated_at}`).
- **Resolution paths:**
  - **(A)** Add a SECOND endpoint, e.g. `GET /api/v1/auth/validate-token` (cleanest — no contract break for SAIlus). **Recommended.**
  - (B) Modify `validate-key` to detect header type and branch (dual-purpose endpoint, contract risk).
  - (C) Rename SAIlus endpoint to `/sa-i-lus/validate-key` and reuse `/auth/validate-key` for Bearer (breaks SAIlus).

### Sanctum config

`config/sanctum.php` — `expiration` is `null` (tokens don't expire), `token_prefix` is empty. Token storage in DB.

---

## Section 2: Module Inventory

The project uses `nwidart/laravel-modules 12.0` with **4 modules**:

| Module | Path | Migrations | Models | Controllers | Has tests? | Purpose |
|--------|------|------------|--------|-------------|-----------|---------|
| `Shared` | `Modules/Shared/` | **0** (all migrations live in `database/migrations/` globally) | **3** (`Usuario`, `Rol`, `Permiso`) | 1 (`SharedController` stub) | 0 | **Cross-cutting identity**: holds canonical `Usuario`, `Rol`, `Permiso` models. App\Models\* inherits from these. |
| `CRM` | `Modules/CRM/` | 0 globally | **7** (`Contacto`, `Oportunidad`, `Pipeline`, `PipelineEtapa`, `Seguimiento`, `DetalleOportunidad`, `Etiqueta`) | **7** (Bulk, Contacto, CRM, Pipeline, PipelineEtapa, SailusWebhook controllers) | ✓ extensive (`tests/Feature/API/*Contact*Op*Pipeline*`) | Core CRM domain: contacts, opportunities, pipelines, followups. Clean Architecture layers (Domain/Application/Infrastructure/Http). |
| `Administrativo` | `Modules/Administrativo/` | 0 globally | **8** (`Colaborador`, `Cuenta`, `DetalleServicio`, `LugarEntidad`, `Movimiento`, `OrdenServicio`, `Proveedor`, `Servicio`) | 1 stub | 0 | Operations + Finance: collaborator registry, services, orders, accounts, suppliers, places. |
| `Proyectos` | `Modules/Proyectos/` | 0 globally | 0 | 1 stub (`ProyectosController`) | 0 | Effectively empty/placeholder. |

**Note on AGENTS.md:** Lists only Shared/CRM/Administrativo — `Proyectos` is a fourth module but is essentially empty. Worth noting for the user.

**Shared vs global:**
- The `app\Models\*` folder contains **duplicates** of every model in `Modules\Shared`, `Modules\CRM`, and `Modules\Administrativo`. They are all 8-line `extends` aliases marked `@deprecated`. This is a transitional state; new code should use the module namespace.
- All migrations live in `database/migrations/` (root) — not per-module. Module-local migration folders are empty `.gitkeep`.

---

## Section 3: Recommended Module Location

**Recommended: extend `Modules/Shared`** — do NOT create a new `Core` or `Identity` module.

**Rationale:**

1. **`apps` and `usuario_app` are pure identity/auth infrastructure** — they sit at the same conceptual layer as `usuarios`, `roles`, `permisos` already in `Shared`. Putting them in `CRM` or `Administrativo` would couple them to a domain.
2. **`personas` is the new party-model counterpart to `usuarios`** — humans are the shared identity primitive across all apps. Co-locating with `usuarios` is natural.
3. **Avoids the nwidart autoload ceremony** — `Modules/Proyectos` is a cautionary tale: it has its own `composer.json` but no models/migrations, so it's effectively a placeholder. Adding a 5th module for ~3 tables is overkill.
4. **Consistent with existing pattern** — `Modules/Shared` already has 0 migrations (everything is global), 0 module-local DB seeders, and only 3 models. It's a thin re-export layer. Adding 3 more tables and 3 more models is a small extension.
5. **No namespace collision concerns** — none of `apps`, `usuario_app`, `personas` would conflict with `Modules/CRM\Models\Contacto` or `Modules\Administrativo\Models\Proveedor`.

**Where things should live:**

| Asset | Location |
|-------|----------|
| `apps` table migration | `database/migrations/2026_07_30_120000_create_apps_table.php` |
| `usuario_app` migration | `database/migrations/2026_07_30_120001_create_usuario_app_table.php` |
| `personas` migration | `database/migrations/2026_07_30_120002_create_personas_table.php` |
| `contacto.persona_id` migration | `database/migrations/2026_07_30_120003_add_persona_id_to_contacto_table.php` |
| `roles.slug` + `es_super_admin` migration | `database/migrations/2026_07_30_120004_add_slug_and_super_admin_to_roles_table.php` |
| `App` model | `Modules/Shared/app/Models/App.php` |
| `UsuarioApp` (pivot) model | `Modules/Shared/app/Models/UsuarioApp.php` |
| `Persona` model | `Modules/Shared/app/Models/Persona.php` |
| `AppRepository` (Eloquent) | `app/Infrastructure/Persistence/EloquentAppRepository.php` (matches existing pattern; can move to module later) |
| `AppController` (HTTP) | `app/Http/Controllers/API/AppController.php` (for `/me/apps`) |
| `UsuarioAppController` (admin) | `app/Http/Controllers/API/UsuarioAppController.php` |
| `EnsureUserHasApp` middleware | `app/Infrastructure/Auth/EnsureUserHasAppMiddleware.php` |
| `ValidateApiKeyTokenUseCase` | `app/Application/UseCases/Auth/ValidateApiKeyTokenUseCase.php` (rename: `ValidateTokenUseCase` would be clearer) |
| `LoginUseCase` (modify) | `app/Application/UseCases/Auth/LoginUseCase.php` — add apps to response |
| `AppsSeeder` + `BrpRolesSeeder` | `database/seeders/` (same place as `RoleSeeder`, `PermisoSeeder`) |
| `CachedTokenValidator` | `app/Infrastructure/Auth/CachedTokenValidator.php` — wraps the cache logic |
| Feature tests | `tests/Feature/API/MeAppsEndpointTest.php`, `tests/Feature/API/ValidateKeyTokenTest.php`, `tests/Feature/API/UsuarioAppAdminTest.php` |
| Spec files | `openspec/changes/multi-app-access/specs/*.md` |

---

## Section 4: Existing Tables — Critical Info

Queried via PDO against the **crm-laravel-dev docker** container (MariaDB, `tecnoinnsoft_crm` database — closest to production):

### `usuarios` — 5 rows
- **Latest migration:** `2026_05_06_000003_create_usuarios_table.php`
- **Columns:** `id`, `nombre`, `email` (UNIQUE), `password_hash`, `rol_id` (FK→roles), `estado`, `created_by`, `updated_by`, timestamps, `deleted_at`
- **Actual seeded users:**
  - `1 - innovacionydesarrollo.tis@gmail.com` (rol=2 Comercial)
  - `2 - gestorcomercial.tis@gmail.com` (Lorena, rol=2)
  - `3 - direccion.tis@gmail.com` (Jaime, rol=2)
  - `4 - servicioalcliente.tis@gmail.com` (Patricia, rol=2)
  - `5 - admin@tecnoinnsoft.dev` (rol=1 SuperAdmin)
  - **Note:** The PRD lists the 4 named users with different IDs (vos=1, Lorena=2, Patricia=3, Jaime=4). In reality IDs are scrambled. The seed script `UsuariosTableSeeder.php` assigns the canonical 4 commercial users but in a different order. **PRD §6 seed insert should use the actual seeded IDs.**

### `contacto` — **2 828 rows** (significant data)
- **Latest structural migration:** `2026_05_06_000011_create_contacto_table.php`
- **Subsequent patches:**
  - `2026_05_15_000001_add_diagnostico_data_to_contacto_table.php`
  - `2026_07_21_000001_make_apellidos_nullable_in_contacto_table.php`
- **Columns (live DESCRIBE):**
  - `id`, `entidad_id`, `nombres`(NOT NULL), `apellidos`(NULLABLE per the Jul 21 patch), `area`, `cargo`, `tel_contacto`, `movil`, `email_contacto`, `email_secundario`, `rol`, `etapa`, `estado`, `score`, `diagnostico_data` (JSON), `fuente`, `created_by`, `updated_by`, timestamps, `deleted_at`
  - **`UNIQUE(entidad_id, email_contacto)` constraint exists.**
- **🚨 CRITICAL: `contacto` does NOT have `identificacion` or `tipo_id` columns.**
  - The PRD §6.3 backfill SQL is **broken as written**:
    ```sql
    -- PRD says:
    SELECT nombres, apellidos, email, telefono, tipo_id, identificacion FROM contacto
    -- Reality: column is `email_contacto`, not `email`; `tipo_id` and `identificacion` do NOT exist.
    ```
  - **Mitigation:** Either add `identificacion_tipo` + `identificacion_numero` to `contacto` first (and backfill from elsewhere or leave NULL), OR write the backfill using only existing columns (`email_contacto`, `tel_contacto`).

### `proveedores` — 0 rows locally, but the table exists
- `Modules/Administrativo\Models\Proveedor` lives here. PRD explicitly says "**NO se migran en MVP**" — correct decision.

### `colaboradores` — 0 rows locally, but the table exists
- `Modules\Administrativo\Models\Colaborador`. **NO se migran en MVP** per PRD §6.3.

### `roles` — 4 rows
- **Latest migration:** `2026_05_06_000001_create_roles_table.php`
- **Columns:** `id`, `nombre`, `estado`, `created_by`, `updated_by`, timestamps, `deleted_at`
- **🚨 CRITICAL: `roles` does NOT have `slug` or `es_super_admin` columns.**
  - The PRD §8 says `roles.es_super_admin` is a boolean flag used to bypass checks.
  - The PRD §13 BRP roles seeder uses `INSERT INTO roles (slug, nombre, es_super_admin) VALUES (…)` — **this INSERT will fail against current schema.**
  - **Mitigation:** Add migration `2026_07_30_..._add_slug_and_super_admin_to_roles.php` with:
    ```php
    Schema::table('roles', function (Blueprint $t) {
        $t->string('slug', 50)->nullable()->unique()->after('nombre');
        $t->boolean('es_super_admin')->default(false)->after('slug');
    });
    // Backfill slug from nombre (kebab-case)
    DB::statement("UPDATE roles SET slug = LOWER(REPLACE(nombre, ' ', '-')) WHERE slug IS NULL");
    // Mark rol 1 (SuperAdmin) as super-admin
    DB::table('roles')->where('id', 1)->update(['es_super_admin' => true]);
    ```
- **Actual roles seeded:**
  - `1 - SuperAdmin` (rol_id referenced by admin@tecnoinnsoft.dev)
  - `2 - Comercial` (Lorena, Patricia, Jaime)
  - `3 - Operaciones`
  - `4 - Finanzas`
  - **NO** `brp-admin`, `brp-lider`, `brp-psicologo` yet — must seed.

### `permisos` — 295 rows
- **Latest migration:** `2026_05_06_000002_create_permisos_table.php`
- Schema: `id, rol_id (FK→roles), vista, …`
- 295 rows means role 1 has `vista='*'` (super-permission), and others have per-route permissions.
- **PRD §8:** "Permisos granulares (opcional en MVP)". Current implementation already supports these — `RbacService` queries `permisos` table via `PermisoRepositoryInterface`. **No changes needed for MVP.**

### `entidad` — 2 518 rows
- Tenant/business entity table. Has `dominio` (used by existing `ValidateApiKeyUseCase`).
- Has `tipo_persona ENUM('Natural','Juridica')` and `identificacion VARCHAR(50) UNIQUE` (already nullable per `2026_05_25_192752`).
- **Out of scope** for this change.

### `entidad_usuario` — 2 054 rows
- Pivot: `entidad_id × usuario_id` with `created_at`, `updated_at`. Composite PK.
- Seeded by `UsuariosTableSeeder` to round-robin distribute client entities to the 4 commercial users.
- **Out of scope** for this change.

### `personal_access_tokens` — 1 row in prod, 0 rows locally
- Default Sanctum table. Only the `crm@tecnoinnsoft.dev` FastAPI service-account has a long-lived token in prod.

### `apps`, `usuario_app`, `personas` — **DO NOT EXIST** (confirmed in both local SQLite and prod MariaDB)

---

## Section 5: Test Patterns

**Canonical reference file:** `tests/Feature/API/AuthTest.php` (113 lines, very clean)

### Convention checklist (apply verbatim to new tests)

1. **Namespace:** `Tests\Feature\API`
2. **Imports:** Use canonical module models: `use Modules\Shared\Models\Usuario;` (NOT `App\Models\Usuario`). But for tests, `App\Models\Rol` and `App\Models\Usuario` are both commonly imported (compatible via `extends`).
3. **RefreshDatabase trait:** Always `use Illuminate\Foundation\Testing\RefreshDatabase;`
4. **Attributes:** Modern PHPUnit: `#[PHPUnit\Framework\Attributes\Test]` above each test method
5. **Auth helper:** Local private method `authenticate()` or `createAdminUser()` returns `['usuario', 'token']`:
   ```php
   private function createAdminUser(): array {
       $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
       Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);
       $usuario = Usuario::create([
           'nombre' => 'Admin User',
           'email' => 'admin@test.com',
           'password_hash' => bcrypt('password123'),
           'rol_id' => $rol->id,
           'estado' => 'Activo',
       ]);
       return ['usuario' => $usuario, 'token' => $usuario->createToken('test-token')->plainTextToken];
   }
   ```
6. **Sanctum auth pattern:** `$this->withHeader('Authorization', 'Bearer ' . $token)->postJson(...)`
7. **Assertions:** `$response->assertStatus(200)->assertJsonPath('success', true)->assertJsonStructure([...])`
8. **Factories:** `ContactoFactory`, `UsuarioFactory`, `RolFactory`, `PermisoFactory` exist in `database/factories/`. New `AppFactory`, `UsuarioAppFactory`, `PersonaFactory` should follow the same pattern.
9. **Running tests:** `composer test` (`@php artisan config:clear && @php artisan test`) or `php artisan test`. Test DB uses **MySQL** in `phpunit.xml` (NOT SQLite as AGENTS.md says — that's incorrect/outdated): `DB_CONNECTION=mysql`, `DB_DATABASE=crm_testing`, `DB_USERNAME=root`, `DB_PASSWORD=sailus_root_dev`. ⚠️ **This means `composer test` requires MySQL running**; verify in target environment.
10. **STRICT TDD MODE:** Per `openspec/config.yaml` `strict_tdd: true` — write tests first; red-green-refactor cycle is mandatory.

### Migration test convention

Per `tests/Feature/Migration/` and `tests/Feature/Seeders/` directories exist. Migrations are typically tested implicitly via `RefreshDatabase`. No stand-alone "test the migration ran" pattern observed.

### Use case test convention

`tests/Feature/UseCases/StoreSeguimientoUseCaseTest.php` shows direct use-case invocation (no HTTP). For the new use cases (`ValidateApiKeyTokenUseCase`, `LoginUseCase` modified), mirror this pattern.

---

## Section 6: Risks & Gotchas Found

### Critical (block implementation until resolved)

1. **`contacto` has no `identificacion`/`tipo_id` columns.** PRD §6.3 backfill SQL references these. Either add the columns first OR rewrite the backfill to use existing columns. The backfill is needed to map contactos → personas, but the PRD example hard-codes a column layout that doesn't exist.

2. **`roles` has no `slug` or `es_super_admin` columns.** PRD §8 depends on `es_super_admin` flag; PRD §13 BRP roles INSERT references `slug`. Both need a single `add_slug_and_super_admin_to_roles_table.php` migration BEFORE the BRP roles seeder can run.

3. **`/auth/validate-key` endpoint collision.** Existing endpoint uses `X-API-Key` (SAIlus bot contract per `openspec\specs\validate-api-key\spec.md`). PRD wants Bearer Sanctum token with a different response shape. Resolution: add a separate endpoint (recommend `GET /api/v1/auth/validate-token`), don't reuse `/auth/validate-key`.

### High

4. **PRD seed assigns user IDs `(1, 2, 3, 4)` to (vos, Lorena, Patricia, Jaime).** Actual seeded IDs are `(5, 1, 3, 2)` per the live MariaDB. Seed SQL must use the real user IDs, not the literal PRD numbers. Either map by email OR fix the PRD example.

5. **`UsuariosTableSeeder` is currently NOT in `DatabaseSeeder.php`.** It's only run manually (`composer setup` doesn't include it). This means a fresh `migrate:fresh --seed` won't produce the 4 commercial users — `validate-key` tests will fail on installation.

6. **`config/auth.php` bug per `Docs/DESPLIEGUE.md:1041`** — pre-existing: `App\Models\Usuario` is referenced but the deprecated wrapper may cause autoload issues in production. Verified: `App\Models\Usuario` extends `Modules\Shared\Models\Usuario` so it works, but `php artisan optimize` could strip the extends metadata. Flag for the proposal.

7. **PSD §6.3 says `apps.tipo` ENUM `('internal', 'external', 'customer')` but the same column in seeded usage appears in §13 BRP table without `customer`.** Minor — pick one set of values.

### Medium

8. **`Tests` flagged as using SQLite per AGENTS.md but `phpunit.xml` uses MySQL.** Update AGENTS.md or fix phpunit.xml. Local dev needs MySQL up to run `composer test`.

9. **Validation library not installed per the Jul 21 apellidos migration comment.** The CRM uses raw SQL (`DB::statement('ALTER TABLE…')`) when `->change()` is needed because no `doctrine/dbal`. If you alter `roles` columns with `->change()`, it will fail. Use raw SQL or add `doctrine/dbal`.

10. **Path-based module reuse:** New `Modules/Shared/app/Models/Persona.php` would conflict with `App\Models\Persona` if it ever existed (it doesn't). Safe to add.

11. **`ThrottleMutations` middleware only throttles mutations (POST/PUT/DELETE).** The new admin endpoints under `/usuarios/{id}/apps` should use this same group to inherit throttle protection.

### Low

12. **`apellidos` is now nullable** (Jul 21 patch). The PRD `personas.apellidos NOT NULL` is stricter than `contacto`. This means the backfill must handle contacto rows with null apellidos — either default to empty string OR add a PRD note that null apellidos personas will exist.

13. **Cache key collision:** `auth:validate:{token_hash}` in PRD §7.4 may collide with the existing `X-API-Key` cache (none currently exists but worth naming carefully). Suggest `auth:validate_token:{hash}`.

14. **`personal_access_tokens.token` is hashed.** To compute the cache key for `auth:validate:{token_hash}` you must hash the incoming Bearer token with SHA-256 BEFORE cache lookup. Laravel Sanctum stores hashed tokens but presents them unhashed via `plainTextToken`. Subtle: the request's `Authorization: Bearer X` token needs hashing to compare to the DB.

---

## Section 7: Recommended Implementation Approach

### High-level architecture

```
Routes (routes/api.php)
   ├── POST /api/v1/auth/login  → AuthController::login (MODIFIED to include apps)
   ├── POST /api/v1/auth/logout (unchanged)
   ├── POST /api/v1/auth/forgot-password (unchanged)
   ├── POST /api/v1/auth/reset-password (unchanged)
   ├── GET  /api/v1/auth/validate-key (unchanged — SAIlus pattern)
   ├── GET  /api/v1/auth/validate-token (NEW — Bearer pattern, BRP-facing)
   ├── GET  /api/v1/me  (NEW)
   ├── GET  /api/v1/me/apps  (NEW)
   ├── GET  /api/v1/me/apps/{slug}/permisos  (NEW)
   ├── GET  /api/v1/usuarios/{id}/apps  (NEW — admin)
   ├── POST /api/v1/usuarios/{id}/apps  (NEW — admin)
   ├── DELETE /api/v1/usuarios/{id}/apps/{app_id}  (NEW — admin)
   └── (existing /auth/validate-key used by SAIlus bots)
```

### Folder layout

```
database/migrations/
   2026_07_30_120000_add_slug_and_super_admin_to_roles_table.php    (CRITICAL — must run first)
   2026_07_30_120001_create_apps_table.php
   2026_07_30_120002_create_usuario_app_table.php
   2026_07_30_120003_create_personas_table.php
   2026_07_30_120004_add_persona_id_to_contacto_table.php
   2026_07_30_120005_backfill_personas_from_contacto.php            (data migration)
database/seeders/
   AppsSeeder.php          (idempotent — INSERT IGNORE pattern)
   BrpRolesSeeder.php      (uses new slug column; idempotent)
   UsuarioAppSeeder.php    (assigns the 4 named users to the 6 apps)
database/factories/
   AppFactory.php
   UsuarioAppFactory.php
   PersonaFactory.php

Modules/Shared/app/Models/
   App.php           (new — table: apps)
   UsuarioApp.php    (new — pivot with timestamps)
   Persona.php       (new)
# (alongside existing Usuario.php, Rol.php, Permiso.php)

app/Models/
   App.php           (deprecated wrapper extending Modules\Shared\Models\App)
   UsuarioApp.php    (deprecated wrapper)
   Persona.php       (deprecated wrapper)

app/Application/UseCases/Auth/
   LoginUseCase.php        (MODIFIED — return apps list in response)
   LogoutUseCase.php       (unchanged)
   ValidateTokenUseCase.php (NEW — for /auth/validate-token)
app/Application/UseCases/Auth/Me/
   GetMyAppsUseCase.php       (NEW — read-only, used by GET /me/apps)
   GetMyPermissionsUseCase.php (NEW — used by GET /me/apps/{slug}/permisos)
app/Application/UseCases/Auth/Admin/
   ListUsuarioAppsUseCase.php      (NEW — admin view)
   AssignUsuarioAppUseCase.php     (NEW — admin POST)
   RevokeUsuarioAppUseCase.php     (NEW — admin DELETE)

app/Http/Controllers/API/
   AuthController.php        (MODIFIED — login now returns apps)
   MeController.php          (NEW — for /me, /me/apps)
   UsuarioAppController.php  (NEW — admin endpoints)
   ValidateTokenController.php (NEW — /auth/validate-token)

app/Infrastructure/Auth/
   EnsureUserHasAppMiddleware.php        (NEW — accepts :slug param)
   ValidateTokenCacheService.php         (NEW — wraps Cache::remember())
   CachedAuthResult.php                  (NEW — value object)

app/Application/Services/
   CurrentUserAppsResolver.php           (NEW — convenience for controllers)

app/Http/Requests/
   AssignUsuarioAppRequest.php           (NEW — validates usuario_id, app_id, rol_id)
   ValidateTokenRequest.php              (NEW — minimal)

app/Http/Resources/
   AppResource.php                       (NEW — apps list shape)
   MeResource.php                        (NEW — /me shape)

tests/Feature/API/
   ValidateTokenEndpointTest.php         (NEW — success, cache hit, invalid token)
   MeAppsEndpointTest.php                (NEW — 200 with apps list, 401)
   MeAppPermisosEndpointTest.php         (NEW)
   UsuarioAppAdminAssignTest.php         (NEW)
   LoginResponseIncludesAppsTest.php     (NEW — modify existing AuthTest? better: separate)
tests/Unit/Application/UseCases/Auth/
   LoginUseCaseIncludesAppsTest.php      (NEW — pure unit, no HTTP)
   ValidateTokenUseCaseTest.php          (NEW — cache hit/miss logic)
   EnsureUserHasAppMiddlewareTest.php    (NEW)
tests/Unit/Infrastructure/Auth/
   CachedTokenValidatorTest.php          (NEW — TTL, key collision)

openspec/changes/multi-app-access/
   explore.md                            (this file)
   proposal.md                           (next phase — orchestrator will create)
   design.md                             (next phase)
   tasks.md                              (next phase)
   specs/
      multi-app-access.md                (next phase: Given/When/Then scenarios)
```

### Implementation phasing (matches PRD §9)

**Phase 1 — Schema (Day 1-2):**
- Migrations: `add_slug_and_super_admin_to_roles` (FIRST — needed for everything), `create_apps`, `create_usuario_app`, `create_personas`, `add_persona_id_to_contacto`, `backfill_personas_from_contacto`
- Models: `App`, `UsuarioApp`, `Persona` + `AppFactory`, `PersonaFactory`
- Seeders: `AppsSeeder`, `BrpRolesSeeder`, `UsuarioAppSeeder` (vos=5, Lorena=1, Patricia=4, Jaime=3, admin=5)
- Unit tests for the pivot and migration shape

**Phase 2 — Multi-app access (Day 3-4):**
- Modify `LoginUseCase` + `LoginResponse` to include apps
- `EnsureUserHasAppMiddleware` + alias
- `ValidateTokenController` + `ValidateTokenUseCase` + `CachedTokenValidator` (5min TTL)
- `MeController` for `/me` and `/me/apps`
- Tests for the 4 named user cases
- **Hold-up check:** BRP can start integrating against `/auth/validate-token` once Phase 2 ships.

**Phase 3 — Admin + seeding (Day 5):**
- `UsuarioAppController` (CRUD endpoints), `AssignUsuarioAppUseCase` etc.
- Admin tests + R5 risk (strict role checks)
- Existing-data validation: confirm seed assigns match real user IDs.

### Decisions for the orchestrator to push back to the user

1. **Should the existing `/auth/validate-key` be renamed/repurposed, or should we add `/auth/validate-token`?**
   - I recommend adding the new endpoint.
2. **Should `contacto` get `identificacion` and `tipo_id` columns added as part of this change, or is the persona backfill done via `email_contacto` only?**
   - I recommend adding the columns (matches BRP-style identification) and writing a one-time CSV/script to populate them.
3. **Where do `Roles` `slug` and `es_super_admin` come from? Add as part of this change?**
   - I recommend YES — without them the BRP roles can't be seeded and `super-admin` bypass can't be implemented.
4. **Should the 4 commercial users be re-created with the PRD's stated IDs, or do we accept their actual IDs and document the mapping?**
   - I recommend accepting reality: use the actual seeded IDs and document the email→ID map in the seeder.
5. **`apps` table location: shared DB migration vs. module-specific?** PRD says modules — but existing convention is global migrations. Recommend global (less disruption).

---

## Files Changed Index (informational)

| Touched by this exploration | Note |
|--------------------------|------|
| `D:\sitios desarrollo\crm-laravel\openspec\changes\multi-app-access\explore.md` | **Created** — this report |
| `engram:mem_save` | Saved summary observation |

No source code was modified. Existing code paths documented for `sdd-design` and `sdd-spec` to act on.
