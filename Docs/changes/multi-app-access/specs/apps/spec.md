# Spec: Apps Catalog (`apps` table)

## Purpose

Define a new `apps` table that catalogs the 6 applications in the Tecnoinnsoft ecosystem (CRM, SAIlus, Marketing, Plugin WP, La Llave, BRP). The catalog is the single source of truth for app identity — `usuario_app` references it, the login response lists its records, and external apps compare their slug against it. Until this catalog exists, multi-app access cannot be modeled.

---

## Requirements

### REQ-APPS-1: Schema for the `apps` table

The system MUST create a new `apps` table with the following schema:

| Column | Type | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT |
| `slug` | VARCHAR(50) | NOT NULL, UNIQUE |
| `nombre` | VARCHAR(100) | NOT NULL |
| `tipo` | ENUM('internal','external','customer') | NOT NULL DEFAULT 'internal' |
| `auth_type` | ENUM('sanctum','api_key') | NOT NULL DEFAULT 'sanctum' |
| `activo` | BOOLEAN | NOT NULL DEFAULT TRUE |
| `descripcion` | TEXT | NULL |
| `created_at` | TIMESTAMP | NULL |
| `updated_at` | TIMESTAMP | NULL |

**Given** the migration runs on an empty (or any) database
**When** `php artisan migrate` succeeds
**Then** the `apps` table exists with the schema above
**And** has a UNIQUE index on `slug`
**And** new rows can be inserted via Eloquent with `App::create([...])`

#### Scenario: Migration creates the table on a fresh DB
- GIVEN an empty database
- WHEN `create_apps_table` migration runs
- THEN `apps` table exists
- AND it has a BIGINT UNSIGNED PK, a VARCHAR slug, an ENUM tipo, an ENUM auth_type, a BOOLEAN activo, and timestamps

#### Scenario: Apps table is empty after migration
- GIVEN migration has just run
- WHEN `SELECT COUNT(*) FROM apps` is executed
- THEN the count is 0 (no seed data yet)

#### Scenario: Slug uniqueness is enforced at DB level
- GIVEN the `apps` table is empty
- WHEN `INSERT INTO apps (slug, nombre) VALUES ('crm', 'CRM A')` succeeds
- AND `INSERT INTO apps (slug, nombre) VALUES ('crm', 'CRM B')` is attempted
- THEN the database raises a UNIQUE constraint violation
- AND no second row is created

#### Scenario: tipo defaults to 'internal'
- GIVEN the `apps` table exists
- WHEN `INSERT INTO apps (slug, nombre) VALUES ('myapp', 'My App')` runs (no tipo supplied)
- THEN the new row has `tipo = 'internal'` (default applied)

#### Scenario: auth_type defaults to 'sanctum'
- GIVEN the `apps` table exists
- WHEN `INSERT INTO apps (slug, nombre) VALUES ('myapp', 'My App')` runs (no auth_type supplied)
- THEN the new row has `auth_type = 'sanctum'`

#### Scenario: activo defaults to TRUE
- GIVEN the `apps` table exists
- WHEN `INSERT INTO apps (slug, nombre) VALUES ('myapp', 'My App')` runs
- THEN the new row has `activo = TRUE` (default applied)

---

### REQ-APPS-2: Seed inserts the 6 canonical apps

The system MUST provide an `AppsSeeder` that inserts (idempotently) the 6 apps listed in the PRD:

| slug | nombre | tipo | auth_type |
|---|---|---|---|
| `crm` | CRM Tecnoinnsoft | internal | sanctum |
| `sailus` | SAIlus Gateway | internal | sanctum |
| `marketing` | Marketing Manager | internal | sanctum |
| `wp-plugin` | Plugin WordPress | external | sanctum |
| `la-llave` | La Llave Documental | external | sanctum |
| `brp` | BRP Asistencia | external | sanctum |

The seeder MUST be idempotent (running it twice does not duplicate rows).

**Given** the `apps` table exists
**When** `php artisan db:seed --class=AppsSeeder` runs (or invoked via `DatabaseSeeder`)
**Then** exactly 7 rows exist in `apps` after the seed
**And** each row's `slug`, `nombre`, `tipo`, `auth_type` match the table above
**And** running the seeder a second time results in 7 rows (no duplicates)

#### Scenario: First seed inserts 7 rows
- GIVEN an empty `apps` table
- WHEN `db:seed --class=AppsSeeder` runs
- THEN `SELECT COUNT(*) FROM apps` returns 7
- AND a row with `slug = 'crm'` exists
- AND a row with `slug = 'sailus'` exists
- AND a row with `slug = 'mercurio'` exists (rename de SAIlus, dual-write hasta 2027-02-06)
- AND a row with `slug = 'marketing'` exists
- AND a row with `slug = 'wp-plugin'` exists
- AND a row with `slug = 'la-llave'` exists
- AND a row with `slug = 'brp'` exists

#### Scenario: Second seed is idempotent
- GIVEN the seed has already inserted 7 rows once
- WHEN `db:seed --class=AppsSeeder` runs again
- THEN `SELECT COUNT(*) FROM apps` is still 7
- AND no UNIQUE-constraint error on slug is raised
- AND the existing rows are unchanged (same id, nombre, tipo, auth_type)

#### Scenario: Seed correctly sets tipo per app
- GIVEN the seed runs
- WHEN querying `SELECT slug, tipo FROM apps`
- THEN `crm`, `sailus`, `marketing` all have `tipo = 'internal'`
- AND `wp-plugin`, `la-llave`, `brp` all have `tipo = 'external'`
- AND no app has `tipo = 'customer'` (this value exists for future use)

#### Scenario: Seed correctly sets auth_type per app
- GIVEN the seed runs
- WHEN querying `SELECT slug, auth_type FROM apps`
- THEN all 6 rows have `auth_type = 'sanctum'`
- AND no app has `auth_type = 'api_key'` (reserved for SAIlus bots in a future change)

#### Scenario: Seed is also idempotent when called from DatabaseSeeder
- GIVEN the default `DatabaseSeeder` is configured to call `$this->call(AppsSeeder::class)`
- WHEN `php artisan migrate:fresh --seed` runs from scratch
- THEN `AppsSeeder` produces exactly 6 rows
- AND does not raise conflicts with other seeders that may also touch `apps`

---

### REQ-APPS-3: An Eloquent `App` model exists

The system MUST expose an `App` model in `Modules\Shared\Models\App` that maps to the `apps` table, with relationships to `UsuarioApp` pivot rows and standard Eloquent conventions.

**Given** the `apps` table exists and AppsSeeder has run
**When** PHP code does `App::where('slug', 'brp')->first()` or `App::find($id)`
**Then** a model instance is returned with the table columns accessible
**And** `$app->usuarioApps` returns the related pivot rows
**And** `$app->usuarios` returns the related users through the pivot

#### Scenario: Lookup by slug
- GIVEN the 6 seeded apps
- WHEN `App::where('slug', 'brp')->first()` runs
- THEN a non-null `App` instance is returned
- AND `app->nombre === 'BRP Asistencia'`
- AND `app->tipo === 'external'`

#### Scenario: Lookup by ID
- GIVEN the 6 seeded apps
- WHEN `App::find($id_for_brp)` runs (where id_for_brp is the auto-generated id of the `brp` row)
- THEN a non-null `App` instance is returned with all 6 columns accessible

#### Scenario: Lookup of nonexistent slug returns null
- GIVEN `apps` does not contain a row with `slug = 'unknown'`
- WHEN `App::where('slug', 'unknown')->first()` runs
- THEN the result is null
- AND no exception is raised

#### Scenario: usuarioApps relationship
- GIVEN a user has an assignment in `usuario_app` for the `brp` app
- WHEN `$app->usuarioApps` is accessed
- THEN a Collection of `UsuarioApp` pivot models is returned
- AND each pivot's `app_id === $app->id`

#### Scenario: usuarios relationship
- GIVEN the same data as above
- WHEN `$app->usuarios` is accessed
- THEN a Collection of distinct `Usuario` models is returned
- AND pivot data (rol_id) is accessible via `$usuario->pivot->rol_id`

---

### REQ-APPS-4: Filter inactive apps out of user-facing endpoints

The system MUST exclude apps with `activo = FALSE` from any endpoint that lists apps for a user (`/me/apps`, login response, `validate-token`). The DB still holds the row but it is not returned.

**Given** an app with `activo = FALSE`
**When** a user's apps are listed by `GetMyAppsUseCase` (or equivalent)
**Then** that app is NOT included in the returned list
**And** the app is still queryable via admin endpoints like `GET /usuarios/{id}/apps` only if the caller is super-admin (per RBAC)

#### Scenario: Inactive app not returned in /me/apps
- GIVEN the `brp` app has `activo = FALSE`
- AND a user (Patricia) has a `usuario_app` row linking her to `brp`
- WHEN `GET /api/v1/me/apps` is called with Patricia's token
- THEN the response's `data.apps` list does NOT include `{slug: 'brp', ...}`
- AND only Patricia's other active-app assignments are returned

#### Scenario: Inactive app not returned in login response
- GIVEN the `brp` app has `activo = FALSE`
- AND a user (Patricia) has a `usuario_app` row linking her to `brp`
- WHEN `POST /api/v1/auth/login` is called with Patricia's credentials
- THEN `data.apps[]` does NOT include `{slug: 'brp', ...}`

#### Scenario: Inactive app not returned in validate-token
- GIVEN the `brp` app has `activo = FALSE`
- AND Patricia has a `usuario_app` row linking her to `brp`
- WHEN `GET /api/v1/auth/validate-token` is called with Patricia's Bearer token
- THEN `data.apps[]` does NOT include the `brp` entry

#### Scenario: Re-activating an app restores visibility
- GIVEN the `brp` app was `activo = FALSE` and hidden
- WHEN an admin calls any workflow that sets `apps.activo = TRUE` for that row
- THEN subsequent `/me/apps` calls for Patricia include `brp` in the list

#### Scenario: Admin endpoint may see inactive apps (optional)
- GIVEN an admin endpoint that lists ALL apps regardless of `activo`
- WHEN the admin calls it
- THEN even `activo = FALSE` apps appear in the result
- AND each entry has an `activo` boolean field to distinguish (out of scope per MVP; this scenario documents a future flag)
