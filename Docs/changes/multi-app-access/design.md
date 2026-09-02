# Design: Multi-app Access & Party Model

> **Phase:** design
> **Date:** 2026-07-30
> **Change:** `multi-app-access`
> **Driving PRD:** `Docs/PRD-MultiApp-Access.md`
> **Related spec:** `openspec/changes/multi-app-access/specs/` (7 spec files, 217 scenarios)
> **Persistence mode:** hybrid (this file + engram summary)

This document is the **HOW**. It bridges the proposal (WHY) and the specs (WHAT) by naming every file, class, method, and DB operation. An implementer should be able to start coding immediately by following §2 + §3 + §4.

---

## 1. Architecture Overview

The CRM evolves from a Sanctum-only auth backend into an **identity platform**: it now catalogs 6 apps, assigns users to apps via a `usuario_app` pivot, validates Bearer tokens for external consumers (BRP), and centralizes human identity in a `personas` table. The change is **additive** — every existing endpoint (login, validate-key, CRUD) keeps its contract.

**Layering (unchanged):** Routes → Controllers (thin) → Use Cases (orchestration) → Eloquent Repositories → Models. New middleware `EnsureUserHasApp` slots into the existing pipeline.

```
┌──────────────────────────────────────────────────────────────────────────┐
│                        POST /api/v1/auth/login (MODIFIED)                │
│                                                                        │
│   AuthController::login                                                │
│      │                                                                 │
│      ▼                                                                 │
│   LoginUseCase::execute                                                │
│      │ ├─ validate email/password (estado=Activo)                       │
│      │ ├─ $user->createToken('auth-token')                             │
│      │ └─ UserAppsResolver::resolve($user)                             │
│      │      ├─ if user has es_super_admin → ALL active apps            │
│      │      └─ else → join usuario_app + apps (activo=true)            │
│      │                                                                 │
│      ▼                                                                 │
│   LoginResponse { token, usuario, apps[] }                             │
│      └─ toArray() → {token, usuario:{id,email,nombres,apellidos},     │
│                      apps:[{slug,nombre,rol,rol_id}]}                   │
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────┐
│                  GET /api/v1/auth/validate-token (NEW)                   │
│                                                                        │
│   ValidateTokenController::__invoke                                    │
│      │                                                                 │
│      ▼                                                                 │
│   ValidateTokenUseCase::execute($bearerToken)                         │
│      │ 1. if X-API-Key header present → 400 bearer_required            │
│      │ 2. hash = sha256($token); cache key = "auth:validate_token:{hash}"│
│      │ 3. Cache::remember($key, 300s, fn() => …)                       │
│      │      ├─ Sanctum::findToken($token) → null if 401               │
│      │      ├─ $user = $pat->tokenable; estado check                   │
│      │      ├─ apps = UserAppsResolver::resolve($user)                 │
│      │      ├─ permisos = PermisoRepository::listFor($user)            │
│      │      └─ return {valid, usuario_id, email, apps, permisos,       │
│      │                 cached:false, validated_at:now()->toIso8601String()}│
│      │ 4. if cache hit → set cached:true, validated_at: same as stored │
│      │ 5. response header X-Cache: HIT|MISS                            │
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────┐
│                  GET /api/v1/me, /me/apps, /me/apps/{slug}/permisos    │
│                                                                        │
│   MeController::show / apps / permisos                                 │
│      │                                                                 │
│      ▼                                                                 │
│   GetUserProfileUseCase / GetMyAppsUseCase / GetMyPermisosUseCase     │
│      │                                                                 │
│      │ for /me/apps/{slug}/permisos:                                   │
│      ▼                                                                 │
│   EnsureUserHasApp('slug')  ←─ middleware                              │
│      │ ├─ if user.rol.es_super_admin → next()                          │
│      │ ├─ UsuarioApp::where('usuario_id', $user->id)                   │
│      │        ->whereHas('app', fn($q) => $q->where('slug', $slug)     │
│      │                                   ->where('activo', true))      │
│      │        ->exists()                                                │
│      │ └─ if not → 403 {error: "app_access_denied"}                    │
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────┐
│                  POST /api/v1/usuarios/{id}/apps (admin NEW)            │
│                                                                        │
│   UsuarioAppController::store                                         │
│      │  middleware: ['auth:sanctum', 'rbac:usuarios.app-assign']       │
│      │                                                                 │
│      ▼                                                                 │
│   AssignUsuarioAppUseCase::execute($usuarioId, $appSlug, $rolSlug)    │
│      │ 1. caller must be super-admin (routes layer + Service)           │
│      │ 2. resolve App::where('slug', $appSlug)->first() → 422 if null  │
│      │ 3. resolve Rol::where('slug', $rolSlug)->first() → 422 if null  │
│      │ 4. uniqueness check (usuario_id, app_id) → 409 if exists       │
│      │ 5. DB::transaction → UsuarioApp::create([...])                  │
│      │ 6. evict cache: Cache::tags("auth:user:{$userId}")->flush()    │
│      └─ return 201 with the new row                                    │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Module & File Layout

### 2.1 Migrations (global)

All migrations live in `database/migrations/` (convention from `explore §2`).

| Action | File | Purpose |
|---|---|---|
| CREATE | `database/migrations/2026_07_30_080000_add_slug_and_es_super_admin_to_roles_table.php` | **PM-1** blocks BRP roles seeder (depends on `roles` table existing) |
| CREATE | `database/migrations/2026_07_30_080100_create_personas_table.php` | **PM-2** new table (no FK target dependency) |
| CREATE | `database/migrations/2026_07_30_080200_create_apps_table.php` | new table (no FK target dependency) |
| CREATE | `database/migrations/2026_07_30_080300_create_usuario_app_table.php` | new table (FK → `usuarios`, `apps`, `roles`) |
| CREATE | `database/migrations/2026_07_30_080400_add_identificacion_and_persona_id_to_contacto_table.php` | **PM-3** alters `contacto` (depends on `personas` table above) |
| CREATE | `database/migrations/2026_07_30_080500_backfill_personas_from_contacto_data.php` | data migration (idempotent) |

**Ordering rationale:**
1. `add_slug_and_es_super_admin_to_roles` — must be first because `apps` and `usuario_app` FK chain references `roles` and `BrpRolesSeeder` needs `slug`.
2. `create_personas_table` — second, because `contacto.persona_id` FK needs the target.
3. `create_apps_table` — independent.
4. `create_usuario_app_table` — depends on `usuarios`, `apps`, `roles` (all exist).
5. `add_identificacion_and_persona_id_to_contacto_table` — depends on `personas`.
6. `backfill_personas_from_contacto_data` — depends on `contacto.persona_id` column existing.

### 2.2 Eloquent Models

| Action | File | Purpose |
|---|---|---|
| CREATE | `Modules/Shared/app/Models/App.php` | Maps `apps` table |
| CREATE | `Modules/Shared/app/Models/UsuarioApp.php` | Maps `usuario_app` pivot |
| CREATE | `Modules/Shared/app/Models/Persona.php` | Maps `personas` table |
| MODIFY | `Modules/Shared/app/Models/Usuario.php` | Add `apps()` BelongsToMany, `rol.es_super_admin` accessor |
| MODIFY | `Modules/Shared/app/Models/Rol.php` | Add `slug`, `es_super_admin` to `$fillable`; add `isSuperAdmin()` method |
| MODIFY | `Modules/CRM/app/Models/Contacto.php` | Add `persona_id` to `$fillable`, `persona()` BelongsTo relationship |
| CREATE | `app/Models/App.php` | Deprecated wrapper extending `Modules\Shared\Models\App` |
| CREATE | `app/Models/UsuarioApp.php` | Deprecated wrapper |
| CREATE | `app/Models/Persona.php` | Deprecated wrapper |

**Signatures:**

```php
// Modules/Shared/app/Models/App.php
class App extends Model {
    use HasFactory, SoftDeletes;
    protected $table = 'apps';
    protected $fillable = ['slug','nombre','tipo','auth_type','activo','descripcion'];
    protected $casts = ['activo' => 'boolean'];
    public function usuarioApps(): HasMany { return $this->hasMany(UsuarioApp::class, 'app_id'); }
    public function usuarios(): BelongsToMany { return $this->belongsToMany(Usuario::class, 'usuario_app', 'app_id', 'usuario_id')->withPivot('rol_id')->withTimestamps(); }
}

// Modules/Shared/app/Models/UsuarioApp.php
class UsuarioApp extends Model {
    use HasFactory;
    protected $table = 'usuario_app';
    protected $fillable = ['usuario_id','app_id','rol_id'];
    public function usuario(): BelongsTo { return $this->belongsTo(Usuario::class, 'usuario_id'); }
    public function app(): BelongsTo { return $this->belongsTo(App::class, 'app_id'); }
    public function rol(): BelongsTo { return $this->belongsTo(Rol::class, 'rol_id'); }
}

// Modules/Shared/app/Models/Persona.php
class Persona extends Model {
    use HasFactory, SoftDeletes;
    protected $table = 'personas';
    protected $fillable = ['identificacion_tipo','identificacion_numero','nombres','apellidos','email_principal','telefono_principal'];
    public function contactos(): HasMany { return $this->hasMany(\Modules\CRM\Models\Contacto::class, 'persona_id'); }
}

// Modules/Shared/app/Models/Rol.php (MODIFY)
class Rol extends Model {
    use HasFactory, SoftDeletes;
    protected $table = 'roles';
    protected $fillable = ['nombre','slug','es_super_admin','estado','created_by','updated_by']; // ADD slug, es_super_admin
    protected $casts = ['es_super_admin' => 'boolean'];
    public function isSuperAdmin(): bool { return (bool) $this->es_super_admin; }
    public function usuarioApps(): HasMany { return $this->hasMany(UsuarioApp::class, 'rol_id'); }
}

// Modules/Shared/app/Models/Usuario.php (MODIFY)
class Usuario extends Authenticatable {
    // ... existing fields
    public function apps(): BelongsToMany { return $this->belongsToMany(App::class, 'usuario_app', 'usuario_id', 'app_id')->withPivot('rol_id')->withTimestamps(); }
    public function isSuperAdmin(): bool { return $this->rol?->isSuperAdmin() ?? false; }
}

// Modules/CRM/app/Models/Contacto.php (MODIFY)
class Contacto extends Model {
    // ... existing fields
    protected $fillable = [...existing..., 'identificacion_tipo', 'identificacion_numero', 'persona_id']; // ADD
    public function persona(): BelongsTo { return $this->belongsTo(\Modules\Shared\Models\Persona::class, 'persona_id'); }
}
```

### 2.3 Application Layer (Use Cases + DTOs + Services)

| Action | File | Purpose |
|---|---|---|
| CREATE | `app/Application/UseCases/Auth/ValidateTokenUseCase.php` | Validates Bearer token, returns apps+permisos (BRP-facing) |
| MODIFY | `app/Application/UseCases/Auth/LoginUseCase.php` | Inject `UserAppsResolver`, return `apps[]` in response |
| CREATE | `app/Application/UseCases/Auth/Me/GetMyProfileUseCase.php` | Returns user + apps for `/me` |
| CREATE | `app/Application/UseCases/Auth/Me/GetMyAppsUseCase.php` | Returns just apps for `/me/apps` |
| CREATE | `app/Application/UseCases/Auth/Me/GetMyPermisosUseCase.php` | Returns permisos for user+app |
| CREATE | `app/Application/UseCases/Auth/Admin/ListUsuarioAppsUseCase.php` | Admin GET `/usuarios/{id}/apps` |
| CREATE | `app/Application/UseCases/Auth/Admin/AssignUsuarioAppUseCase.php` | Admin POST `/usuarios/{id}/apps` |
| CREATE | `app/Application/UseCases/Auth/Admin/RevokeUsuarioAppUseCase.php` | Admin DELETE `/usuarios/{id}/apps/{app_id}` |
| CREATE | `app/Application/Services/UserAppsResolver.php` | Shared logic: returns apps array for a user (with super-admin bypass + activo filter) |
| CREATE | `app/Application/Services/SuperAdminGuard.php` | Helper: `callerMustBeSuperAdmin(Usuario $caller)` |
| MODIFY | `app/Application/DTOs/LoginResponse.php` | Add `apps` field, expose via `toArray()` |
| CREATE | `app/Application/DTOs/AppAssignmentDto.php` | Value object: `{slug, nombre, rol, rol_id}` |
| CREATE | `app/Application/DTOs/ValidateTokenResponseDto.php` | Value object for the BRP contract |

### 2.4 Infrastructure (Middleware + Cache)

| Action | File | Purpose |
|---|---|---|
| CREATE | `app/Infrastructure/Auth/EnsureUserHasAppMiddleware.php` | Alias `has-app:slug`; checks pivot + super-admin bypass |
| CREATE | `app/Infrastructure/Auth/TokenCacheService.php` | Wraps `Cache::remember` with `auth:validate_token:{hash}` key |
| MODIFY | `bootstrap/app.php` | Register `has-app` alias in middleware |

### 2.5 HTTP Layer (Controllers + Requests + Resources)

| Action | File | Purpose |
|---|---|---|
| CREATE | `app/Http/Controllers/API/Auth/ValidateTokenController.php` | `__invoke` for `/auth/validate-token` |
| CREATE | `app/Http/Controllers/API/MeController.php` | `show`, `apps`, `permisos` methods |
| CREATE | `app/Http/Controllers/API/UsuarioAppController.php` | `index`, `store`, `destroy` for admin |
| MODIFY | `app/Http/Controllers/API/AuthController.php` | Inject `UserAppsResolver` into LoginUseCase (via constructor). No signature change. |
| MODIFY | `routes/api.php` | Add 8 new routes (see §4) |
| CREATE | `app/Http/Requests/AssignUsuarioAppRequest.php` | Validates `app_slug`, `rol_slug` for POST |
| CREATE | `app/Http/Resources/AppAssignmentResource.php` | Shape: `{slug, nombre, rol, rol_id}` |
| CREATE | `app/Http/Resources/MeResource.php` | Shape: `{usuario, apps}` |
| CREATE | `app/Http/Resources/PermisosResource.php` | Shape: `{app, permisos}` |

### 2.6 Database Seeders

| Action | File | Purpose |
|---|---|---|
| CREATE | `database/seeders/AppsSeeder.php` | Inserts the 6 apps (idempotent, firstOrCreate) |
| CREATE | `database/seeders/BrpRolesSeeder.php` | Inserts `brp-admin`, `brp-lider`, `brp-psicologo` (idempotent). Requires PM-1. |
| CREATE | `database/seeders/UsuarioAppAssignmentsSeeder.php` | Assigns the 4 canonical users (Vos=5, Lorena=1, Patricia=4, Jaime=3) per pre-resolved decisions. Lookup by email. |
| MODIFY | `database/seeders/DatabaseSeeder.php` | Add `AppsSeeder`, `BrpRolesSeeder`, `UsuarioAppAssignmentsSeeder` to call chain |

**Important:** `UsuariosTableSeeder` is NOT in `DatabaseSeeder::run()` (see explore §6 high-risk #5). Per the spec, controllers' tests already create users via factory, so we don't break the canonical flow. But we DO add apps + assignments to `DatabaseSeeder` so `migrate:fresh --seed` produces a BRP-ready env.

### 2.7 Console Commands

| Action | File | Purpose |
|---|---|---|
| CREATE | `app/Console/Commands/BackfillPersonasFromContacto.php` | `crm:backfill-personas --dry-run` artisan command (REQ-PERSONAS-5) |

### 2.8 Tests

| Action | File | Type | Coverage |
|---|---|---|---|
| CREATE | `tests/Feature/Shared/AppsTableTest.php` | Feature/Migration | REQ-APPS-1 + REQ-APPS-2 (21 scenarios) |
| CREATE | `tests/Unit/Shared/AppModelTest.php` | Unit | REQ-APPS-3 (relationship, lookup) |
| CREATE | `tests/Feature/Seeders/AppsSeederTest.php` | Feature/Seeder | REQ-APPS-2 idempotency |
| CREATE | `tests/Feature/Shared/UsuarioAppPivotTest.php` | Feature/Migration | REQ-USRAPP-1 (UNIQUE, FK cascades) |
| CREATE | `tests/Feature/Seeders/UsuarioAppAssignmentsSeederTest.php` | Feature/Seeder | REQ-USRAPP-2 (4 canonical users, 11 pivot rows) |
| CREATE | `tests/Feature/Migration/AddSlugToRolesMigrationTest.php` | Feature/Migration | REQ-PRE-1 (slug backfill, super-admin flag) |
| CREATE | `tests/Feature/Migration/AddIdentificacionToContactoMigrationTest.php` | Feature/Migration | REQ-PRE-2 (nullable columns, FK) |
| CREATE | `tests/Feature/Migration/CreatePersonasTableTest.php` | Feature/Migration | REQ-PRE-3 (schema, partial unique) |
| CREATE | `tests/Feature/Console/BackfillPersonasCommandTest.php` | Feature/Console | REQ-PRE-4 + REQ-PERSONAS-5 (--dry-run, idempotency, dedup) |
| CREATE | `tests/Unit/Shared/PersonaTest.php` | Unit | REQ-PERSONAS-1 + REQ-PERSONAS-3 |
| CREATE | `tests/Feature/Shared/ContactoPersonaRelationshipTest.php` | Feature | REQ-PERSONAS-3 bidirectional |
| CREATE | `tests/Feature/Auth/LoginResponseIncludesAppsTest.php` | Feature | REQ-LOGIN-1..10 (Vos/Lorena/Patricia/Jaime; 29 scenarios) |
| MODIFY | `tests/Feature/API/AuthTest.php` | Feature | Update existing login test to assert `data.apps` is present |
| CREATE | `tests/Feature/Auth/ValidateTokenEndpointTest.php` | Feature | REQ-VALTOK-1..9 (cache, 401, X-API-Key 400, 30 scenarios) |
| CREATE | `tests/Feature/Me/MeEndpointsTest.php` | Feature | REQ-ME-1..9 (42 scenarios) |
| CREATE | `tests/Feature/Auth/UsuarioAppAdminTest.php` | Feature | REQ-USRAPP-3..7 (40 scenarios) |
| CREATE | `tests/Unit/Auth/ValidateTokenUseCaseTest.php` | Unit | Cache hit/miss, super-admin bypass, permisos aggregation |
| CREATE | `tests/Unit/Auth/UserAppsResolverTest.php` | Unit | Super-admin bypass, activo filter, empty apps |
| CREATE | `tests/Unit/Auth/EnsureUserHasAppMiddlewareTest.php` | Unit | 403, allow, super-admin bypass |
| CREATE | `tests/Unit/Auth/LoginUseCaseIncludesAppsTest.php` | Unit | LoginUseCase returns apps in response |

### 2.9 Files NOT to create (deferred / out of scope)

- `Modules/Identity/` — no new module; `Shared` is the right home.
- `App\Http\Resources\PersonaResource.php` — deferred (no personas API in MVP).
- `app/Application/UseCases/Personas/*.php` — only the artisan command is MVP.
- `Modules/Administrativo/Models/Proveedor.php` / `Colaborador.php` — NOT modified (out of scope per PRD §6.3).

---

## 3. Migration Strategy

### 3.1 Pre-migration #1: `add_slug_and_es_super_admin_to_roles_table`

**Filename:** `2026_07_30_080000_add_slug_and_es_super_admin_to_roles_table.php`

**Up behavior:**
```php
public function up(): void {
    // No doctrine/dbal — raw SQL
    DB::statement('ALTER TABLE `roles` ADD COLUMN `slug` VARCHAR(50) NULL UNIQUE AFTER `nombre`');
    DB::statement('ALTER TABLE `roles` ADD COLUMN `es_super_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `slug`');
    DB::statement('CREATE UNIQUE INDEX `idx_roles_slug` ON `roles` (`slug`)');
    // Idempotency guard for fresh vs seeded DB
    DB::statement("UPDATE `roles` SET `slug` = LOWER(REPLACE(`nombre`, ' ', '-')) WHERE `slug` IS NULL");
    DB::statement("UPDATE `roles` SET `es_super_admin` = 1 WHERE `id` = 1 AND `nombre` = 'SuperAdmin'");
}

public function down(): void {
    DB::statement('ALTER TABLE `roles` DROP INDEX `idx_roles_slug`');
    DB::statement('ALTER TABLE `roles` DROP COLUMN `es_super_admin`');
    DB::statement('ALTER TABLE `roles` DROP COLUMN `slug`');
}
```

**Data migration:** Updates `slug` for all existing rows using `LOWER(REPLACE(nombre, ' ', '-'))`. Sets `es_super_admin = TRUE` for `id = 1`. (`id = 1` is hardcoded because the existing seed assigns SuperAdmin to id=1; better than `WHERE nombre='SuperAdmin'` because nome may have accents.)

**Idempotency:** Resilient — on a rerun, `WHERE slug IS NULL` is a no-op (`LOWER(REPLACE('super-admin', ' ', '-'))` = `'super-admin'`), and `id = 1` is unique. The `ADD COLUMN` is NOT idempotent at DB level — the migration framework's `migrations` table prevents re-running.

### 3.2 Pre-migration #2: `create_personas_table`

**Filename:** `2026_07_30_080100_create_personas_table.php`

**Up behavior:**
```php
public function up(): void {
    Schema::create('personas', function (Blueprint $t) {
        $t->id();
        $t->string('identificacion_tipo', 10)->nullable();
        $t->string('identificacion_numero', 20)->nullable();
        $t->string('nombres', 100);
        $t->string('apellidos', 100);
        $t->string('email_principal', 150)->nullable();
        $t->string('telefono_principal', 30)->nullable();
        $t->timestamps();
        $t->softDeletes();
        $t->unique('identificacion_numero', 'idx_personas_identificacion'); // partial unique handled in DB via "WHERE identificacion_numero IS NOT NULL" — MySQL doesn't support partial unique; instead use composite logic
    });
}
```

**Important:** MySQL/MariaDB **DOES NOT support partial UNIQUE indexes natively**. The spec REQ-PERSONAS-1 says "UNIQUE (partial: only when not NULL)". Mitigation: Application-level de-duplication in `BackfillPersonasFromContacto` (skip if a persona with the same `identificacion_numero` exists). Alternative: use a generated column `identificacion_numero_unique VARCHAR(20) GENERATED ALWAYS AS (IF(identificacion_numero IS NULL, NULL, identificacion_numero)) STORED` with a UNIQUE index — but adds complexity. **Decision: application-level dedup; do not enforce DB partial unique.**

**Indexes:** `(identificacion_numero)` (plain) for backfill lookups. NOT unique.

**Down:** `Schema::dropIfExists('personas')`.

### 3.3 Pre-migration #3: `add_identificacion_and_persona_id_to_contacto_table`

**Filename:** `2026_07_30_080400_add_identificacion_and_persona_id_to_contacto_table.php`

**Up behavior:**
```php
public function up(): void {
    DB::statement('ALTER TABLE `contacto` ADD COLUMN `identificacion_tipo` VARCHAR(10) NULL AFTER `apellidos`');
    DB::statement('ALTER TABLE `contacto` ADD COLUMN `identificacion_numero` VARCHAR(20) NULL AFTER `identificacion_tipo`');
    // FK constraint — `personas` must exist (pre-migration #2)
    DB::statement('ALTER TABLE `contacto` ADD COLUMN `persona_id` BIGINT UNSIGNED NULL AFTER `id`');
    DB::statement('ALTER TABLE `contacto` ADD CONSTRAINT `fk_contacto_persona` FOREIGN KEY (`persona_id`) REFERENCES `personas`(`id`) ON DELETE SET NULL');
    DB::statement('CREATE INDEX `idx_contacto_persona` ON `contacto` (`persona_id`)');
    DB::statement('CREATE INDEX `idx_contacto_identificacion` ON `contacto` (`identificacion_numero`)');
}

public function down(): void {
    DB::statement('ALTER TABLE `contacto` DROP FOREIGN KEY `fk_contacto_persona`');
    DB::statement('ALTER TABLE `contacto` DROP INDEX `idx_contacto_persona`');
    DB::statement('ALTER TABLE `contacto` DROP INDEX `idx_contacto_identificacion`');
    DB::statement('ALTER TABLE `contacto` DROP COLUMN `persona_id`');
    DB::statement('ALTER TABLE `contacto` DROP COLUMN `identificacion_numero`');
    DB::statement('ALTER TABLE `contacto` DROP COLUMN `identificacion_tipo`');
}
```

**Data migration:** No rows are touched in this migration. The `crm:backfill-personas` artisan command (separate, REQ-PERSONAS-5) does the data work, with `--dry-run` and idempotency.

**Idempotency:** Laravel's migration table prevents re-running the `ALTER TABLE`.

### 3.4 Data migration: `backfill_personas_from_contacto_data`

**Filename:** `2026_07_30_080500_backfill_personas_from_contacto_data.php`

**Implementation:** this is a thin migration whose `up()` calls `Artisan::call('crm:backfill-personas', ['--dry-run' => false])`. The artisan command lives in `app/Console/Commands/BackfillPersonasFromContacto.php`.

**Algorithm** (in the command, not the migration):
```php
public function handle(): int {
    if (!Schema::hasTable('personas')) {
        $this->error('Run php artisan migrate first.');
        return self::FAILURE;
    }
    $dryRun = $this->option('dry-run');
    $inserted = 0; $updated = 0; $skipped = 0;

    DB::transaction(function () use ($dryRun, &$inserted, &$updated, &$skipped) {
        $contactos = DB::table('contacto')
            ->whereNull('deleted_at')
            ->whereNotNull('email_contacto')
            ->orderBy('id')
            ->cursor();

        foreach ($contactos as $c) {
            // Match by email (primary) — fallback to nombres+apellidos
            $persona = DB::table('personas')
                ->where('email_principal', $c->email_contacto)
                ->first();

            if (!$persona) {
                if ($dryRun) { $inserted++; continue; }
                $personaId = DB::table('personas')->insertGetId([
                    'identificacion_tipo' => $c->identificacion_tipo ?? null,
                    'identificacion_numero' => $c->identificacion_numero ?? null,
                    'nombres' => $c->nombres,
                    'apellidos' => $c->apellidos ?? '',
                    'email_principal' => $c->email_contacto,
                    'telefono_principal' => $c->movil ?? $c->tel_contacto ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $inserted++;
            } else {
                $personaId = $persona->id;
            }

            if ($dryRun) { $updated++; continue; }
            DB::table('contacto')->where('id', $c->id)->update(['persona_id' => $personaId]);
            $updated++;
        }
    });

    $this->info("Inserted: $inserted, Updated: $updated" . ($dryRun ? ' (DRY RUN)' : ''));
    return self::SUCCESS;
}
```

**Idempotency:** the second run finds existing personas by `email_principal` and only updates `contacto.persona_id` if it's NULL (which is a no-op after first run). Testing this is a unit test in `BackfillPersonasCommandTest`.

### 3.5 New tables

**`apps` (2026_07_30_080200):**
```php
Schema::create('apps', function (Blueprint $t) {
    $t->id();
    $t->string('slug', 50);
    $t->string('nombre', 100);
    $t->enum('tipo', ['internal','external','customer'])->default('internal');
    $t->enum('auth_type', ['sanctum','api_key'])->default('sanctum');
    $t->boolean('activo')->default(true);
    $t->text('descripcion')->nullable();
    $t->timestamps();
    $t->softDeletes();
    $t->unique('slug', 'idx_apps_slug');
});
```

**`usuario_app` (2026_07_30_080300):**
```php
Schema::create('usuario_app', function (Blueprint $t) {
    $t->id();
    $t->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
    $t->foreignId('app_id')->constrained('apps')->onDelete('cascade');
    $t->foreignId('rol_id')->constrained('roles')->onDelete('restrict');
    $t->timestamps();
    $t->unique(['usuario_id', 'app_id'], 'idx_usuario_app_unique');
    $t->index('usuario_id', 'idx_usuario_app_usuario');
    $t->index('app_id', 'idx_usuario_app_app');
});
```

### 3.6 Migration test pattern

Mirrors `tests/Feature/Migration/PipelineEtapaMigrationTest.php`:
- `Schema::create()` for required dependent tables inline (OR rely on `RefreshDatabase` + ordered migrations).
- Run `require database_path('migrations/...')` to get the migration object, call `->up()`.
- Assert via `DB::select('SHOW COLUMNS FROM ...')` or `Schema::hasColumn()`.

For our case, the simpler pattern is **integration**: run `php artisan migrate` on a fresh test DB, then assert. Use `RefreshDatabase` trait — it runs all migrations during setUp.

---

## 4. Endpoint Implementation Details

### 4.1 `POST /api/v1/auth/login` (MODIFIED)

**Route:** unchanged (`routes/api.php:58`).
**Controller:** `AuthController::login` — no signature change.
**Use case:** `LoginUseCase::execute` — INJECT `UserAppsResolver` via constructor.

**Modified `LoginUseCase`:**
```php
class LoginUseCase {
    public function __construct(
        private UserAppsResolver $appsResolver,
    ) {}

    public function execute(LoginRequest $request): LoginResponse {
        // ... existing validation
        $token = $usuario->createToken('auth-token')->plainTextToken;
        $apps = $this->appsResolver->resolve($usuario);
        return new LoginResponse(token: $token, usuario: $usuario, apps: $apps);
    }
}
```

**Modified `LoginResponse`:**
```php
class LoginResponse {
    public function __construct(
        public string $token,
        public Usuario $usuario,
        public array $apps = [], // NEW
    ) {}

    public function toArray(): array {
        return [
            'token' => $this->token,
            'usuario' => [
                'id' => $this->usuario->id,
                'email' => $this->usuario->email,
                'nombres' => $this->usuario->nombre, // PRD §7.3 example shows 'nombres'/'apellidos'
                'apellidos' => '', // map from $this->usuario->nombre (currently concatenated)
            ],
            'apps' => array_map(fn($a) => $a->toArray(), $this->apps),
        ];
    }
}
```

> **Note:** existing `Usuario` table has a single `nombre` column (150 chars), not `nombres`/`apellidos`. The PRD example shows `nombres`/`apellidos`. For the MVP, we split at the first space: `'Juan Pérez' → ['Juan', 'Pérez']`. The `apellidos` field on `usuario` is NOT in the schema. The login response will use the full `nombre` as `nombres` and an empty string for `apellidos` (or skip the field). **Open decision: confirm with user whether to split or use the full name.**

### 4.2 `UserAppsResolver` (new service)

`app/Application/Services/UserAppsResolver.php`:
```php
class UserAppsResolver {
    public function resolve(Usuario $user): array {
        if ($user->isSuperAdmin()) {
            return App::where('activo', true)
                ->orderBy('slug')
                ->get()
                ->map(fn($app) => new AppAssignmentDto(
                    slug: $app->slug,
                    nombre: $app->nombre,
                    rol: $user->rol->slug ?? $user->rol->nombre,
                    rol_id: $user->rol_id,
                ))->all();
        }

        return UsuarioApp::with(['app', 'rol'])
            ->where('usuario_id', $user->id)
            ->whereHas('app', fn($q) => $q->where('activo', true))
            ->get()
            ->map(fn($ua) => new AppAssignmentDto(
                slug: $ua->app->slug,
                nombre: $ua->app->nombre,
                rol: $ua->rol->slug ?? $ua->rol->nombre,
                rol_id: $ua->rol_id,
            ))->all();
    }
}
```

### 4.3 `GET /api/v1/auth/validate-token` (NEW)

**Route:**
```php
// In routes/api.php, OUTSIDE the auth:sanctum group (it self-validates)
Route::get('/auth/validate-token', [ValidateTokenController::class, '__invoke'])
    ->middleware(['throttle:120,1']) // 120 req/min per IP (high-limit; BRP may hammer)
    ->name('auth.validate-token');
```

**Controller (`ValidateTokenController`):**
```php
class ValidateTokenController extends Controller {
    use ApiResponse;
    public function __construct(
        private ValidateTokenUseCase $useCase,
    ) {}

    public function __invoke(Request $request): JsonResponse {
        // 400 if X-API-Key is used (per REQ-VALTOK-5)
        if ($request->hasHeader('X-API-Key') && !$request->bearerToken()) {
            return $this->errorResponse('bearer_required', 400);
        }

        $token = $request->bearerToken();
        $result = $this->useCase->execute($token);
        if (!$result) {
            return $this->errorResponse('invalid_token', 401);
        }

        return $this->successResponse($result->toArray())
            ->header('X-Cache', $result->cached ? 'HIT' : 'MISS');
    }
}
```

**Use case (`ValidateTokenUseCase`):**
```php
class ValidateTokenUseCase {
    public function __construct(
        private TokenCacheService $cache,
        private UserAppsResolver $appsResolver,
        private PermisoRepositoryInterface $permisoRepo,
    ) {}

    public function execute(?string $token): ?ValidateTokenResponseDto {
        if (!$token) return null;

        $hash = hash('sha256', $token);
        $cacheKey = "auth:validate_token:{$hash}";
        $cachedPayload = $this->cache->remember($cacheKey, 300, fn() => $this->computeFresh($token));

        if (!$cachedPayload) return null; // computeFresh returned null → token invalid

        $cacheHit = $cachedPayload['cached'] === true;
        return new ValidateTokenResponseDto(
            valid: true,
            usuario_id: $cachedPayload['usuario_id'],
            email: $cachedPayload['email'],
            apps: $cachedPayload['apps'],
            permisos: $cachedPayload['permisos'],
            cached: $cacheHit,
            validated_at: $cachedPayload['validated_at'],
        );
    }

    private function computeFresh(string $token): ?array {
        $pat = PersonalAccessToken::findToken($token);
        if (!$pat) return null;
        $user = $pat->tokenable;
        if (!$user || $user->estado !== 'Activo') return null;

        $apps = $this->appsResolver->resolve($user);
        $permisos = $this->permisoRepo->listPermissionsForUsuario($user); // gather union

        return [
            'usuario_id' => $user->id,
            'email' => $user->email,
            'apps' => array_map(fn($a) => $a->toArray(), $apps),
            'permisos' => $permisos,
            'cached' => false,
            'validated_at' => now()->toIso8601String(),
        ];
    }
}
```

**Cache invalidation:** When admin revokes an app via `AssignUsuarioAppUseCase` / `RevokeUsuarioAppUseCase`, call `Cache::forget("auth:validate_token:{hash}")` for ALL active tokens of that user. To find tokens: `PersonalAccessToken::where('tokenable_id', $userId)->get()->each(fn($t) => Cache::forget("auth:validate_token:" . hash('sha256', $t->plain_text ?? '')))`. ⚠️ Sanctum doesn't expose the plain text after creation — tokens are stored hashed. **Mitigation: after expiration (TTL 5min), the cache auto-resolves. Trade-off is documented in R2.**

**Better mitigation:** Use `Cache::tags(["user:{$userId}"])` and call `Cache::tags(["user:{$userId}"])->flush()` on revoke. Requires `Cache::tags()` support (Redis/Memcached). Default DB cache driver does NOT support tags. **Decision: skip cache invalidation in MVP (R2 accepted).**

### 4.4 `GET /api/v1/me`, `/me/apps`, `/me/apps/{slug}/permisos` (NEW)

**Routes:**
```php
Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('me')->group(function () {
    Route::get('/', [MeController::class, 'show'])->name('me.show');
    Route::get('/apps', [MeController::class, 'apps'])->name('me.apps');
    Route::get('/apps/{slug}/permisos', [MeController::class, 'permisos'])
        ->middleware('has-app:slug')
        ->name('me.apps.permisos');
});
```

**Controller (`MeController`):**
```php
class MeController extends Controller {
    use ApiResponse;
    public function __construct(
        private GetMyProfileUseCase $profile,
        private GetMyAppsUseCase $apps,
        private GetMyPermisosUseCase $permisos,
    ) {}

    public function show(Request $request): JsonResponse {
        return $this->successResponse($this->profile->execute($request->user()));
    }

    public function apps(Request $request): JsonResponse {
        return $this->successResponse(['apps' => $this->apps->execute($request->user())]);
    }

    public function permisos(Request $request, string $slug): JsonResponse {
        $result = $this->permisos->execute($request->user(), $slug);
        if (!$result) return $this->errorResponse('app_not_found', 404);
        return $this->successResponse($result);
    }
}
```

**Use cases:** thin wrappers around `UserAppsResolver` + `PermisoRepository`.

### 4.5 `GET /api/v1/usuarios/{id}/apps` (admin NEW)

**Route:**
```php
Route::middleware(['auth:sanctum', 'throttle-mutations'])->prefix('usuarios/{id}/apps')->group(function () {
    Route::get('/', [UsuarioAppController::class, 'index'])->name('usuarios.apps.index');
    Route::post('/', [UsuarioAppController::class, 'store'])->name('usuarios.apps.store');
    Route::delete('/{app_id}', [UsuarioAppController::class, 'destroy'])->name('usuarios.apps.destroy');
});
```

**Controller:** thin; delegates to use cases. Each use case throws `AppAccessDenied` or returns null on failure.

```php
class UsuarioAppController extends Controller {
    use ApiResponse;
    public function __construct(
        private ListUsuarioAppsUseCase $list,
        private AssignUsuarioAppUseCase $assign,
        private RevokeUsuarioAppUseCase $revoke,
        private SuperAdminGuard $guard,
    ) {}

    public function index(Request $request, int $id): JsonResponse {
        $this->guard->assertSuperAdmin($request->user()); // 403 if not
        $result = $this->list->execute($id);
        if ($result === null) return $this->errorResponse('user_not_found', 404);
        return $this->successResponse($result);
    }

    public function store(AssignUsuarioAppRequest $request, int $id): JsonResponse {
        $this->guard->assertSuperAdmin($request->user());
        try {
            $row = $this->assign->execute($id, $request->validated());
            return $this->successResponse($row, 201, 'Asignación creada.');
        } catch (UserNotFoundException) {
            return $this->errorResponse('user_not_found', 404);
        } catch (AssignmentExistsException) {
            return $this->errorResponse('assignment_already_exists', 409);
        }
    }

    public function destroy(Request $request, int $id, int $app_id): JsonResponse {
        $this->guard->assertSuperAdmin($request->user());
        $result = $this->revoke->execute($id, $app_id);
        if (!$result) return $this->errorResponse('assignment_not_found', 404);
        return $this->successResponse(null, 200, 'Asignación revocada.');
    }
}
```

**Form Request (`AssignUsuarioAppRequest`):**
```php
class AssignUsuarioAppRequest extends FormRequest {
    public function authorize(): bool { return true; } // super-admin guard is in controller
    public function rules(): array {
        return [
            'app_slug' => 'required|string|exists:apps,slug',
            'rol_slug' => 'required|string|exists:roles,slug',
        ];
    }
}
```

### 4.6 `EnsureUserHasApp` middleware

`app/Infrastructure/Auth/EnsureUserHasAppMiddleware.php`:
```php
class EnsureUserHasAppMiddleware {
    public function handle(Request $request, Closure $next, string $slug): Response {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        // Super-admin bypass
        if ($user->isSuperAdmin()) {
            $request->attributes->set('current_app_slug', $slug);
            return $next($request);
        }

        $hasAccess = UsuarioApp::where('usuario_id', $user->id)
            ->whereHas('app', fn($q) => $q->where('slug', $slug)->where('activo', true))
            ->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'error' => 'app_access_denied',
                'message' => "User does not have access to app '{$slug}'",
            ], 403);
        }

        $request->attributes->set('current_app_slug', $slug);
        return $next($request);
    }
}
```

**Registration in `bootstrap/app.php`:**
```php
$middleware->alias([
    // ... existing
    'has-app' => \App\Infrastructure\Auth\EnsureUserHasAppMiddleware::class,
]);
```

### 4.7 `SuperAdminGuard` service

`app/Application/Services/SuperAdminGuard.php`:
```php
class SuperAdminGuard {
    public function assertSuperAdmin(?Usuario $user): void {
        if (!$user || !$user->isSuperAdmin()) {
            throw new AppAccessDeniedException('Super admin role required.');
        }
    }
}
```

Exception is caught in `bootstrap/app.php` `withExceptions()` and rendered as 403.

---

## 5. Seeder Strategy

### 5.1 `AppsSeeder`

```php
class AppsSeeder extends Seeder {
    private const APPS = [
        ['slug'=>'crm',         'nombre'=>'CRM Tecnoinnsoft',     'tipo'=>'internal', 'auth_type'=>'sanctum'],
        ['slug'=>'sailus',      'nombre'=>'SAIlus Gateway',       'tipo'=>'internal', 'auth_type'=>'sanctum'],
        ['slug'=>'mercurio',    'nombre'=>'Mercurio Gateway',     'tipo'=>'internal', 'auth_type'=>'sanctum'], // dual-write SAIlus→Mercurio hasta 2027-02-06
        ['slug'=>'marketing',   'nombre'=>'Marketing Manager',    'tipo'=>'internal', 'auth_type'=>'sanctum'],
        ['slug'=>'wp-plugin',   'nombre'=>'Plugin WordPress',     'tipo'=>'external', 'auth_type'=>'sanctum'],
        ['slug'=>'la-llave',    'nombre'=>'La Llave Documental',  'tipo'=>'external', 'auth_type'=>'sanctum'],
        ['slug'=>'brp',         'nombre'=>'BRP Asistencia',       'tipo'=>'external', 'auth_type'=>'sanctum'],
    ];

    public function run(): void {
        foreach (self::APPS as $data) {
            App::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
```

### 5.2 `BrpRolesSeeder`

```php
class BrpRolesSeeder extends Seeder {
    private const BRP_ROLES = [
        ['slug'=>'brp-admin',     'nombre'=>'BRP Admin',      'es_super_admin'=>false],
        ['slug'=>'brp-lider',     'nombre'=>'BRP Líder',      'es_super_admin'=>false],
        ['slug'=>'brp-psicologo', 'nombre'=>'BRP Psicólogo',  'es_super_admin'=>false],
    ];

    public function run(): void {
        foreach (self::BRP_ROLES as $data) {
            Rol::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
```

**Order:** Runs AFTER `RoleSeeder` + `add_slug_and_es_super_admin_to_roles` (because `slug` column must exist).

### 5.3 `UsuarioAppAssignmentsSeeder`

```php
class UsuarioAppAssignmentsSeeder extends Seeder {
    public function run(): void {
        // Lookup by email (resilient to id changes)
        $vos = Usuario::where('email', 'admin@tecnoinnsoft.dev')->first();
        $lorena = Usuario::where('email', 'innovacionydesarrollo.tis@gmail.com')->first();
        $patricia = Usuario::where('email', 'servicioalcliente.tis@gmail.com')->first();
        $jaime = Usuario::where('email', 'direccion.tis@gmail.com')->first();

        $superAdmin = Rol::where('slug', 'super-admin')->first();
        $comercial = Rol::where('slug', 'comercial')->first();
        $operativo = Rol::where('slug', 'operativo')->first();

        $assignments = [
            ['user' => $vos,      'apps' => ['crm','sailus','marketing','wp-plugin','la-llave','brp'], 'rol' => $superAdmin],
            ['user' => $lorena,   'apps' => ['crm'],                                         'rol' => $comercial],
            ['user' => $patricia, 'apps' => ['crm','brp'],                                   'rol' => $operativo],
            ['user' => $jaime,    'apps' => ['crm','marketing'],                             'rol' => $superAdmin],
        ];

        DB::transaction(function () use ($assignments) {
            foreach ($assignments as $a) {
                if (!$a['user'] || !$a['rol']) {
                    Log::warning('Skipping user-app assignment: missing user or role', $a);
                    continue;
                }
                foreach ($a['apps'] as $slug) {
                    $app = App::where('slug', $slug)->first();
                    if (!$app) {
                        Log::warning("Skipping assignment: app slug '{$slug}' not found", $a);
                        continue;
                    }
                    UsuarioApp::firstOrCreate(
                        ['usuario_id' => $a['user']->id, 'app_id' => $app->id],
                        ['rol_id' => $a['rol']->id],
                    );
                }
            }
        });
    }
}
```

**Key design choices:**
- Lookup by EMAIL (not by id=1,2,3,4) → resilient to seeding order changes.
- `firstOrCreate` → idempotent.
- Wrapped in `DB::transaction` for atomicity.
- Skip + log when user/role/app missing rather than fail loudly (seeds should be defensive).

### 5.4 `DatabaseSeeder` modification

```php
public function run(): void {
    $this->call([
        RoleSeeder::class,            // EXISTING (creates SuperAdmin, Comercial, etc.)
        PermisoSeeder::class,         // EXISTING
        // ... other existing seeders ...
        AppsSeeder::class,            // NEW (must come before UsuarioAppAssignments)
        BrpRolesSeeder::class,        // NEW (depends on PM-1 having run)
        UsuarioAppAssignmentsSeeder::class, // NEW (depends on Apps + BrpRoles)
    ]);
}
```

---

## 6. Test Strategy

### 6.1 Test pyramid

| Layer | What | Files |
|---|---|---|
| **Unit** | Use cases, middleware, resolvers, models | `tests/Unit/Shared/`, `tests/Unit/Auth/`, `tests/Unit/Application/` |
| **Feature** | HTTP endpoints, migrations, seeders, console commands | `tests/Feature/Auth/`, `tests/Feature/Me/`, `tests/Feature/Shared/`, `tests/Feature/Migration/`, `tests/Feature/Seeders/`, `tests/Feature/Console/` |

### 6.2 Test mapping (per spec)

| Spec file | Test file | Coverage |
|---|---|---|
| `apps/spec.md` (21 scenarios) | `tests/Feature/Shared/AppsTableTest.php` + `tests/Unit/Shared/AppModelTest.php` + `tests/Feature/Seeders/AppsSeederTest.php` | Schema, seed idempotency, Eloquent model, activo filter |
| `auth-login-modified/spec.md` (29 scenarios) | `tests/Feature/Auth/LoginResponseIncludesAppsTest.php` + `tests/Unit/Auth/LoginUseCaseIncludesAppsTest.php` | Vos/Lorena/Patricia/Jaime cases, super-admin bypass, activo filter, 401/422 paths |
| `auth-validate-token/spec.md` (30 scenarios) | `tests/Feature/Auth/ValidateTokenEndpointTest.php` + `tests/Unit/Auth/ValidateTokenUseCaseTest.php` | Cache hit/miss, 401, X-API-Key 400, app-less user 200, super-admin bypass, permisos aggregation |
| `me-endpoints/spec.md` (42 scenarios) | `tests/Feature/Me/MeEndpointsTest.php` + `tests/Unit/Auth/EnsureUserHasAppMiddlewareTest.php` + `tests/Unit/Auth/UserAppsResolverTest.php` | /me, /me/apps, /me/apps/{slug}/permisos, has-app middleware |
| `personas-party-model/spec.md` (29 scenarios) | `tests/Unit/Shared/PersonaTest.php` + `tests/Feature/Shared/ContactoPersonaRelationshipTest.php` + `tests/Feature/Console/BackfillPersonasCommandTest.php` | Schema, FK, bidirectional, backfill --dry-run, idempotency |
| `pre-migrations/spec.md` (26 scenarios) | `tests/Feature/Migration/AddSlugToRolesMigrationTest.php` + `tests/Feature/Migration/AddIdentificacionToContactoMigrationTest.php` + `tests/Feature/Migration/CreatePersonasTableTest.php` | Schema, data backfill, ordering, rollback |
| `usuario-app-assignments/spec.md` (40 scenarios) | `tests/Feature/Shared/UsuarioAppPivotTest.php` + `tests/Feature/Seeders/UsuarioAppAssignmentsSeederTest.php` + `tests/Feature/Auth/UsuarioAppAdminTest.php` | Pivot schema, canonical seed, admin CRUD, super-admin bypass |

### 6.3 Canonical test pattern

Mirror `tests/Feature/API/AuthTest.php`:
```php
namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginResponseIncludesAppsTest extends TestCase {
    use RefreshDatabase;

    #[Test]
    public function it_returns_6_apps_for_super_admin(): void {
        // Arrange
        $rol = Rol::create(['nombre' => 'SuperAdmin', 'slug' => 'super-admin', 'es_super_admin' => true, 'estado' => 'Activo']);
        $user = Usuario::create([
            'nombre' => 'Vos', 'email' => 'admin@tecnoinnsoft.dev',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id, 'estado' => 'Activo',
        ]);
        // Run AppsSeeder to populate the 6 apps
        $this->seed(AppsSeeder::class);
        // Assign 6 apps
        $apps = App::all();
        foreach ($apps as $app) {
            UsuarioApp::create(['usuario_id' => $user->id, 'app_id' => $app->id, 'rol_id' => $rol->id]);
        }

        // Act
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@tecnoinnsoft.dev',
            'password' => 'password123',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['token', 'usuario', 'apps']])
            ->assertJsonCount(6, 'data.apps');
    }
}
```

### 6.4 Test data setup helpers

Create `tests/Concerns/SeedsMultiAppContext.php` (or in `tests/TestCase.php`):
```php
protected function seedMultiAppContext(): void {
    $this->seed(AppsSeeder::class);
    $this->seed(BrpRolesSeeder::class);
}
```

This is the red-green-refactor cycle. Every spec scenario maps to one assertion minimum. The `composer test` runs against MySQL (per `phpunit.xml` line 26-28).

---

## 7. Performance Considerations

| Concern | Mitigation |
|---|---|
| `validate-token` cache hit < 10ms | `Cache::remember` with DB driver (single-row lookup + cache key prefix). Default cache driver is `database` per `AGENTS.md`. Test with `php artisan tinker` to verify. |
| `me/apps` N+1 on roles | `UsuarioApp::with(['app', 'rol'])->where('usuario_id', $id)->get()` — eager load. |
| `me/apps/{slug}/permisos` lookup | `PermisoRepository::listFor($user, $app)` — single JOIN query. |
| `usuario_app` index | Migration creates `idx_usuario_app_usuario` for fast `/me/apps` lookup. |
| `apps` catalog (6 rows) | Tiny table; no perf concern. |
| `personas` backfill (2,828 rows) | `cursor()` for memory efficiency; `DB::transaction` for atomicity. |
| `PersonalAccessToken::findToken($token)` | Uses indexed `token` column (SHA-256). Standard Sanctum implementation. |

**Load test (manual):** `php artisan tinker` → call `Cache::remember('auth:validate_token:test', 300, fn() => …)` 1000 times in a loop. Should stay < 10ms p99.

---

## 8. Rollout Plan

### 8.1 Order (strict — pre-migrations block features)

1. **Step 1 — Pre-migrations on staging DB**
   ```bash
   docker exec crm-laravel-dev php artisan migrate --path=database/migrations/2026_07_30_080000_add_slug_and_es_super_admin_to_roles_table.php
   docker exec crm-laravel-dev php artisan migrate --path=database/migrations/2026_07_30_080100_create_personas_table.php
   docker exec crm-laravel-dev php artisan migrate --path=database/migrations/2026_07_30_080400_add_identificacion_and_persona_id_to_contacto_table.php
   ```
2. **Step 2 — Verify pre-migrations**
   ```sql
   DESCRIBE roles; -- should have slug, es_super_admin
   DESCRIBE contacto; -- should have identificacion_tipo, identificacion_numero, persona_id
   SHOW TABLES LIKE 'personas'; -- should exist
   SELECT * FROM roles; -- slug should be populated, id=1 has es_super_admin=1
   ```
3. **Step 3 — Backfill personas (manual, with --dry-run first)**
   ```bash
   docker exec crm-laravel-dev php artisan crm:backfill-personas --dry-run
   # review output
   docker exec crm-laravel-dev php artisan crm:backfill-personas
   ```
4. **Step 4 — Run new schema migrations + seeders**
   ```bash
   docker exec crm-laravel-dev php artisan migrate
   docker exec crm-laravel-dev php artisan db:seed --class=AppsSeeder
   docker exec crm-laravel-dev php artisan db:seed --class=BrpRolesSeeder
   docker exec crm-laravel-dev php artisan db:seed --class=UsuarioAppAssignmentsSeeder
   ```
5. **Step 5 — Verify assignments**
   ```sql
   SELECT u.email, a.slug, r.slug AS rol
   FROM usuario_app ua
   JOIN usuarios u ON u.id = ua.usuario_id
   JOIN apps a ON a.id = ua.app_id
   JOIN roles r ON r.id = ua.rol_id;
   -- expected: 11 rows for the 4 canonical users
   ```
6. **Step 6 — Deploy feature code**
   - Push branch, merge to main, CI runs `composer test` (must pass).
   - EasyPanel deploy webhook (if configured).
7. **Step 7 — Smoke test endpoints**
   ```bash
   curl -X POST https://crm/api/v1/auth/login -H 'Content-Type: application/json' \
     -d '{"email":"admin@tecnoinnsoft.dev","password":"password123"}'
   # expect: 200 with data.apps = 6 entries
   curl -X GET https://crm/api/v1/auth/validate-token \
     -H 'Authorization: Bearer <token>'
   # expect: 200 with apps/permisos
   ```
8. **Step 8 — Coordinate with BRP team**
   - Send them the OpenAPI spec or the response examples from §4.3.
   - Stand up a staging BRP instance pointing at the new `/auth/validate-token`.
   - Integration test: BRP logs in via CRM, calls `validate-token`, gets back `apps: [{slug: 'brp'}]`.
9. **Step 9 — Production cutover**
   - Schedule 30-minute window.
   - Steps 1-5 in production with backups taken before each migration.
   - Step 6 deploy code.
   - Step 7 smoke tests.
   - Step 8 BRP-side integration.

---

## 9. Rollback Plan

### 9.1 Pre-migrations
```bash
docker exec crm-laravel-dev php artisan migrate:rollback --step=6
# This rolls back: backfill_personas (no-op), add_identificacion_to_contacto,
# create_usuario_app, create_apps, create_personas, add_slug_to_roles
```

Each migration's `down()` is documented in §3.

### 9.2 Seeder assignments
```bash
docker exec crm-laravel-dev php artisan tinker
>>> DB::table('usuario_app')->truncate();
```
Or selectively:
```bash
DELETE FROM usuario_app WHERE created_at > '2026-07-30 14:00:00';
```

### 9.3 Feature code (login modification)
- Revert `LoginUseCase.php` to the pre-change version (return only `LoginResponse(token, usuario)`).
- Revert `LoginResponse.php` to remove `apps` field.
- The login endpoint will go back to the pre-change shape; clients expecting `apps` will get a missing field (no error, but degraded UX).

### 9.4 `/auth/validate-token` endpoint
- Remove the route from `routes/api.php`.
- No downstream consumers yet (BRP has not yet integrated beyond mock).
- The endpoint is purely additive — removing it has zero impact on existing CRM flows.

### 9.5 Admin endpoints
- Remove the 3 routes from `routes/api.php`.
- No clients yet (admin operations still done via DB or seeder).
- Removing them has zero impact.

### 9.6 Failure mode checklist

| What broke | Recovery |
|---|---|
| Login returns 500 (likely `UserAppsResolver` injection failure) | Revert `LoginUseCase.php` change. |
| `/auth/validate-token` returns 500 | Remove route; revert `ValidateTokenController` + `ValidateTokenUseCase`. |
| `/auth/validate-key` (SAIlus) regressed | No code touched that endpoint. Verify with `curl -H 'X-API-Key: <key>' https://crm/api/v1/auth/validate-key`. Should still work. |
| Persona FK breaks insert in `contacto` | Migration removes FK + persona_id column. Re-run `migrate:rollback --step=1` for that migration. |
| Cache returns stale data after admin revoke | Documented R2; wait 5 min or call `Cache::flush()`. |

### 9.7 Database backup protocol

Before Step 1 in production:
```bash
docker exec crm-laravel-dev mysqldump -u root -p tecnoinnsoft_crm > backup_pre_multi_app_$(date +%Y%m%d_%H%M%S).sql
```

---

## 10. Open Questions (for implementer)

These are flagged for the `sdd-apply` phase to resolve if user wants:

1. **Splitting `Usuario.nombre` into `nombres`/`apellidos` for the login response.** The current schema has a single `nombre` column. The PRD example shows `nombres` + `apellidos`. **Recommendation:** for MVP, return `nombres: $user->nombre, apellidos: ''` (the existing `nombre` field IS the full name) and add a TODO to migrate to split columns later. The `Persona` model already has `nombres`/`apellidos` — when the user exists as a `usuario`, the `nombres` field will be empty until linked.

2. **`permisos` aggregation strategy in `validate-token`.** The spec says "union of all permissions across user's roles". The current `PermisoRepository` interface (`tests/Feature/UseCases/StoreSeguimientoUseCaseTest.php` references `PermisoRepositoryInterface`) may or may not have a method that takes a `Usuario` (vs a `rol_id`). **Recommendation:** add a new method `listPermissionsForUsuario(Usuario $user): array` to the repository, returning a uniqued array of strings. Implement with `Permiso::whereIn('rol_id', $user->apps()->pluck('pivot.rol_id'))->pluck('vista')->unique()->all()`.

3. **`Cache::tags` for invalidation.** Default DB cache driver does NOT support tags. Either (a) accept R2 (5-min stale cache) or (b) switch to Redis. **Recommendation:** accept R2 for MVP; flag for post-MVP.

4. **Throttle limits on the new endpoints.** `throttle:120,1` is suggested for `validate-token` (BRP can hit hard). Confirm with infra team. Default `throttle:api` (60/min) for `/me/*`.

5. **How to handle BRP's existing `/auth/validate-key` PR.** The BRP PRD (separately referenced in the orchestrator task) still mentions `validate-key`. Phase 5 of the rollout plan sends a coordination email to the BRP team. **Open:** has that email been sent? The implementer should confirm before code merge.

If the implementer hits any of these, default to the **recommendation** and document the choice in the PR description.

---

## 11. File Count Summary

| Category | Count |
|---|---|
| **Migrations** | 6 new |
| **Models** | 3 new + 3 deprecated wrappers + 3 modified |
| **Use cases** | 7 new + 1 modified |
| **Services** | 2 new |
| **DTOs** | 3 new + 1 modified |
| **Middleware** | 1 new + 1 modified (`bootstrap/app.php`) |
| **Controllers** | 3 new + 1 modified |
| **Form Requests** | 1 new |
| **Resources** | 3 new |
| **Seeders** | 3 new + 1 modified |
| **Console commands** | 1 new |
| **Routes** | 8 new + 1 modified |
| **Tests** | 19 new + 1 modified |
| **Total** | ~73 files touched |

---

## 12. Reference: Decision Log (no re-litigation)

| # | Decision | Source |
|---|---|---|
| DR-1 | Endpoint name `/auth/validate-token` (NOT `/auth/validate-key`) | Orchestrator pre-resolved |
| DR-2 | Cache key prefix `auth:validate_token:{hash}` | Orchestrator pre-resolved |
| DR-3 | Real user IDs: admin=5, lorena=1, patricia=4, jaime=3 | Orchestrator pre-resolved |
| DR-4 | Module placement: `Modules/Shared/` (no new module) | Proposal §3.1 |
| DR-5 | Pre-migrations MUST run before feature code | Proposal §3.4 |
| DR-6 | No `doctrine/dbal` — use raw SQL | Explore §6 high-risk #9 |
| DR-7 | `composer test` uses MySQL (NOT SQLite) | Explore §6 medium-risk #8 |
| DR-8 | Existing `LoginUseCase` pattern preserved | Confirmed in §1 architecture |
| DR-9 | `AppsSeeder`, `BrpRolesSeeder`, `UsuarioAppAssignmentsSeeder` registered in `DatabaseSeeder` | Proposal §5 #4 |
| DR-10 | `crm:backfill-personas` defaults to APPLY (no `--dry-run` needed) | Proposal §7 #1 |
| DR-11 | Cache TTL 300s accepted (R2) | Proposal DA-5 |
| DR-12 | Super-admin bypass applies to ALL `has-app:*` AND `rbac:*` checks | Proposal §7 #2 |
| DR-13 | `apellidos` is NOT NULL on `personas` (per spec) but nullable on `contacto` (per Jul 21 migration) | Reconciled in `BackfillPersonasFromContacto` via `?? ''` |

---

## 13. Implementation Order (TDD-mapped)

Per the design + strict TDD, the implementer should follow this order:

1. **PM-1** + tests: `AddSlugToRolesMigrationTest.php` (RED → migration → GREEN)
2. **PM-2** + tests: `CreatePersonasTableTest.php`
3. **PM-3** + tests: `AddIdentificacionToContactoMigrationTest.php`
4. **`apps` table** + tests: `AppsTableTest.php`
5. **`AppsSeeder`** + tests: `AppsSeederTest.php`
6. **`usuario_app` pivot** + tests: `UsuarioAppPivotTest.php`
7. **`BrpRolesSeeder`** + tests: `BrpRolesSeederTest.php`
8. **`UsuarioAppAssignmentsSeeder`** + tests: `UsuarioAppAssignmentsSeederTest.php`
9. **Models** + tests: `AppModelTest.php`, `UsuarioAppTest.php`, `PersonaTest.php`
10. **`Contactopersona` relationship** + tests: `ContactoPersonaRelationshipTest.php`
11. **`UserAppsResolver`** + tests: `UserAppsResolverTest.php`
12. **`LoginResponse` modification** + tests: `LoginUseCaseIncludesAppsTest.php`
13. **`LoginUseCase` modification** + tests: `LoginResponseIncludesAppsTest.php`
14. **`EnsureUserHasAppMiddleware`** + tests: `EnsureUserHasAppMiddlewareTest.php`
15. **`bootstrap/app.php` alias** + manual smoke test
16. **`MeController`** + use cases + tests: `MeEndpointsTest.php`
17. **`ValidateTokenUseCase`** + tests: `ValidateTokenUseCaseTest.php`
18. **`ValidateTokenController`** + route + tests: `ValidateTokenEndpointTest.php`
19. **`CrmBackfillPersonasCommand`** + tests: `BackfillPersonasCommandTest.php`
20. **`UsuarioAppController`** + use cases + tests: `UsuarioAppAdminTest.php`
21. **`DatabaseSeeder` modification** + manual `migrate:fresh --seed` smoke test
22. **Final:** `composer test` must pass end-to-end.

---

**Next step:** Ready for `sdd-tasks` (task breakdown with TODOs marked TDD phases).
