# Tasks: Multi-app Access & Party Model

> **Phase:** tasks
> **Date:** 2026-07-30
> **Change:** `multi-app-access`
> **Mode:** Strict TDD — every task is RED (test first) → GREEN (impl) → REFACTOR
> **Driver:** Banco de Bogotá — BRP must authenticate psicólogos against the CRM in ≤15 days

---

## Conventions

- **Task ID format:** `<PHASE>-<NUMBER>` (e.g., `PM-1`, `SCH-2`, `E-3`)
- **Phases:** PM (Pre-Migrations), SCH (New Schema), SE (Seeders), E (Endpoints), M (Middleware), T (Tests/E2E), V (Verification)
- **Strict TDD cycle per task:**
  1. 🔴 **RED** — Write the test FIRST in `tests/...`. Confirm it fails for the right reason.
  2. 🟢 **GREEN** — Implement the minimum code to pass the test.
  3. 🧹 **REFACTOR** — Clean up duplication, add type hints, run Pint.
- **Status:** `[ ]` pending · `[~]` in progress · `[x]` done · `[!]` blocked
- **Dependencies:** Listed in each task. A task cannot start until all `Depends on` are `[x]`.
- **Test DB:** MySQL (per `phpunit.xml`). Run with `composer test` or `php artisan test`.
- **TDD rule:** NEVER write production code before the failing test exists. Commit test + impl together only when green.
- **All migrations live in `database/migrations/`** (global, per existing convention).
- **All new Eloquent models live in `Modules/Shared/app/Models/`** with deprecated wrappers in `app/Models/`.

---

## Dependency Graph

```
PM-1 (roles slug/super_admin) ─┐
PM-2 (personas table)         ─┼─→ PM-3 (contacto.persona_id + identificacion)
PM-4 (backfill command)       ─┘         │
                                         ▼
                              SCH-1 (apps table) ──→ SCH-2 (usuario_app pivot)
                                                      │
                                                      ▼
                                              SCH-3 (Eloquent models)
                                                      │
                                ┌─────────────────────┼──────────────────────┐
                                ▼                     ▼                      ▼
                          SE-1 (AppsSeeder)    SE-2 (BrpRolesSeeder)   SE-3 (UsuarioAppAssignmentsSeeder)
                                                                       + DatabaseSeeder wiring
                                │                     │                      │
                                └─────────────────────┼──────────────────────┘
                                                      ▼
                                          E-1 (UserAppsResolver + LoginResponse)
                                                      ▼
                                          E-2 (LoginUseCase + AuthController)
                                                      ▼
                                ┌─────────────────────┴──────────────────────┐
                                ▼                                            ▼
                          E-3 (Validate-Token UseCase+Ctrl)         E-4 (MeController + use cases)
                                                                       │
                                                                       ▼
                                                                 M-1 (EnsureUserHasAppMiddleware)
                                                                       │
                                                                       ▼
                                          E-5 (PermisoRepository.listFor Usuario)
                                                                       ▼
                                          E-6 (Admin use cases + FormRequest)
                                                                       ▼
                                          E-7 (UsuarioAppController + routes)
                                                                       │
                                                                       ▼
                                              ┌────────────────────────┼─────────────────┐
                                              ▼                        ▼                 ▼
                                          T-1 (Unit suite)      T-2 (Feature suite)   T-3 (Smoke E2E)
                                                                      │                 │
                                                                      └────────┬────────┘
                                                                               ▼
                                                              V-1 (Full composer test) → V-2 (AGENTS.md + BRP PRD)
```

---

## Phase PM: Pre-Migrations (Day 1 — BLOCKER)

These three schema corrections run BEFORE any feature code. Without them, seeders and endpoints fail.

### PM-1: Add `slug` and `es_super_admin` columns to `roles`

**Goal:** `roles` gains `slug` (UNIQUE) + `es_super_admin` (BOOLEAN); existing 4 rows backfilled.

**Depends on:** none
**Blocks:** SE-2 (BrpRolesSeeder), SCH-2 (usuario_app FK to roles)
**Estimated:** 3h (TDD)
**Status:** [x] DONE — `database/migrations/2026_07_30_080000_add_slug_and_es_super_admin_to_roles_table.php` + `tests/Feature/Migration/AddSlugAndSuperAdminToRolesTest.php` (7/7 green). Deviation from design: matches SuperAdmin by `LOWER(nombre)='superadmin'` instead of `id=1` because AUTO_INCREMENT doesn't reset on transaction rollback, so id varies across test methods.

🔴 **RED — Test first:** `tests/Feature/Migrations/AddSlugAndSuperAdminToRolesTest.php`
- GIVEN the existing 4 roles (`1=SuperAdmin`, `2=Comercial`, `3=Operaciones`, `4=Finanzas`) and `RefreshDatabase`
- WHEN `php artisan migrate` runs (which applies the new migration)
- THEN `Schema::hasColumn('roles', 'slug')` is `true` and `Schema::hasColumn('roles', 'es_super_admin')` is `true`
- AND `Rol::find(1)->es_super_admin === true`
- AND `Rol::find(1)->slug === 'superadmin'` (kebab-case via `LOWER(REPLACE(nombre, ' ', '-'))`)
- AND `Rol::find(2)->slug === 'comercial'`
- AND a UNIQUE index on `slug` exists (`SHOW INDEX FROM roles` filtered by `Column_name='slug'`)
- AND `migrate:rollback --step=1` drops both columns without affecting `nombre` / `estado`
- AND `Rol::where('id', 1)->value('nombre')` still returns `'SuperAdmin'` after rollback+re-migrate

🟢 **GREEN — Implement:** `database/migrations/2026_07_30_080000_add_slug_and_es_super_admin_to_roles_table.php`
- Use `DB::statement('ALTER TABLE roles …')` (no `doctrine/dbal`, see explore §6 #9)
- `up()`: ADD COLUMN `slug VARCHAR(50) NULL UNIQUE`, ADD COLUMN `es_super_admin TINYINT(1) NOT NULL DEFAULT 0`, then `UPDATE roles SET slug = LOWER(REPLACE(nombre,' ','-')) WHERE slug IS NULL`, then `UPDATE roles SET es_super_admin = 1 WHERE id = 1`
- `down()`: drop both columns in reverse order
- Reference: `app/Models/Rol.php` already exists — no model change needed in this task

**Acceptance:** `php artisan migrate:fresh && php artisan test --filter=AddSlugAndSuperAdminToRoles` is green. Manual: `DESCRIBE roles` shows the 2 new columns.

---

### PM-2: Create `personas` table

**Goal:** new `personas` table with identification + names + contact fields.

**Depends on:** none
**Blocks:** PM-3 (FK target), SCH-3 (Persona model)
**Estimated:** 2h (TDD)
**Status:** [x] DONE — `database/migrations/2026_07_30_080100_create_personas_table.php` + `Modules/Shared/app/Models/Persona.php` + `tests/Feature/Migration/CreatePersonasTableTest.php` (9/9 green). Added Schema::hasTable idempotency guard so the migration is safe to re-run in tests after RefreshDatabase already applied it.

🔴 **RED — Test first:** `tests/Feature/Migrations/CreatePersonasTableTest.php`
- GIVEN fresh DB with `RefreshDatabase`
- WHEN migration runs
- THEN `Schema::hasTable('personas')` is `true`
- AND `Schema::hasColumn('personas', 'nombres')` exists and is NOT NULL (DB raises error on `INSERT (apellidos) VALUES ('x')` without `nombres`)
- AND `Schema::hasColumn('personas', 'identificacion_numero')` is nullable (`INSERT` with NULL succeeds twice)
- AND an index exists on `identificacion_numero` (`SHOW INDEX FROM personas`)
- AND `Persona::create(['nombres'=>'Ana','apellidos'=>'Pérez'])` round-trips and `apellidos === 'Pérez'`
- AND `migrate:rollback --step=1` drops the table

🟢 **GREEN — Implement:** `database/migrations/2026_07_30_080100_create_personas_table.php`
- `Schema::create('personas', function (Blueprint $t) { $t->id(); $t->string('identificacion_tipo', 10)->nullable(); $t->string('identificacion_numero', 20)->nullable(); $t->string('nombres', 100); $t->string('apellidos', 100); $t->string('email_principal', 150)->nullable(); $t->string('telefono_principal', 30)->nullable(); $t->timestamps(); $t->softDeletes(); $t->index('identificacion_numero', 'idx_personas_identificacion'); });`
- Per design §3.2: skip partial UNIQUE at DB level (MySQL doesn't support). Application-level dedup in PM-4.
- Down: `Schema::dropIfExists('personas')`

**Acceptance:** `php artisan migrate:fresh && php artisan test --filter=CreatePersonasTable` green. Manual `SHOW CREATE TABLE personas\G` shows the indexes.

---

### PM-3: Add `identificacion_*` + `persona_id` to `contacto`

**Goal:** `contacto` gains 3 nullable columns + FK to `personas(id)` ON DELETE SET NULL.

**Depends on:** PM-2 (FK target)
**Blocks:** PM-4 (backfill command needs the column)
**Estimated:** 2h (TDD)
**Status:** [x] DONE — `database/migrations/2026_07_30_080400_add_identificacion_and_persona_id_to_contacto_table.php` + `Modules/CRM/app/Models/Contacto.php` (fillable + persona() rel) + `tests/Feature/Migration/AddIdentificacionAndPersonaIdToContactoTest.php` (6/6 green). Used `ALTER TABLE contacto AUTO_INCREMENT = 1` in setUp so the test gets predictable IDs (MySQL doesn't reset counter on transaction rollback).

🔴 **RED — Test first:** `tests/Feature/Migrations/AddIdentificacionAndPersonaIdToContactoTest.php`
- GIVEN `personas` table exists + 100 seeded `contacto` rows (`ContactoFactory::count(100)`)
- WHEN migration runs
- THEN `Schema::hasColumn('contacto', 'identificacion_tipo')` is `true` (nullable)
- AND `Schema::hasColumn('contacto', 'identificacion_numero')` is `true` (nullable)
- AND `Schema::hasColumn('contacto', 'persona_id')` is `true` (nullable)
- AND all 100 existing contactos have `persona_id === null` (data preserved)
- AND FK constraint exists: `SHOW CREATE TABLE contacto` shows `fk_contacto_persona` referencing `personas(id)` with `ON DELETE SET NULL`
- AND deleting a persona cascades NULL on linked contactos (`UPDATE contacto SET persona_id=42 WHERE id=1`; then `DELETE FROM personas WHERE id=42`; then `Contacto::find(1)->persona_id === null`)
- AND `migrate:rollback --step=1` removes all 3 columns + FK + indexes cleanly

🟢 **GREEN — Implement:** `database/migrations/2026_07_30_080400_add_identificacion_and_persona_id_to_contacto_table.php`
- Use `DB::statement` for raw SQL (no doctrine/dbal):
  - `ALTER TABLE contacto ADD COLUMN identificacion_tipo VARCHAR(10) NULL AFTER apellidos`
  - `ALTER TABLE contacto ADD COLUMN identificacion_numero VARCHAR(20) NULL AFTER identificacion_tipo`
  - `ALTER TABLE contacto ADD COLUMN persona_id BIGINT UNSIGNED NULL AFTER id`
  - `ALTER TABLE contacto ADD CONSTRAINT fk_contacto_persona FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE SET NULL`
  - `CREATE INDEX idx_contacto_persona ON contacto (persona_id)`
- Down: drop FK + index + columns in reverse

**Acceptance:** `composer test --filter=AddIdentificacionAndPersonaIdToContacto` green. Manual: `DESCRIBE contacto` shows the 3 new columns.

---

### PM-4: `crm:backfill-personas` artisan command + data migration

**Goal:** artisan command creates `personas` rows from non-deleted `contacto` rows (matched by `email_contacto`); updates `contacto.persona_id`. Idempotent. `--dry-run` flag.

**Depends on:** PM-2, PM-3
**Blocks:** T-3 (E2E smoke test expects personas populated)
**Estimated:** 4h (TDD)
**Status:** [x] DONE — `app/Console/Commands/BackfillPersonasFromContacto.php` + `tests/Feature/Console/BackfillPersonasFromContactoTest.php` (8/8 green). Uses DB::transaction + cursor() for memory efficiency. The data migration in `2026_07_30_080500_backfill_personas_from_contacto_data.php` is deferred — the command is enough for MVP and the seeder can call it directly.

🔴 **RED — Test first:** `tests/Feature/Console/BackfillPersonasFromContactoTest.php`
- GIVEN 5 `contacto` rows with distinct `email_contacto` + 1 with `deleted_at` set + 2 sharing same email + 1 with `email_contacto IS NULL` (must have `nombres+apellidos`)
- WHEN `php artisan crm:backfill-personas --dry-run` runs (via `Artisan::call`)
- THEN command exits 0, prints `Would create 6 personas` (5 unique-by-email + 1 by name), `Would update 7 contactos`, `Skipped 1 soft-deleted`
- AND `SELECT COUNT(*) FROM personas` is 0 (dry-run does not mutate)
- AND `Contacto::all()->every(fn($c) => $c->persona_id === null)` is `true`
- AND re-running without `--dry-run` creates exactly 6 personas (the 2 duplicate-email contactos link to the SAME persona)
- AND `Contacto::where('email_contacto', 'shared@x.com')->pluck('persona_id')->unique()->count() === 1`
- AND re-running (idempotency) creates 0 new personas, 0 updates
- AND if `personas` table missing, command exits non-zero with clear error message (`$this->error(...)` contains "migrate first")

🟢 **GREEN — Implement:**
- `app/Console/Commands/BackfillPersonasFromContacto.php` — signature `crm:backfill-personas {--dry-run}`; `handle()` iterates `DB::table('contacto')->whereNull('deleted_at')->orderBy('id')->cursor()`
- Algorithm: match by `email_principal` first, fallback to `nombres+apellidos`; skip soft-deleted; wrap in `DB::transaction`
- Counters: `$inserted`, `$updated`, `$skipped`; report at end
- `database/migrations/2026_07_30_080500_backfill_personas_from_contacto_data.php` — thin migration calling `Artisan::call('crm:backfill-personas')` so `migrate:fresh` on a DB with contactos auto-populates personas

**Acceptance:** `composer test --filter=BackfillPersonasFromContacto` green. Manual: `php artisan crm:backfill-personas --dry-run` on a DB with 100 contactos reports correct counts.

---

## Phase SCH: New Schema (Day 2)

### SCH-1: Create `apps` table

**Goal:** new `apps` table cataloging the 6 apps (CRM, SAIlus, Marketing, WP Plugin, La Llave, BRP).

**Depends on:** none
**Blocks:** SCH-2 (FK), SE-1 (AppsSeeder), SCH-3 (App model)
**Estimated:** 2h (TDD)
**Status:** [x] DONE — `database/migrations/2026_07_30_080200_create_apps_table.php` + `Modules/Shared/app/Models/App.php` + `tests/Feature/Migration/CreateAppsTableTest.php` (9/9 green).

🔴 **RED — Test first:** `tests/Feature/Migrations/CreateAppsTableTest.php`
- GIVEN fresh DB + `RefreshDatabase`
- WHEN migration runs
- THEN `Schema::hasTable('apps')` is `true`
- AND `Schema::hasColumn('apps', 'slug')` exists and is UNIQUE (inserting 2 rows with same slug raises exception)
- AND `Schema::hasColumn('apps', 'tipo')` is ENUM with values `internal|external|customer` (inserting `'invalid'` raises exception)
- AND `Schema::hasColumn('apps', 'auth_type')` defaults to `'sanctum'` when not supplied
- AND `Schema::hasColumn('apps', 'activo')` defaults to `true` (boolean cast)
- AND `App::create(['slug'=>'crm','nombre'=>'CRM'])` works and `activo === true`, `tipo === 'internal'`, `auth_type === 'sanctum'`

🟢 **GREEN — Implement:** `database/migrations/2026_07_30_080200_create_apps_table.php`
- `Schema::create('apps', function (Blueprint $t) { $t->id(); $t->string('slug', 50); $t->string('nombre', 100); $t->enum('tipo', ['internal','external','customer'])->default('internal'); $t->enum('auth_type', ['sanctum','api_key'])->default('sanctum'); $t->boolean('activo')->default(true); $t->text('descripcion')->nullable(); $t->timestamps(); $t->softDeletes(); $t->unique('slug', 'idx_apps_slug'); });`
- Down: `Schema::dropIfExists('apps')`

**Acceptance:** `composer test --filter=CreateAppsTable` green. Manual `SHOW CREATE TABLE apps\G` shows enums + UNIQUE.

---

### SCH-2: Create `usuario_app` pivot table

**Goal:** `usuario_app` pivot linking `usuarios × apps × roles` with `UNIQUE(usuario_id, app_id)` and FK cascades.

**Depends on:** SCH-1, PM-1 (roles.slug exists)
**Blocks:** SE-3 (UsuarioAppAssignmentsSeeder), SCH-3 (UsuarioApp model)
**Estimated:** 2h (TDD)
**Status:** [x] DONE — `database/migrations/2026_07_30_080300_create_usuario_app_table.php` + `Modules/Shared/app/Models/UsuarioApp.php` + `tests/Feature/Migration/CreateUsuarioAppTableTest.php` (9/9 green).

🔴 **RED — Test first:** `tests/Feature/Migrations/CreateUsuarioAppTableTest.php`
- GIVEN `usuarios`, `apps`, `roles` tables exist + 1 user, 1 app, 1 role
- WHEN migration runs
- THEN `Schema::hasTable('usuario_app')` is `true`
- AND inserting `(usuario_id=1, app_id=1, rol_id=1)` twice raises UNIQUE violation
- AND `DELETE FROM usuarios WHERE id=1` cascades and deletes the pivot row
- AND `DELETE FROM roles WHERE id=1` raises FK RESTRICT violation (does not delete)
- AND `DELETE FROM apps WHERE id=1` cascades and deletes the pivot row
- AND indexes `(usuario_id)` and `(app_id)` exist for fast lookups
- AND `migrate:rollback --step=1` drops the table cleanly

🟢 **GREEN — Implement:** `database/migrations/2026_07_30_080300_create_usuario_app_table.php`
- `Schema::create('usuario_app', function (Blueprint $t) { $t->id(); $t->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade'); $t->foreignId('app_id')->constrained('apps')->onDelete('cascade'); $t->foreignId('rol_id')->constrained('roles')->onDelete('restrict'); $t->timestamps(); $t->unique(['usuario_id','app_id'], 'idx_usuario_app_unique'); $t->index('usuario_id', 'idx_usuario_app_usuario'); $t->index('app_id', 'idx_usuario_app_app'); });`
- Down: `Schema::dropIfExists('usuario_app')`

**Acceptance:** `composer test --filter=CreateUsuarioAppTable` green. Manual cascade delete verified.

---

### SCH-3: Eloquent models (App, UsuarioApp, Persona) + update Rol/Usuario/Contacto

**Goal:** canonical Eloquent models in `Modules/Shared/app/Models/` + deprecated wrappers + relationship updates.

**Depends on:** SCH-1, SCH-2, PM-2, PM-3, PM-1
**Blocks:** E-1, E-4, E-6 (anything that uses the models)
**Estimated:** 4h (TDD)
**Status:** [x] DONE — `Modules/Shared/app/Models/{App,UsuarioApp,Persona,Rol,Usuario}.php` + `Modules/CRM/app/Models/Contacto.php` (modified) + deprecated wrappers `app/Models/{App,UsuarioApp,Persona}.php`. Tests: `tests/Unit/Shared/{App,UsuarioApp,Persona}ModelTest.php` (9/9 green).

🔴 **RED — Tests first (4 files):**
1. `tests/Unit/Shared/AppModelTest.php` — GIVEN 6 apps seeded + 1 user with 2 assignments, WHEN `$app->usuarioApps` THEN 2 rows; WHEN `$app->usuarios` THEN 1 user; WHEN `App::where('slug','brp')->first()` THEN model with `tipo='external'`
2. `tests/Unit/Shared/UsuarioAppModelTest.php` — GIVEN pivot row, WHEN `$ua->app->slug` THEN 'crm'; WHEN `$ua->rol->slug` THEN 'comercial'; WHEN `$ua->usuario->email` THEN 'lorena@…'
3. `tests/Unit/Shared/PersonaModelTest.php` — GIVEN `Persona::create(['nombres'=>'Ana','apellidos'=>'Pérez'])` THEN id auto-generated; WHEN `$persona->contactos` THEN empty Collection for new persona
4. `tests/Feature/Shared/ContactoPersonaRelationshipTest.php` — GIVEN contacto linked to persona_id=42, WHEN `$contacto->persona` THEN Persona instance; WHEN `$persona->contactos` THEN Collection of linked contactos; WHEN contacto has `persona_id IS NULL`, `$contacto->persona` returns `null` (no exception)

🟢 **GREEN — Implement (8 files):**
- CREATE `Modules/Shared/app/Models/App.php` (table=`apps`, fillable=`[slug,nombre,tipo,auth_type,activo,descripcion]`, casts=[activo=>bool], relationships `usuarioApps()` hasMany, `usuarios()` belongsToMany via pivot)
- CREATE `Modules/Shared/app/Models/UsuarioApp.php` (table=`usuario_app`, fillable=`[usuario_id,app_id,rol_id]`, `belongsTo` for `usuario`, `app`, `rol`)
- CREATE `Modules/Shared/app/Models/Persona.php` (table=`personas`, fillable=`[identificacion_tipo,identificacion_numero,nombres,apellidos,email_principal,telefono_principal]`, `contactos()` hasMany `Modules\CRM\Models\Contacto`)
- CREATE deprecated wrappers: `app/Models/App.php`, `app/Models/UsuarioApp.php`, `app/Models/Persona.php` (each `extends Modules\Shared\Models\*`)
- MODIFY `Modules/Shared/app/Models/Rol.php`: add `slug,es_super_admin` to `$fillable`; cast `es_super_admin=>bool`; add `isSuperAdmin(): bool` method; add `usuarioApps()` hasMany
- MODIFY `Modules/Shared/app/Models/Usuario.php`: add `apps()` belongsToMany via `usuario_app` with `rol_id` pivot; add `isSuperAdmin(): bool` (delegates to `$this->rol?->isSuperAdmin() ?? false`)
- MODIFY `Modules/CRM/app/Models/Contacto.php`: add `identificacion_tipo,identificacion_numero,persona_id` to `$fillable`; add `persona()` belongsTo `Modules\Shared\Models\Persona`

**Acceptance:** `composer test --filter='AppModelTest|UsuarioAppModelTest|PersonaModelTest|ContactoPersonaRelationshipTest'` all green. Manual: `tinker` → `App::all()->count() === 6` after SE-1 runs.

---

## Phase SE: Seeders (Day 2)

### SE-1: AppsSeeder (6 apps, idempotent)

**Goal:** `php artisan db:seed --class=AppsSeeder` inserts the 7 canonical apps (incluye `mercurio` como dual-write de `sailus` hasta 2027-02-06); re-running is no-op.

**Depends on:** SCH-1
**Blocks:** SE-3 (assignments need apps)
**Estimated:** 1h (TDD)

🔴 **RED — Test first:** `tests/Feature/Seeders/AppsSeederTest.php`
- GIVEN empty `apps` table
- WHEN `AppsSeeder` runs
- THEN 7 rows exist with slugs exactly `{crm, sailus, mercurio, marketing, wp-plugin, la-llave, brp}`
- AND `crm`, `sailus`, `mercurio`, `marketing` have `tipo='internal'`; the rest `tipo='external'`
- AND all 7 have `auth_type='sanctum'` and `activo=true`
- WHEN seeder runs a SECOND time
- THEN still exactly 7 rows (idempotent)
- AND no UNIQUE constraint error on slug

🟢 **GREEN — Implement:** `database/seeders/AppsSeeder.php`
- `private const APPS = [['slug'=>'crm','nombre'=>'CRM Tecnoinnsoft','tipo'=>'internal','auth_type'=>'sanctum'], ... 7 total, incluye 'mercurio' como alias dual-write de 'sailus']`
- `foreach as $a) App::firstOrCreate(['slug'=>$a['slug']], $a);`

**Acceptance:** `composer test --filter=AppsSeederTest` green. Manual `db:seed --class=AppsSeeder` twice → still 6 rows.

---

### SE-2: BrpRolesSeeder (3 BRP roles)

**Goal:** idempotent insert of `brp-admin`, `brp-lider`, `brp-psicologo` roles.

**Depends on:** PM-1 (roles.slug must exist)
**Blocks:** SE-3 (assignments reference these roles)
**Estimated:** 1h (TDD)

🔴 **RED — Test first:** `tests/Feature/Seeders/BrpRolesSeederTest.php`
- GIVEN empty `roles` table (after migration)
- WHEN `BrpRolesSeeder` runs
- THEN 3 new roles exist with slugs `{brp-admin, brp-lider, brp-psicologo}` and `es_super_admin=false`
- AND existing 4 roles (SuperAdmin, Comercial, Operaciones, Finanzas) are NOT modified (still 4 rows preserved)
- WHEN seeder runs again
- THEN total rows still 7 (idempotent), no UNIQUE violation on slug

🟢 **GREEN — Implement:** `database/seeders/BrpRolesSeeder.php`
- `private const BRP_ROLES = [['slug'=>'brp-admin','nombre'=>'BRP Admin','es_super_admin'=>false], ...]`
- `foreach as $r) Rol::firstOrCreate(['slug'=>$r['slug']], $r);`

**Acceptance:** `composer test --filter=BrpRolesSeederTest` green.

---

### SE-3: UsuarioAppAssignmentsSeeder + DatabaseSeeder wiring

**Goal:** assign the 4 canonical users (Vos=5, Lorena=1, Patricia=4, Jaime=3) to apps via email lookup + 11 total pivot rows; wire all 3 new seeders into `DatabaseSeeder`.

**Depends on:** SE-1, SE-2, SCH-2, SCH-3 (models exist)
**Blocks:** E-1..E-7 (endpoints read from pivot), T-3 (smoke test)
**Estimated:** 3h (TDD)

🔴 **RED — Test first:** `tests/Feature/Seeders/UsuarioAppAssignmentsSeederTest.php`
- GIVEN seeded apps + BRP roles + the 4 canonical users exist (lookup by EMAIL, not id):
  - `admin@tecnoinnsoft.dev` (id=5, role=super-admin)
  - `innovacionydesarrollo.tis@gmail.com` (id=1, role=comercial)
  - `servicioalcliente.tis@gmail.com` (id=4, role=operativo)
  - `direccion.tis@gmail.com` (id=3, role=super-admin)
- WHEN `UsuarioAppAssignmentsSeeder` runs
- THEN `SELECT COUNT(*) FROM usuario_app` returns exactly 11
- AND `usuario_id=5` has 6 rows (all apps, super-admin rol)
- AND `usuario_id=1` has 1 row (crm, comercial rol)
- AND `usuario_id=4` has 2 rows (crm + brp, operativo rol)
- AND `usuario_id=3` has 2 rows (crm + marketing, super-admin rol)
- AND `usuario_id=2` (gestorcomercial) has 0 rows (the seeder does NOT touch this user)
- WHEN seeder runs again
- THEN still exactly 11 rows (idempotent via UNIQUE)
- AND if a user is missing, the seeder logs warning and skips (does not throw)

🟢 **GREEN — Implement:**
- `database/seeders/UsuarioAppAssignmentsSeeder.php` — resolve users by email, apps by slug, roles by slug; wrap in `DB::transaction`; use `UsuarioApp::firstOrCreate` with `(usuario_id, app_id)` as the lookup key
- MODIFY `database/seeders/DatabaseSeeder.php` — append `$this->call([AppsSeeder::class, BrpRolesSeeder::class, UsuarioAppAssignmentsSeeder::class])` to the end of `run()`

**Acceptance:** `composer test --filter=UsuarioAppAssignmentsSeederTest` green. Manual `migrate:fresh --seed` produces the 11 pivot rows.

---

## Phase E: Endpoints (Day 3-4)

### E-1: UserAppsResolver + SuperAdminGuard + LoginResponse extension

**Goal:** shared service that resolves a user's apps list (with super-admin bypass + `activo` filter) + guard helper. `LoginResponse` DTO gains `apps[]` field.

**Depends on:** SCH-3
**Blocks:** E-2, E-3, E-4
**Estimated:** 4h (TDD)

🔴 **RED — Tests first (3 files):**
1. `tests/Unit/Auth/UserAppsResolverTest.php`
   - GIVEN user with 2 assignments to active apps, WHEN `resolve($user)` THEN 2 `AppAssignmentDto` with `{slug, nombre, rol, rol_id}`
   - GIVEN super-admin user with 0 pivot rows, WHEN `resolve($user)` THEN 6 entries (all active apps), each with `rol='super-admin'`
   - GIVEN user with assignment to `brp` (activo=false), WHEN `resolve($user)` THEN `brp` is filtered out
   - GIVEN user with 0 apps + non-super-admin, WHEN `resolve($user)` THEN `[]`
2. `tests/Unit/Auth/SuperAdminGuardTest.php`
   - GIVEN super-admin user, WHEN `assertSuperAdmin($user)` THEN no exception
   - GIVEN non-super-admin user, WHEN `assertSuperAdmin($user)` THEN `AppAccessDeniedException` thrown
   - GIVEN `null`, WHEN `assertSuperAdmin(null)` THEN exception
3. `tests/Unit/Auth/LoginResponseIncludesAppsTest.php` (DTO test, not HTTP yet)
   - GIVEN `LoginResponse(token, usuario, [app1, app2])`, WHEN `toArray()` THEN includes `data.apps` array of length 2 with `{slug,nombre,rol,rol_id}`
   - AND `data.usuario` carries `{id, email, nombres, apellidos}` (with `apellidos=''` for legacy single-name users — per DR-10)
   - AND does NOT include `password_hash` or `personal_access_tokens`

🟢 **GREEN — Implement:**
- `app/Application/Services/UserAppsResolver.php` — `resolve(Usuario $user): array` (super-admin bypass via `App::where('activo',true)->orderBy('slug')->get()`, else `UsuarioApp::with(['app','rol'])->where('usuario_id',$user->id)->whereHas('app',fn($q)=>$q->where('activo',true))->get()`)
- `app/Application/Services/SuperAdminGuard.php` — `assertSuperAdmin(?Usuario $user): void` throws `AppAccessDeniedException`
- `app/Application/DTOs/AppAssignmentDto.php` — readonly DTO `{slug, nombre, rol, rol_id}` with `toArray()`
- `app/Application/DTOs/ValidateTokenResponseDto.php` — `{valid, usuario_id, email, apps, permisos, cached, validated_at}` with `toArray()`
- MODIFY `app/Application/DTOs/LoginResponse.php` — add `public array $apps` to constructor; extend `toArray()` to include `data.apps[]` and split `data.usuario.nombres=$usuario->nombre, apellidos=''`

**Acceptance:** all 3 unit tests green. `composer test --filter='UserAppsResolver|SuperAdminGuard|LoginResponseIncludesApps'` passes.

---

### E-2: LoginUseCase + AuthController modified to return apps

**Goal:** `POST /api/v1/auth/login` response includes `data.apps[]` for the 4 canonical users (Vos=6, Lorena=1, Patricia=2, Jaime=2).

**Depends on:** E-1
**Blocks:** T-2 (auth login tests reference the modified endpoint)
**Estimated:** 3h (TDD)

🔴 **RED — Test first:** `tests/Feature/Auth/LoginResponseIncludesAppsTest.php`
- GIVEN super-admin user (Vos, id=5) with 6 pivot rows + 6 seeded apps
- WHEN `POST /api/v1/auth/login` with valid creds
- THEN 200, `success=true`, `data.token` is a Sanctum token, `data.apps` has 6 entries with slugs `{crm, sailus, marketing, wp-plugin, la-llave, brp}`
- AND the issued token works on `GET /api/v1/me` (200)
- GIVEN Lorena (1 app), WHEN login THEN `data.apps` has exactly 1 entry (`slug='crm'`, `rol='comercial'`)
- GIVEN Patricia (2 apps), WHEN login THEN `data.apps` has 2 entries (`{crm, operativo}, {brp, operativo}`)
- GIVEN Jaime (2 apps, super-admin rol), WHEN login THEN `data.apps` has 2 entries each with `rol='super-admin'`
- GIVEN wrong password, WHEN login THEN 401, no `apps` leaked
- GIVEN `brp` app set to `activo=false`, WHEN Patricia logs in THEN `data.apps` contains only `crm` (inactive filtered)
- GIVEN existing `tests/Feature/API/AuthTest.php` assertions (the canonical login test), WHEN that test runs THEN ALL existing assertions still pass

🟢 **GREEN — Implement:**
- MODIFY `app/Application/UseCases/Auth/LoginUseCase.php` — inject `UserAppsResolver` via constructor; call `$this->appsResolver->resolve($usuario)` after token creation; pass to `LoginResponse`
- MODIFY `app/Http/Controllers/API/AuthController.php` — no signature change; Laravel auto-resolves the new dependency via container
- Reference: see `app/Application/UseCases/Auth/LoginUseCase.php` for existing pattern

**Acceptance:** `composer test --filter='LoginResponseIncludesAppsTest|AuthTest'` green (no regression).

---

### E-3: ValidateTokenUseCase + TokenCacheService + ValidateTokenController

**Goal:** `GET /api/v1/auth/validate-token` returns the BRP contract with 5-min cache.

**Depends on:** E-1, E-5 (PermisoRepository.listFor Usuario — or stub it in this task and stub-replace later)
**Blocks:** T-2 (feature test)
**Estimated:** 5h (TDD)

🔴 **RED — Tests first (2 files):**
1. `tests/Unit/Auth/ValidateTokenUseCaseTest.php`
   - GIVEN a user with 2 apps + 3 permisos rows, WHEN `execute($token)` THEN returns DTO with `{valid:true, usuario_id, email, apps[2], permisos[3], cached:false, validated_at:ISO8601}`
   - GIVEN same call within 300s, WHEN second `execute($token)` THEN returns DTO with `cached:true` and same `validated_at`
   - GIVEN token not in `personal_access_tokens`, WHEN `execute($token)` THEN returns `null` (caller maps to 401)
   - GIVEN user has `estado='Inactivo'`, WHEN `execute($token)` THEN returns `null`
   - GIVEN 401 path, WHEN re-called within TTL THEN second call also 401 (no cache on failure)
   - GIVEN different token (different hash), WHEN `execute($otherToken)` THEN separate cache entry
2. `tests/Feature/Auth/ValidateTokenEndpointTest.php`
   - GIVEN Patricia's Bearer token, WHEN `GET /api/v1/auth/validate-token` THEN 200 with `data.valid=true, data.usuario_id=4, data.apps[2], data.cached=false`, header `X-Cache: MISS`
   - WHEN called AGAIN within 300s, THEN 200 with `data.cached=true`, header `X-Cache: HIT`, same `validated_at`
   - WHEN called WITHOUT bearer token, THEN 401, `{success:false, error:'invalid_token'}`
   - WHEN called with `Authorization: Bearer fake-token`, THEN 401
   - WHEN called with `X-API-Key: xyz` and no Bearer, THEN 400, `error:'bearer_required'`
   - WHEN called with both headers AND valid Bearer, THEN 200 (Bearer takes precedence)
   - WHEN Vos (super-admin) calls, THEN `data.apps` has 6 entries
   - WHEN user with 0 apps + valid token calls, THEN 200, `data.apps=[]`, `data.valid=true` (NOT 401)
   - WHEN token is deleted from `personal_access_tokens` between calls, THEN 2nd call returns 401
   - WHEN 6th call in 60s is made, THEN 429 with `Retry-After` (throttle:120,1)
   - WHEN `brp` set to `activo=false` and Patricia has assignment, THEN `data.apps` does NOT include `brp`

🟢 **GREEN — Implement:**
- `app/Application/UseCases/Auth/ValidateTokenUseCase.php` — inject `TokenCacheService`, `UserAppsResolver`, `PermisoRepositoryInterface`; `execute(?string $token): ?ValidateTokenResponseDto`; compute `$hash = hash('sha256', $token)`; `Cache::remember("auth:validate_token:{$hash}", 300, fn() => $this->computeFresh($token))`; if fresh is `null` return `null` (no cache on failure); otherwise build DTO with `cached: cacheHit` based on stored payload
- `app/Infrastructure/Auth/TokenCacheService.php` — thin wrapper over `Cache::remember()` for testability
- `app/Http/Controllers/API/Auth/ValidateTokenController.php` — `__invoke(Request)`; check `X-API-Key` header presence → 400 if no Bearer; call use case; if `null` → 401 `invalid_token`; else 200 with `X-Cache: HIT|MISS` header
- `app/Http/Middleware/ThrottleMutations` reuse OR add `throttle:120,1` to the route
- MODIFY `routes/api.php` — append `Route::get('/auth/validate-token', ValidateTokenController::class)->middleware('throttle:120,1')->name('auth.validate-token');` (OUTSIDE auth:sanctum group — public, self-validates)

**Acceptance:** both test files green. Manual `curl -H 'Authorization: Bearer <token>' /api/v1/auth/validate-token` returns expected JSON.

---

### E-4: MeController + GetMyProfileUseCase + GetMyAppsUseCase + GetMyPermisosUseCase

**Goal:** `GET /api/v1/me`, `/me/apps`, `/me/apps/{slug}/permisos` return user data with apps and permissions.

**Depends on:** E-1, E-5 (Permisos lookup), M-1 (EnsureUserHasApp middleware for `permisos` route)
**Blocks:** T-2
**Estimated:** 4h (TDD)

🔴 **RED — Test first:** `tests/Feature/Me/MeEndpointsTest.php`
- GIVEN Vos authenticated, WHEN `GET /api/v1/me` THEN 200, `data.usuario.id=5, data.usuario.email='admin@…', data.apps.length=6`, `data.usuario.rol.slug='super-admin'`
- WHEN `GET /api/v1/me/apps` THEN 200, `data.apps[6]` (same content as login)
- WHEN `GET /api/v1/me/apps/crm/permisos` THEN 200, `data.app.slug='crm'`, `data.permisos` is non-empty
- GIVEN Lorena authenticated, WHEN `GET /api/v1/me/apps/brp/permisos` THEN 403 (EnsureUserHasApp denies)
- GIVEN Patricia authenticated, WHEN `GET /api/v1/me/apps/brp/permisos` THEN 200, `data.app.rol='operativo'`
- WHEN called with `slug='unknown'` THEN 404
- WHEN called without `Authorization` header THEN 401
- WHEN called with bogus token THEN 401
- WHEN called 2x in a row, both responses identical (idempotent, no `updated_at` mutation on user)
- WHEN inspecting response, `data.usuario` does NOT contain `password_hash` or `tokens` array

🟢 **GREEN — Implement:**
- `app/Application/UseCases/Auth/Me/GetMyProfileUseCase.php` — injects `UserAppsResolver`; `execute(Usuario $user): array` returns `['usuario'=>$user->load('rol'), 'apps'=>$resolver->resolve($user)]`
- `app/Application/UseCases/Auth/Me/GetMyAppsUseCase.php` — `execute(Usuario $user): array` (just the apps)
- `app/Application/UseCases/Auth/Me/GetMyPermisosUseCase.php` — `execute(Usuario $user, string $slug): ?array` (returns `null` if app not found OR no access; else returns `['app'=>{slug,nombre,rol}, 'permisos'=>[…]]`)
- `app/Http/Controllers/API/MeController.php` — injects the 3 use cases; methods `show`, `apps`, `permisos` (permisos uses `EnsureUserHasApp` middleware)
- `app/Http/Resources/MeResource.php`, `app/Http/Resources/PermisosResource.php` (shape-only, no logic)
- MODIFY `routes/api.php` — add group under `auth:sanctum, throttle:api` with prefix `me`: `GET /` → show; `GET /apps` → apps; `GET /apps/{slug}/permisos` → permisos (with `has-app:{slug}` middleware)
- Add exception handler for 404 `app_not_found` (render in controller, not as exception)

**Acceptance:** `composer test --filter=MeEndpointsTest` green. Manual smoke test via tinker.

---

### E-5: PermisoRepository.listPermissionsForUsuario (extension)

**Goal:** add a new method to `PermisoRepositoryInterface` that aggregates permissions across all roles assigned to the user (union, deduped).

**Depends on:** SCH-3 (Usuario.rol + Usuario.apps relationships work)
**Blocks:** E-3 (validate-token permisos aggregation), E-4 (`/me/apps/{slug}/permisos`)
**Estimated:** 2h (TDD)

🔴 **RED — Test first:** `tests/Unit/Auth/PermisoRepositoryListForUsuarioTest.php`
- GIVEN user with 1 rol_id having 3 permisos rows (`vista='brp.sesiones.marcar'`, etc.), WHEN `listFor($user)` THEN returns 3 strings
- GIVEN user with 2 roles having overlapping permisos (e.g., both have `'crm.oportunidades.ver'`), WHEN `listFor($user)` THEN deduplicated union
- GIVEN user with rol_id=`1` (super-admin, `vista='*'`), WHEN `listFor($user)` THEN `['*']`
- GIVEN user with 0 permisos rows, WHEN `listFor($user)` THEN `[]`
- GIVEN super-admin user with 0 pivot rows, WHEN `listFor($user)` THEN permissions from rol_id only (does NOT aggregate from apps)

🟢 **GREEN — Implement:**
- MODIFY `app/Domain/Repositories/PermisoRepositoryInterface.php` — add `listPermissionsForUsuario(Usuario $user): array`
- MODIFY `app/Infrastructure/Persistence/EloquentPermisoRepository.php` — implement: collect all rol_ids the user has (via `Usuario::apps()->pluck('pivot.rol_id')` + `Usuario->rol_id`), union with `$user->rol_id`, then `Permiso::whereIn('rol_id', $rolIds)->pluck('vista')->unique()->all()`. If `'*'` in results, return `['*']` (early exit).

**Acceptance:** `composer test --filter=PermisoRepositoryListForUsuarioTest` green.

---

### E-6: Admin use cases (List + Assign + Revoke) + Form Request

**Goal:** business-logic use cases for `GET/POST/DELETE /usuarios/{id}/apps`. Form request validates `app_slug` + `rol_slug` exist.

**Depends on:** E-1 (UserAppsResolver), SCH-3 (models)
**Blocks:** E-7 (controller wires these)
**Estimated:** 4h (TDD)

🔴 **RED — Tests first (3 files):**
1. `tests/Unit/Auth/Admin/ListUsuarioAppsUseCaseTest.php`
   - GIVEN Patricia (2 assignments), WHEN `execute(4)` THEN 2 entries with `{app_id, app_slug, app_nombre, rol_id, rol_slug, rol_nombre}`
   - GIVEN user with 0 assignments, WHEN `execute($id)` THEN `[]`
   - GIVEN nonexistent user id, WHEN `execute(9999)` THEN `null` (caller maps to 404)
2. `tests/Unit/Auth/Admin/AssignUsuarioAppUseCaseTest.php`
   - GIVEN Lorena + valid `{app_slug:'marketing', rol_slug:'comercial'}`, WHEN `execute(1, $payload)` THEN returns new `UsuarioApp` row (201)
   - GIVEN duplicate `{app_slug:'crm', rol_slug:'comercial'}` for Lorena (already assigned), WHEN `execute(1, $payload)` THEN throws `AssignmentExistsException` (caller → 409)
   - GIVEN unknown `app_slug:'unknown'`, WHEN `execute(1, $payload)` THEN throws `ValidationException` (caller → 422)
   - GIVEN nonexistent user, WHEN `execute(9999, $payload)` THEN throws `UserNotFoundException` (caller → 404)
3. `tests/Unit/Auth/Admin/RevokeUsuarioAppUseCaseTest.php`
   - GIVEN Patricia with brp assignment, WHEN `execute(4, $brpAppId)` THEN returns `true` and row deleted
   - GIVEN no assignment for (id, app_id), WHEN `execute(4, 99)` THEN returns `false` (caller → 404)
   - GIVEN nonexistent user, WHEN `execute(9999, 1)` THEN returns `false`

🟢 **GREEN — Implement:**
- `app/Application/UseCases/Auth/Admin/ListUsuarioAppsUseCase.php` — `execute(int $id): ?array`; null if user not found
- `app/Application/UseCases/Auth/Admin/AssignUsuarioAppUseCase.php` — `execute(int $id, array $payload): UsuarioApp`; resolves App by slug, Rol by slug; checks existing; wraps in transaction
- `app/Application/UseCases/Auth/Admin/RevokeUsuarioAppUseCase.php` — `execute(int $id, int $appId): bool`
- `app/Application/Exceptions/AppAccessDeniedException.php`, `app/Application/Exceptions/AssignmentExistsException.php`, `app/Application/Exceptions/UserNotFoundException.php`
- `app/Http/Requests/AssignUsuarioAppRequest.php` — `rules: ['app_slug' => 'required|string|exists:apps,slug', 'rol_slug' => 'required|string|exists:roles,slug']`; `authorize(): bool { return true; }` (guard enforced in controller)

**Acceptance:** all 3 unit test files green.

---

### E-7: UsuarioAppController + routes/api.php admin routes

**Goal:** `GET /usuarios/{id}/apps`, `POST /usuarios/{id}/apps`, `DELETE /usuarios/{id}/apps/{app_id}` work end-to-end with super-admin guard + 120/min throttle.

**Depends on:** E-6
**Blocks:** T-1, T-2
**Estimated:** 3h (TDD)

🔴 **RED — Test first:** `tests/Feature/Auth/UsuarioAppAdminTest.php`
- GIVEN Vos (super-admin) authenticated, WHEN `GET /api/v1/usuarios/4/apps` THEN 200, `data[2]` with Patricia's assignments
- WHEN `POST /api/v1/usuarios/1/apps` with `{app_slug:'marketing', rol_slug:'comercial'}` THEN 201, new row created
- WHEN same POST again THEN 409, `error:'assignment_already_exists'`
- WHEN POST with `{app_slug:'unknown'}` THEN 422, validation error
- WHEN `DELETE /api/v1/usuarios/4/apps/6` (brp id) THEN 200, row deleted
- WHEN DELETE for nonexistent assignment THEN 404
- WHEN Lorena (comercial) calls ANY of the 3 admin endpoints THEN 403, `error:'forbidden'`
- WHEN no token THEN 401

🟢 **GREEN — Implement:**
- `app/Http/Controllers/API/UsuarioAppController.php` — `index(Request, $id)`, `store(AssignUsuarioAppRequest, $id)`, `destroy(Request, $id, $app_id)`; inject all 3 admin use cases + `SuperAdminGuard`; call `$this->guard->assertSuperAdmin($user)` first; map exceptions to status codes via `match` or try/catch
- `app/Http/Resources/AppAssignmentResource.php` — shape `{app_id, app_slug, app_nombre, rol_id, rol_slug, rol_nombre}`
- MODIFY `bootstrap/app.php` — add exception render for `AppAccessDeniedException` → 403 JSON envelope (if not already handled)
- MODIFY `routes/api.php` — add `Route::middleware(['auth:sanctum', 'throttle:120,1'])->prefix('usuarios/{id}/apps')->group(...)` with the 3 routes; wrap POST/DELETE with `throttle-mutations` middleware per design §4.5

**Acceptance:** `composer test --filter=UsuarioAppAdminTest` green. Manual `curl -X POST /usuarios/1/apps -H 'Authorization: Bearer …' -d '{"app_slug":"marketing","rol_slug":"comercial"}'` returns 201.

---

## Phase M: Middleware (Day 4)

### M-1: EnsureUserHasAppMiddleware + bootstrap alias

**Goal:** `has-app:{slug}` route middleware checks pivot + super-admin bypass; returns 403 JSON envelope.

**Depends on:** SCH-3
**Blocks:** E-4 (`/me/apps/{slug}/permisos` route uses it)
**Estimated:** 2h (TDD)

🔴 **RED — Test first:** `tests/Unit/Auth/EnsureUserHasAppMiddlewareTest.php`
- GIVEN user with assignment to `brp`, WHEN middleware fires with `slug='brp'` THEN calls `$next($request)` (allow)
- GIVEN user with NO assignment to `brp`, WHEN middleware fires THEN returns 403 JSON `{success:false, error:'app_access_denied', message:'User does not have access to app brp'}`
- GIVEN super-admin user with 0 pivot rows, WHEN middleware fires THEN allows (super-admin bypass)
- GIVEN user with assignment to `brp` but `brp.activo=false`, WHEN middleware fires THEN returns 403 (inactive filtered)
- GIVEN no authenticated user, WHEN middleware fires THEN returns 401
- AND `bootstrap/app.php` registers alias `'has-app' => EnsureUserHasAppMiddleware::class`

🟢 **GREEN — Implement:**
- `app/Infrastructure/Auth/EnsureUserHasAppMiddleware.php` — `handle(Request $request, Closure $next, string $slug)`: short-circuit on no user (401), short-circuit on super-admin, query `UsuarioApp::where('usuario_id',$user->id)->whereHas('app',fn($q)=>$q->where('slug',$slug)->where('activo',true))->exists()`, return 403 if false, else set `$request->attributes->set('current_app_slug',$slug)` and call `$next`
- MODIFY `bootstrap/app.php` — append `'has-app' => \App\Infrastructure\Auth\EnsureUserHasAppMiddleware::class` to the middleware alias array

**Acceptance:** `composer test --filter=EnsureUserHasAppMiddlewareTest` green. Manual smoke: hit `/me/apps/brp/permisos` as Lorena → 403.

---

## Phase T: Tests + E2E (Day 5)

### T-1: Unit suite passes (models, use cases, services, middleware)

**Goal:** all ~14 unit test files created in earlier phases are green together.

**Depends on:** SCH-3, E-1, E-3, E-5, E-6, M-1
**Blocks:** T-3
**Estimated:** 2h
**Status:** [x] DONE — all 5 required multi-app unit test files exist and pass (21 tests, 66 assertions). The full Unit suite has 3 documented pre-existing unrelated failures in `EloquentPipelineRepositoryTest` and `SendPipelineChangeToN8nTest` (2).

**Execution:**
```bash
php artisan test --testsuite=Unit
```

**Acceptance:** zero failures. If regressions appear, fix in the corresponding task (re-open it). All `tests/Unit/Shared/*.php` + `tests/Unit/Auth/*.php` pass.

---

### T-2: Feature suite passes (endpoints, seeders, migrations, console)

**Goal:** all ~7 feature test files created in earlier phases are green together.

**Depends on:** T-1, E-2, E-3, E-4, E-6, E-7, SE-3
**Blocks:** T-3
**Estimated:** 2h
**Status:** [x] DONE — all required multi-app feature files exist; 122 in-scope tests pass with 449 assertions. The full Feature suite has 5 documented pre-existing unrelated failures (`PipelineEtapaChangedDispatchTest` once and `PipelineEtapaMigrationTest` four times).

**Execution:**
```bash
php artisan test --testsuite=Feature
```

**Acceptance:** zero failures, including no regression in `tests/Feature/API/AuthTest.php` (existing login flow) or `tests/Feature/API/PlanControllerTest.php` (canonical feature test pattern).

---

### T-3: E2E smoke test via tinker/curl against a fresh DB

**Goal:** `migrate:fresh --seed` produces a fully-working BRP integration env. The 4 canonical users can log in and `/auth/validate-token` returns correct data.

**Depends on:** T-1, T-2, SE-3 (DatabaseSeeder wired), PM-4 (backfill command callable)
**Blocks:** V-1
**Estimated:** 2h
**Status:** [x] DONE — `tests/Feature/E2E/MultiAppAccessFlowTest.php` verifies all 4 canonical users through login → `/me/apps` → two `/auth/validate-token` calls using the real assignment seeder (1 test, 118 assertions, green).

**Execution:**
```bash
# In docker (per AGENTS.md docker dev section)
docker exec crm-laravel-dev php artisan migrate:fresh --seed
docker exec crm-laravel-dev php artisan crm:backfill-personas --dry-run   # expect ~2 836 personas would be created
docker exec crm-laravel-dev php artisan tinker --execute='echo App::count(); echo "\n"; echo UsuarioApp::count();'  # expect 6 + 11 = 17 rows total

# Smoke test all 4 canonical users via curl
docker exec crm-laravel-dev php artisan tinker --execute='
$vos = Usuario::where("email","admin@tecnoinnsoft.dev")->first();
$token = $vos->createToken("smoke")->plainTextToken;
file_put_contents("/tmp/smoke_token", $token);
'
curl -sX POST http://localhost:8001/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@tecnoinnsoft.dev","password":"password123"}' | jq '.data.apps | length'  # expect 6
curl -sX GET http://localhost:8001/api/v1/auth/validate-token \
  -H "Authorization: Bearer $(cat /tmp/smoke_token)" | jq '.data | {valid, usuario_id, apps_count: (.apps | length), cached}'  # expect valid:true, usuario_id:5, 6 apps, cached:false
curl -sX GET http://localhost:8001/api/v1/auth/validate-token \
  -H "Authorization: Bearer $(cat /tmp/smoke_token)" | jq '.data.cached'  # expect true (cache hit)
```

**Acceptance:** all curl outputs match expectations. Cache hit on 2nd call. `/me/apps/{slug}/permisos` for Lorena with `slug=brp` returns 403.

---

## Phase V: Verification (Day 5)

### V-1: Full `composer test` + spec-compliance sweep

**Goal:** entire test suite (Unit + Feature) is green; spec scenarios accounted for.

**Depends on:** T-1, T-2, T-3
**Estimated:** 1h
**Status:** [x] DONE WITH PRE-EXISTING CAVEATS — full suite executed in the PHP container (the exact `composer test` script steps: `config:clear` + `artisan test`): 243 total, 235 passed, 8 unrelated pre-existing failures, 780 assertions, 0 failures in `multi-app-access` tests.

**Execution:**
```bash
composer test
# or: php artisan config:clear && php artisan test
```

**Spec sweep** (manual checklist):
- [x] REQ-APPS-1..4: schema, seed, model, activo filter — covered by SCH-1, SE-1, SCH-3 tests
- [x] REQ-PRE-1..5: roles/contacto/personas/backfill — covered by PM-1..4 tests
- [x] REQ-LOGIN-1..10: login response — covered by E-2 tests
- [x] REQ-VALTOK-1..9: validate-token — covered by E-3 tests
- [x] REQ-ME-1..9: /me endpoints — covered by E-4 tests
- [x] REQ-USRAPP-1..7: pivot + seed + admin — covered by SCH-2, SE-3, E-6, E-7 tests
- [x] REQ-PERSONAS-1..7: personas — covered by PM-2, PM-3, PM-4, SCH-3 tests

**Acceptance:** `composer test` exits 0. Spec checklist fully checked.

---

### V-2: AGENTS.md update + BRP PRD downstream coordination

**Goal:** repository docs reflect the new auth model; BRP team notified to update their PRD.

**Depends on:** V-1
**Estimated:** 1h

**Changes to `AGENTS.md`** (project root, NOT repo-root AGENTS.md):
1. Add to Stack section: "Auth flow is multi-app (6 apps cataloged in `apps` table); `roles.es_super_admin=TRUE` bypasses `has-app:*` and `rbac:*` checks."
2. Document the new `/auth/validate-token` endpoint (Bearer pattern, 5-min cache, distinct from `/auth/validate-key` which remains X-API-Key/SAIlus).
3. Fix the inaccurate note about SQLite testing — `composer test` requires MySQL (per `phpunit.xml`).
4. Document `crm:backfill-personas` command (--dry-run flag, idempotent).

**BRP PRD coordination:**
- Create a coordination note (e.g., `Docs/COORDINATION-BRP-2026-07-30.md`) listing:
  - Endpoint name: `/auth/validate-token` (NOT `/auth/validate-key`)
  - Header: `Authorization: Bearer <sanctum-token>` (NOT `X-API-Key`)
  - Response shape unchanged: `{valid, usuario_id, email, apps, permisos, cached, validated_at}`
  - Owner: BRP team to update `Asistencia BRP/Back-BRP/Docs/PRD-BRP.md`

**Acceptance:** AGENTS.md has 4 updates. Coordination note exists. (BRP team's PRD update is OUT OF SCOPE for this PR — just the coordination handoff.)

---

## Day Plan

### Day 1 — Pre-Migrations (BLOCKER)
| Time | Task | Hours |
|---|---|---|
| 09:00–12:00 | PM-1 (roles slug/super_admin) | 3h |
| 13:00–15:00 | PM-2 (personas table) | 2h |
| 15:00–17:00 | PM-3 (contacto identificacion + persona_id) | 2h |
| | **End-of-day checkpoint:** `composer test --filter='Migrations'` all green | |
| **Total** | | **8h** |

### Day 2 — New Schema + Seeders
| Time | Task | Hours |
|---|---|---|
| 09:00–11:00 | PM-4 (backfill command) | 2h |
| 11:00–13:00 | SCH-1 (apps table) + SCH-2 (usuario_app pivot) | 2h |
| 14:00–17:00 | SCH-3 (Eloquent models) | 3h |
| | **End-of-day checkpoint:** `composer test --filter='Migrations|Models'` green | |
| **Total** | | **7h** |

### Day 3 — Endpoints: login + validate-token + me
| Time | Task | Hours |
|---|---|---|
| 09:00–10:00 | SE-1, SE-2, SE-3 (seeders + DatabaseSeeder wiring) | 3h |
| 13:00–15:00 | E-1 (UserAppsResolver + LoginResponse) | 3h |
| 15:00–17:00 | E-2 (LoginUseCase + AuthController modified) | 3h |
| | **End-of-day checkpoint:** `curl POST /auth/login` returns apps | |
| **Total** | | **9h** |

### Day 4 — Middleware + Validate-token + Admin endpoints
| Time | Task | Hours |
|---|---|---|
| 09:00–12:00 | E-3 (ValidateTokenUseCase + Controller + Cache) | 4h |
| 12:00–14:00 | E-5 (PermisoRepository extension) | 2h |
| 14:00–16:00 | M-1 (EnsureUserHasApp middleware) | 2h |
| 16:00–18:00 | E-4 (MeController + use cases) | 3h |
| | **End-of-day checkpoint:** all 4 canonical users can hit `/me` and `/validate-token` | |
| **Total** | | **11h** |

### Day 5 — Admin endpoints + Tests + Verification
| Time | Task | Hours |
|---|---|---|
| 09:00–12:00 | E-6 + E-7 (admin use cases + controller + routes) | 5h |
| 13:00–15:00 | T-1 + T-2 (Unit + Feature suites) | 3h |
| 15:00–17:00 | T-3 + V-1 (E2E smoke + full `composer test`) | 2h |
| 17:00–18:00 | V-2 (AGENTS.md + coordination note) | 1h |
| **Total** | | **11h** |

**Grand total:** 8 + 7 + 9 + 11 + 11 = **46 hours (≈5.75 working days at 8h/day)**

> ⚠️ The design estimated 5 days. With Strict TDD (red-green-refactor on EVERY task, ~30% overhead) and E-3's cache logic complexity, this realistically lands at 6 working days. Recommend front-loading PM tasks to absorb any rollbacks discovered late.

---

## Risk Checklist (carry-over from proposal)

| # | Risk | Mitigation in tasks |
|---|---|---|
| R1 | Data loss in backfill | PM-4 uses `--dry-run` default false but reports counts; PM-4 test asserts dry-run reports correctly before any apply |
| R2 | 5-min stale cache after revoke | Documented in design §10.2; not blocking MVP |
| R3 | `validate-token` perf | E-3 uses `Cache::remember` with DB driver; manual load test in T-3 |
| R5 | Cross-app unauthorized access | E-6 + E-7 enforce super-admin guard; covered by 403 tests in E-7 + M-1 |
| R7 | PRD user IDs vs real DB IDs | SE-3 looks up by EMAIL (not id) |
| R9 | BRP PRD references `validate-key` | V-2 creates coordination note (BRP's update is out of scope) |
| R10 | `composer test` requires MySQL | T-1 docs the dependency; V-1 verifies in CI |

---

## Cross-cutting Notes

### Files NOT to create (deferred, see design §2.9)
- `Modules/Identity/` module — no, use `Shared`
- `App\Http\Resources\PersonaResource.php` — no personas API in MVP
- `app/Application/UseCases/Personas/*.php` — only the artisan command exists
- No FK/property changes to `Modules\Administrativo\Models\Proveedor` or `Colaborador`

### Code patterns to follow (per explore §5)
- Use `Modules\Shared\Models\Usuario` (canonical), NOT `App\Models\Usuario`
- `RefreshDatabase` trait on all Feature tests
- `#[PHPUnit\Framework\Attributes\Test]` attribute on every test method
- `$this->withHeader('Authorization', 'Bearer ' . $token)->postJson(...)` for auth
- `App\Models\*` deprecated wrappers allowed in tests but production code uses `Modules\Shared\Models\*`

### Strict TDD discipline
- Never write implementation before the test. Run the test, see it fail for the right reason.
- Commit test + impl in the same PR only when green.
- After green, run Pint (`./vendor/bin/pint`) and re-run tests.
- If a test passes immediately (was already green), the test was wrong — rewrite or remove it.

### When a task grows beyond 4h
- Split it. A 6h task usually hides 2 independent units of work.
- Re-number sub-tasks with letter suffix (e.g., `E-3a`, `E-3b`).
- Update this document BEFORE starting the sub-task.

---

## Phase TE: Token-Exchange Endpoint (Extension — Batch 2g)

> Added 2026-07-31 as an extension to the multi-app-access change, implementing
> the SAIlus integration contract from `Docs/integrations/sailus-integration.md`.

### TE-1: Audit log migration + model
- **Status:** [x] DONE — `database/migrations/2026_07_31_120000_create_auth_audit_log_table.php` + `app/Models/AuthAuditLog.php`. Append-only table with 3 composite indexes (email+created_at, ip+created_at, created_at). `tests/Feature/Migration/CreateAuthAuditLogTableTest.php` (11/11 green).

### TE-2: AuthAuditService (write-only, fire-and-forget)
- **Status:** [x] DONE — `app/Application/Services/AuthAuditService.php`. Swallows DB errors and logs to `laravel.log`. Lowercases email, truncates user_agent to 500 chars, persists empty metadata as `[]`. `tests/Unit/Application/AuthAuditServiceTest.php` (7/7 green).

### TE-3: TokenExchangeUseCase + TokenExchangeResult
- **Status:** [x] DONE — `app/Application/UseCases/Auth/TokenExchangeUseCase.php` + `app/Application/DTOs/Auth/TokenExchangeResult.php`. Uses `Hash::check` (not `Auth::attempt`) to avoid session side-effects. TTL via `env('TOKEN_EXCHANGE_TTL')` (default 3600s). Audits every call. `tests/Unit/Application/TokenExchangeUseCaseTest.php` (13/13 green).

### TE-4: TokenExchangeController + FormRequest + AuditContextMiddleware + Route
- **Status:** [x] DONE — `app/Http/Controllers/API/Auth/TokenExchangeController.php` + `app/Http/Requests/TokenExchangeRequest.php` + `app/Http/Middleware/AuditContextMiddleware.php`. Route: `POST /api/v1/auth/token-exchange` with triple middleware (`throttle:10,1`, `throttle:token-exchange-email`, `audit.context`). `tests/Feature/Auth/TokenExchangeTest.php` (19/19 green) + `tests/Unit/Http/Middleware/AuditContextMiddlewareTest.php` (9/9 green).

### TE-5: Email-based rate limiter (anti-bruteforce)
- **Status:** [x] DONE — `app/Providers/AppServiceProvider.php` registers `RateLimiter::for('token-exchange-email', ...)`: 5 attempts per 10 minutes per email, project envelope on 429 (`error: too_many_attempts`). Covered by `eleven_requests_in_one_minute_returns_429_on_eleventh` and `six_failed_requests_with_same_email_in_ten_min_returns_429_on_sixth`.


