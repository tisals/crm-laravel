# Spec: Personas — Party Model

## Purpose

Define the new `personas` table that centralizes human identity across the CRM. The Party Model pattern treats a `persona` as the canonical record for a human, with `contacto` (and later `colaborador` / `proveedor`) referencing it via a nullable foreign key. This change adds the table, the FK on `contacto`, and the data-migration command to backfill from existing 2.828 rows.

The personas table is an identity primitive — it does NOT carry business logic like `rol` or `estado`. The `Usuario` model in `usuarios` still holds the authentication / app-assignment concept. Personas and Usuarios coexist: a `persona` is "who they are", a `usuario` is "how they log in".

---

## Requirements

### REQ-PERSONAS-1: Schema for the `personas` table

See REQ-PRE-3 in `pre-migrations/spec.md` for the column-by-column definition. This requirement restates the schema in the personas-domain context.

The system MUST create a `personas` table with:

| Column | Type | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT |
| `identificacion_tipo` | VARCHAR(10) | NULL |
| `identificacion_numero` | VARCHAR(20) | NULL |
| `nombres` | VARCHAR(100) | NOT NULL |
| `apellidos` | VARCHAR(100) | NOT NULL |
| `email_principal` | VARCHAR(150) | NULL |
| `telefono_principal` | VARCHAR(30) | NULL |
| `created_at` | TIMESTAMP | NULL |
| `updated_at` | TIMESTAMP | NULL |

#### Scenario: Required fields reject incomplete inserts
- GIVEN the `personas` table is empty
- WHEN `INSERT INTO personas (apellidos) VALUES ('Pérez')` is attempted (no `nombres`)
- THEN the database raises a NOT NULL violation on `nombres`
- AND no row is created

#### Scenario: All optional fields may be NULL
- GIVEN the `personas` table exists
- WHEN `INSERT INTO personas (nombres, apellidos) VALUES ('Ana', 'Pérez')` runs
- THEN the row is created with all optional columns as NULL

#### Scenario: identificacion_numero uniqueness allows NULLs
- GIVEN two personas both have `identificacion_numero = NULL`
- WHEN a third is inserted with `identificacion_numero = NULL`
- THEN the UNIQUE constraint does NOT fire (NULLs are not considered duplicates)
- AND inserting a fourth with `identificacion_numero = '12345'` succeeds
- AND inserting a fifth with `identificacion_numero = '12345'` raises UNIQUE violation

#### Scenario: Eloquent model allows standard CRUD
- GIVEN the model `Persona` exists in `Modules\Shared\Models`
- WHEN code does `$p = Persona::create(['nombres' => 'Ana', 'apellidos' => 'Pérez'])`
- THEN a row is inserted with an auto-generated id
- AND `$p->nombres === 'Ana'`

---

### REQ-PERSONAS-2: `contacto.persona_id` foreign key

The system MUST add `contacto.persona_id` as a nullable foreign key column:

| Column | Type | Constraints |
|---|---|---|
| `persona_id` | BIGINT UNSIGNED | NULL, FK → `personas(id)` ON DELETE SET NULL |

Index: `(persona_id)` for JOIN performance.

**Given** the `personas` table exists
**When** the migration `add_persona_id_to_contacto_table` runs
**Then** `contacto.persona_id` exists as nullable
**And** the FK constraint is in place
**And** no existing contacto rows are modified (all NULL)

#### Scenario: Pre-migration personas is required
- GIVEN the `personas` table does NOT exist
- WHEN the migration `add_persona_id_to_contacto_table` runs
- THEN the migration fails with a FK target error
- AND the migration is rolled back (no orphan column added)

#### Scenario: Existing contactos have NULL persona_id after migration
- GIVEN 100 contactos are seeded
- WHEN the migration runs
- THEN all 100 contactos have `persona_id = NULL`
- AND no `contacto` row is updated by the migration alone (data migration is a separate step)

#### Scenario: ON DELETE SET NULL protects contactos
- GIVEN a contacto linked to persona_id=42
- WHEN `DELETE FROM personas WHERE id = 42` runs
- THEN the contacto's `persona_id` becomes NULL
- AND the contacto data (nombres, apellidos, email_contacto) is preserved

#### Scenario: ON DELETE RESTRICT would fail (sanity check)
- GIVEN a contacto linked to persona_id=42
- WHEN `DELETE FROM personas WHERE id = 42` is attempted
- THEN the delete would fail IF ON DELETE RESTRICT
- BUT the migration uses SET NULL, so the delete succeeds and sets the FK column to NULL

#### Scenario: Existing contacto logic still works
- GIVEN the migration has run
- WHEN `Contacto::where('id', $id)->update([...])` is called without `persona_id`
- THEN the update succeeds
- AND `persona_id` is unchanged (NULL before, NULL after)

---

### REQ-PERSONAS-3: Contacto ↔ Persona bidirectional lookup

The system MUST expose Eloquent relationships on both `Contacto` and `Persona` so that consumers can navigate the FK in both directions.

**Given** a `contacto` linked to `persona_id = 42`
**When** code accesses `$contacto->persona` or `$persona->contactos`
**Then** appropriate models are returned

#### Scenario: Contacto → Persona relationship
- GIVEN a contacto with `persona_id = 42`
- WHEN `$contacto->persona` is accessed
- THEN a `Persona` instance with id=42 is returned (or null if the FK is NULL)

#### Scenario: Persona → Contactos relationship
- GIVEN a persona with id=42
- WHEN `$persona->contactos` is accessed
- THEN a Collection of `Contacto` instances is returned
- AND each contacto's `persona_id === 42`

#### Scenario: Persona with no linked contactos returns empty collection
- GIVEN a persona with id=42 and no `contacto.persona_id = 42` rows
- WHEN `$persona->contactos` is accessed
- THEN an empty `Collection` is returned (NOT null)

#### Scenario: Contacto with NULL persona_id
- GIVEN a contacto with `persona_id IS NULL`
- WHEN `$contacto->persona` is accessed
- THEN null is returned (not an exception)

---

### REQ-PERSONAS-4: Personas is created independently of contacto (pre-migration #3)

The system MUST create `personas` as a standalone table BEFORE any FK references it. Specifically:

- `personas` migration runs first (as pre-migration #3)
- Then `contacto.persona_id` migration runs (as pre-migration #4)

**Given** both migrations are pending
**When** `php artisan migrate` runs
**Then** `create_personas_table` completes successfully
**And** `add_persona_id_to_contacto_table` completes successfully afterwards

#### Scenario: No FK target → migration fails safely
- GIVEN only `add_persona_id_to_contacto_table` is queued (personas migration skipped)
- WHEN `php artisan migrate` runs
- THEN the FK-target migration fails with a clear error
- AND the migration is rolled back (no orphan column added)

#### Scenario: Both migrations run in sequence on fresh DB
- GIVEN a fresh DB
- WHEN `php artisan migrate` runs
- THEN both migrations succeed in the documented order
- AND `personas` is created first, then `contacto.persona_id` references it

---

### REQ-PERSONAS-5: Backfill command — applied via artisan, NOT automatic in migration

The system MUST provide a separate artisan command `crm:backfill-personas` that the operator invokes (NOT auto-run during `migrate`). The command:

- MUST have a `--dry-run` flag (default behavior: real mutation, unless `--dry-run`)
- MUST log counts of inserts, updates, and skipped rows
- MUST be idempotent (re-running produces no new changes)
- MUST exit cleanly if `personas` table is missing (no DB error)
- MUST support Spanish and English log messages

**Given** the operator wants to backfill from 100 contactos
**When** `php artisan crm:backfill-personas` is invoked
**Then** personas are created and contactos are linked

#### Scenario: Dry-run is the safe default (opt-in only via --dry-run)
- GIVEN 100 non-deleted contactos with email
- WHEN `php artisan crm:backfill-personas --dry-run` runs
- THEN the command prints:
  - "Would create N personas"
  - "Would update M contactos"
  - "Would skip K soft-deleted rows"
- AND zero `INSERT`s or `UPDATE`s are executed
- AND the command exits with status 0

> **Decision:** Per `proposal.md` §7 Open Question #1, the command defaults to **apply** (real mutation) unless `--dry-run` is specified. This is because the DB operator usually runs the backfill after pre-migrations are applied and wants it to actually do the work. The `--dry-run` flag is for safety review before a large production run.

#### Scenario: Apply creates personas linked to contactos
- GIVEN 100 non-deleted contactos with distinct email_contacto
- WHEN `php artisan crm:backfill-personas` runs (no flag)
- THEN 100 rows are inserted into `personas`
- AND 100 `contacto.persona_id` updates are performed
- AND the command prints counts and exits 0

#### Scenario: Idempotent second run
- GIVEN the first run created 100 personas and linked 100 contactos
- WHEN `php artisan crm:backfill-personas` runs again
- THEN 0 inserts, 0 updates (matching emails already exist)
- AND the command prints "Already up to date" or equivalent

#### Scenario: Contacts with same email are deduped
- GIVEN 3 contactos (different `entidad_id`) share `email_contacto = 'a@x.com'`
- WHEN backfill runs
- THEN only 1 persona is created with `email_principal = 'a@x.com'`
- AND all 3 contactos have `persona_id` set to that one persona's id

#### Scenario: Soft-deleted contactos are skipped with no error
- GIVEN a contacto with `deleted_at IS NOT NULL`
- WHEN backfill runs
- THEN that contacto is NOT processed
- AND the skip is counted in the log output

#### Scenario: Contacts with NULL email_contacto are matched by nombres+apellidos
- GIVEN a contacto has `email_contacto IS NULL` but `nombres='Ana'`, `apellidos='Pérez'`
- WHEN backfill runs
- THEN a persona is created (or matched by name) with `nombres='Ana', apellidos='Pérez'`
- AND the contacto is linked to that persona

#### Scenario: Command aborts gracefully if personas table missing
- GIVEN `personas` table does not exist
- WHEN `php artisan crm:backfill-personas` runs
- THEN the command prints a clear error message ("Run `php artisan migrate` first")
- AND exits with non-zero status
- AND no DB writes are attempted

#### Scenario: Command is part of `migrate:fresh --seed` flow (optional)
- GIVEN `DatabaseSeeder` calls `crm:backfill-personas` after seed
- WHEN `php artisan migrate:fresh --seed` runs on a DB with contactos
- THEN personas are created automatically as part of the workflow
- AND the command runs AFTER `ContactoTableSeeder` (so data exists)

> This scenario documents a recommended wiring; whether to add it to DatabaseSeeder is a deployment decision.

---

### REQ-PERSONAS-6: Personas and Usuarios are independent entities

The system MUST NOT add any FK or relationship between `personas` and `usuarios`. The two tables coexist:

- A `persona` represents "who the human is" (identity)
- A `usuario` represents "how the human authenticates" (auth)
- The same human MAY have BOTH a `persona` row and a `usuario` row
- A `usuario` may exist without a `persona` (e.g., service accounts, internal bots)

**Given** the two tables exist
**When** code queries `personas` or `usuarios`
**Then** no implicit JOIN happens — they are queried independently

#### Scenario: A service account has no persona
- GIVEN a `usuario` exists for `crm@tecnoinnsoft.dev` (the FastAPI service account)
- AND no `persona` row is associated
- WHEN code does `Usuario::where('email', 'crm@tecnoinnsoft.dev')->first()`
- THEN the usuario is returned
- AND `usuario->persona` returns null
- AND no constraint violation is raised

#### Scenario: A usuario and persona for the same human are independent rows
- GIVEN a `usuario` for `lorena@tecnoinnsoft.dev` and a `persona` for the same human
- WHEN code does `Usuario::find(lorena_id)->persona` (if such a relationship is added later)
- THEN the lookup is independent (no FK between them)
- AND the relationship is OPTIONAL — both rows can be updated, deleted, or replaced independently

#### Scenario: proveedor and colaborador are NOT touched in this change
- GIVEN `proveedores` and `colaboradores` tables exist with their own data
- WHEN this change is applied
- THEN no FK or column is added to those tables
- AND they remain independent of `personas` (per proposal "Out of Scope")

---

### REQ-PERSONAS-7: Data integrity in the backfill

The system MUST ensure the backfill produces a 1:N relationship between `personas` and `contactos` (one persona can be linked to many contactos from different `entidad_id`s).

**Given** the same email_contacto appears on N contactos
**When** backfill runs
**Then** exactly 1 persona is created
**And** all N contactos have `persona_id` set to that single persona

#### Scenario: One persona, multiple contactos (same email)
- GIVEN 5 contactos share `email_contacto = 'shared@example.com'`
- WHEN backfill runs
- THEN `personas` has 1 row with `email_principal = 'shared@example.com'`
- AND all 5 contactos have `persona_id` set to that persona's id

#### Scenario: One persona, one contacto (unique email)
- GIVEN 1 contacto has a unique email
- WHEN backfill runs
- THEN `personas` has 1 row
- AND the contacto's `persona_id` is set

#### Scenario: Personas count equals distinct emails after dedup
- GIVEN 100 contactos with 80 unique emails
- WHEN backfill runs
- THEN `personas` has 80 rows
- AND 100 contactos have `persona_id` set
