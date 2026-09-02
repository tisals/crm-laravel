# Spec: Pre-migrations — Schema Fixes

## Purpose

Three schema corrections that MUST run BEFORE any feature code in `multi-app-access`. They unlock (a) the `roles` columns that BRP roles seeder depends on, (b) the `contacto` identification columns that the personas backfill references, and (c) the initial population of `personas` from existing `contacto` rows. None of these break existing behavior — all new columns are NULLABLE or have safe defaults, and backfill migrations must be idempotent.

These migrations are the only ones in this change that:
- ALTER existing tables (`roles`, `contacto`)
- Run data migrations inside `up()` or as separate data migrations
- Must succeed on BOTH a fresh DB and a DB with 2.828 contactos seeded

---

## Requirements

### REQ-PRE-1: Roles gains `slug` and `es_super_admin` columns

The system MUST add two columns to the existing `roles` table:

| Column | Type | Constraints | Default |
|---|---|---|---|
| `slug` | VARCHAR(50) | NULL, UNIQUE | NULL |
| `es_super_admin` | BOOLEAN | NOT NULL | FALSE |

**Given** an existing `roles` table with rows (1=SuperAdmin, 2=Comercial, 3=Operaciones, 4=Finanzas)
**When** the migration runs `up()`
**Then** both columns exist with the types above
**And** a UNIQUE index exists on `roles.slug`
**And** the existing 4 rows are backfilled — `slug` is set via `LOWER(REPLACE(nombre, ' ', '-'))` and the SuperAdmin row (id=1) has `es_super_admin = TRUE`

#### Scenario: Migration runs on fresh DB
- GIVEN an empty `roles` table on a fresh install
- WHEN the `add_slug_and_super_admin_to_roles_table` migration runs
- THEN both columns are added with the schema above
- AND zero rows exist so no backfill data is changed

#### Scenario: Migration runs on seeded DB
- GIVEN the existing 4 roles (1=SuperAdmin, 2=Comercial, 3=Operaciones, 4=Finanzas)
- WHEN the migration runs `up()`
- THEN all 4 rows have a non-null `slug` equal to the kebab-case of `nombre`
- AND `roles.es_super_admin` is TRUE for `id = 1` and FALSE for ids 2, 3, 4
- AND no row has a NULL slug after migration

#### Scenario: Backfill slug is deterministic
- GIVEN a role with `nombre = "BRP Psicólogo"`
- WHEN the migration runs `up()`
- THEN that row's `slug` is `"brp-psicólogo"` (matches `LOWER(REPLACE(nombre, ' ', '-'))`)
- AND the row is queryable via `WHERE slug = ?`

#### Scenario: Slug uniqueness enforced
- GIVEN two rows in `roles` that would produce the same kebab-case slug
- WHEN a `INSERT INTO roles (slug, nombre) VALUES (...duplicate..., ...)` is attempted
- THEN the database raises a UNIQUE constraint violation
- AND the insertion is rejected

#### Scenario: es_super_admin defaults to FALSE on new rows
- GIVEN a freshly migrated `roles` table
- WHEN a new role is inserted with `INSERT INTO roles (nombre, estado) VALUES ('Auditor', 'Activo')`
- THEN the row's `es_super_admin` is FALSE (NOT NULL default applied)
- AND the row's `slug` is NULL until explicitly set

#### Scenario: Existing functionality is not broken
- GIVEN the 4 existing roles have rows post-migration with `slug`/`es_super_admin` populated
- WHEN the application queries `SELECT * FROM roles WHERE id = ?`
- THEN the response shape is unchanged (id, nombre, estado, timestamps, deleted_at)
- AND legacy code that does `SELECT nombre, estado FROM roles` continues to work

#### Scenario: Migration is reversible
- GIVEN the migration has run successfully on a DB
- WHEN `php artisan migrate:rollback` is invoked
- THEN the `slug` and `es_super_admin` columns are dropped
- AND no data in unrelated columns is lost

---

### REQ-PRE-2: Contacto gains identification columns and FK to personas

The system MUST add the following to the existing `contacto` table:

| Column | Type | Constraints |
|---|---|---|
| `identificacion_tipo` | VARCHAR(10) | NULL |
| `identificacion_numero` | VARCHAR(20) | NULL |
| `persona_id` | BIGINT UNSIGNED | NULL, FK → `personas(id)` ON DELETE SET NULL |

A `personas` table MUST be created first (separate migration), then this ALTER runs.

**Given** the `personas` table exists with at least the `id` column
**When** the migration runs `up()`
**Then** `contacto.identificacion_tipo`, `contacto.identificacion_numero` are added as NULL columns
**And** `contacto.persona_id` is added with the FK constraint and an index
**And** existing rows are NOT modified (all three new columns are NULL after migration)

#### Scenario: Migration runs on a fresh DB
- GIVEN an empty `contacto` table
- WHEN the `add_identificacion_and_persona_id_to_contacto_table` migration runs
- THEN the three new columns exist
- AND the FK `fk_contacto_persona` references `personas(id)` with `ON DELETE SET NULL`
- AND an index `idx_contacto_persona` exists on `persona_id`

#### Scenario: Migration runs on a DB with 2.828 existing contactos
- GIVEN 2.828 rows in `contacto` (representative of production data volume)
- WHEN the migration runs `up()`
- THEN all existing rows have `persona_id = NULL` after migration
- AND no row has a non-null `identificacion_tipo` or `identificacion_numero` (NULLABLE inserts nothing)
- AND no FK violation is raised (because personas is being populated independently)

#### Scenario: ON DELETE SET NULL protects historical contacts
- GIVEN a `contacto` row linked to a `personas` row via `persona_id = 42`
- WHEN the linked `personas` row is deleted
- THEN the `contacto` row's `persona_id` becomes NULL (NOT deleted)
- AND the contact data (nombres, apellidos, email_contacto) is preserved

#### Scenario: Existing contacto functionality is not broken
- GIVEN `contacto` has the 3 new NULL columns
- WHEN an existing `Contacto::find($id)` Eloquent query runs
- THEN the model returns with the new fields as NULL attributes
- AND existing `Contacto::create([...])` calls without the new fields still succeed

#### Scenario: identificacion_numero is NOT unique on contacto
- GIVEN multiple contactos from different entities may share a doc number (data quality issue known in legacy)
- WHEN these contacts are inserted
- THEN the migration does NOT create a UNIQUE index on `identificacion_numero`
- AND the FK + index migration is idempotent if run twice (no duplicate column error)

---

### REQ-PRE-3: Personas table is created

The system MUST create a new `personas` table with the following columns:

| Column | Type | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT |
| `identificacion_tipo` | VARCHAR(10) | NULL |
| `identificacion_numero` | VARCHAR(20) | NULL, UNIQUE (partial: only when not NULL) |
| `nombres` | VARCHAR(100) | NOT NULL |
| `apellidos` | VARCHAR(100) | NOT NULL |
| `email_principal` | VARCHAR(150) | NULL |
| `telefono_principal` | VARCHAR(30) | NULL |
| `created_at` | TIMESTAMP | NULL |
| `updated_at` | TIMESTAMP | NULL |

Indexes: `(identificacion_numero)` for backfill lookups.

**Given** the migration is to run BEFORE `persona_id` is added to `contacto`
**When** the migration runs `up()`
**Then** the `personas` table exists with the schema above
**And** it accepts INSERTs with the documented column set
**And** a UNIQUE constraint exists only for non-NULL `identificacion_numero`

#### Scenario: Personas table accepts creation with required fields
- GIVEN the `personas` table is empty
- WHEN `INSERT INTO personas (nombres, apellidos) VALUES ('Ana', 'Pérez')` runs
- THEN a new row is created with id auto-generated
- AND `email_principal`, `telefono_principal`, `identificacion_*` are NULL

#### Scenario: Trying to insert without nombres fails
- GIVEN the `personas` table exists
- WHEN `INSERT INTO personas (apellidos) VALUES ('Pérez')` is attempted (missing required `nombres`)
- THEN the database raises a NOT NULL violation
- AND no row is created

#### Scenario: identificacion_numero uniqueness is partial
- GIVEN two `personas` rows with `identificacion_numero = NULL`
- WHEN a third row with `identificacion_numero = NULL` is inserted
- THEN no UNIQUE constraint violation is raised (NULLs are not considered duplicates)
- AND inserting another row with `identificacion_numero = '12345'` succeeds
- AND inserting yet another row with `identificacion_numero = '12345'` raises UNIQUE violation

#### Scenario: Migration creates index on identificacion_numero
- GIVEN the migration has run
- WHEN `SHOW INDEX FROM personas WHERE Column_name = 'identificacion_numero'` is executed
- THEN at least one index exists on that column

---

### REQ-PRE-4: Personas backfill from contacto (data migration + artisan command)

The system MUST provide an artisan command `crm:backfill-personas` that inserts one `personas` row per non-deleted `contacto` row and links them via `contacto.persona_id`.

The command:
- MUST be idempotent (running twice produces the same final state)
- MUST support `--dry-run` that prints counts but does not mutate
- MUST default to `--apply` (real mutation) when `--dry-run` is not passed
- MUST match contactos → personas by `email_contacto` (because `identificacion_*` columns are NULL in legacy data)
- MUST log how many `INSERT`s and `UPDATE`s were performed

**Given** the `personas` migration, `contacto.persona_id` migration, AND the seed data for `personas` + `contacto` are all applied
**When** `php artisan crm:backfill-personas` is invoked
**Then** the count of personas created matches the count of unique non-deleted contactos (assuming no duplicates by email)
**And** every non-deleted `contacto` row has `persona_id` populated (no NULLs left after backfill completes)
**And** a subsequent `crm:backfill-personas` invocation is a no-op (idempotent)

#### Scenario: Dry-run does not mutate
- GIVEN a DB with 100 non-deleted contactos and 0 personas
- WHEN `php artisan crm:backfill-personas --dry-run` is invoked
- THEN the command prints the count of personas that would be created (100)
- AND prints the count of contactos that would be updated (100)
- AND after the command, `SELECT COUNT(*) FROM personas` returns 0
- AND after the command, every `contacto.persona_id` is still NULL

#### Scenario: Apply creates personas
- GIVEN a DB with 100 non-deleted contactos, all with non-null `email_contacto`
- WHEN `php artisan crm:backfill-personas` is invoked (no `--dry-run`)
- THEN 100 rows are inserted into `personas` (one per email)
- AND each new persona's `nombres`/`apellidos` come from the matched contacto
- AND each new persona's `email_principal` equals the matched contacto's `email_contacto`
- AND each contacto's `persona_id` is set to the newly-inserted persona's id

#### Scenario: Idempotency
- GIVEN a previous successful `crm:backfill-personas` run on 100 contactos
- WHEN `php artisan crm:backfill-personas` is invoked again
- THEN zero new personas are inserted (because matching by `email_principal` finds existing rows)
- AND every contacto's `persona_id` is unchanged from before

#### Scenario: Contactos with null email are matched by nombres + apellidos
- GIVEN a contacto with `email_contacto IS NULL` but with `nombres='Ana'` and `apellidos='Pérez'`
- WHEN backfill runs
- THEN a persona is created (or matched) with that combination
- AND the contacto is linked to that persona

#### Scenario: Contactos with same email across multiple entities
- GIVEN 3 contactos (from 3 different `entidad_id`s) sharing `email_contacto = 'shared@x.com'`
- WHEN backfill runs
- THEN exactly ONE persona is created for that email
- AND all 3 contactos are linked to the same persona via `persona_id`
- AND the email-collision is logged (counted in stats)

#### Scenario: Soft-deleted contactos are not migrated
- GIVEN a contacto with `deleted_at IS NOT NULL`
- WHEN backfill runs
- THEN that contacto is NOT processed (no persona created/linked for it)
- AND no error is raised (silently skipped)
- AND the command reports the count of skipped soft-deleted rows in the log

#### Scenario: Command requires pre-migrations
- GIVEN a DB where `personas` table does NOT exist
- WHEN `php artisan crm:backfill-personas` is invoked
- THEN the command exits with a clear error message ("run migrations first")
- AND no inserts are attempted

---

### REQ-PRE-5: Migration ordering and idempotency

The system MUST run the 3 pre-migrations in a strict order so that later migrations never fail because earlier ones did not run.

**Given** the migration filenames use timestamps for ordering
**When** `php artisan migrate` runs on a fresh DB
**Then** the order is: `create_roles_table` (existing) → `add_slug_and_super_admin_to_roles` → `create_personas_table` → `add_identificacion_and_persona_id_to_contacto` → (later) `backfill_personas_data_migration` (can run inside the contacto migration's `up()`)

#### Scenario: Fresh migration order works
- GIVEN a fresh, empty database
- WHEN `php artisan migrate` is invoked
- THEN pre-migration #1 (`add_slug_and_super_admin_to_roles`) succeeds
- AND pre-migration #2 (`create_personas`) succeeds (no FK target dependency)
- AND pre-migration #3 (`add_identificacion_and_persona_id_to_contacto`) succeeds (FK target exists)
- AND no migration fails due to missing dependencies

#### Scenario: Re-running migrations is safe (idempotent)
- GIVEN `php artisan migrate` has run successfully
- WHEN `php artisan migrate` is invoked a second time on the same DB
- THEN the system reports "Nothing to migrate"
- AND no errors are raised about duplicate columns or indexes

#### Scenario: Rollback in reverse order
- GIVEN all 3 pre-migrations have run
- WHEN `php artisan migrate:rollback --step=3` is invoked
- THEN migrations roll back in reverse order (contacto → personas → roles)
- AND the DB returns to the pre-change state with no orphan references
