<?php

namespace Tests\Feature\Migration;

use App\Models\Entidad;
use App\Models\Persona;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Administrativo\Models\Colaborador;
use Modules\Administrativo\Models\Proveedor;
use Modules\CRM\Models\Contacto;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-E: persona-role relations (REQ-PRRL-001..006).
 *
 * Three migrations add a nullable `persona_id` FK column to each role table:
 *  - `contacto.persona_id`      FK + index  (NOT UNIQUE — a persona can be
 *                                          multiple contactos across entities)
 *  - `colaboradores.persona_id` FK + UNIQUE (RQ-1 — 1 persona per colaborador)
 *  - `proveedores.persona_id`   FK + index  (NOT UNIQUE — different vendor
 *                                          roles can share a persona)
 *
 * All three FKs use `nullOnDelete`: deleting a persona sets the role row's
 * `persona_id = NULL` instead of cascading. This mirrors the existing
 * `entidad_id` precedent on `contacto` (REQ-PRRL-006).
 *
 * Strict TDD: tests 3.1–3.7 are RED before the migrations ship. GREEN
 * tasks 3.8–3.10 (three migrations) + 3.11 (Eloquent + Domain entities)
 * make them pass.
 */
class PersonaRoleRelationsTest extends TestCase
{
    use RefreshDatabase;

    // ── 3.1 — contacto.persona_id exists, nullable, indexed, NOT unique ──

    #[Test]
    public function contacto_persona_id_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(
            Schema::hasColumn('contacto', 'persona_id'),
            'contacto.persona_id column must exist (REQ-PRRL-001)'
        );

        // Inserting a row with persona_id omitted must succeed (nullable).
        $entidad = $this->createEntidad();
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Ada',
            'apellidos' => 'Lovelace',
            'estado' => 'Activo',
            'score' => 0,
        ]);

        $row = DB::table('contacto')->where('nombres', 'Ada')->first();
        $this->assertNotNull($row, 'contacto row must persist with persona_id omitted');
        $this->assertNull(
            $row->persona_id,
            'contacto.persona_id must default to NULL when omitted (nullable)'
        );
    }

    #[Test]
    public function contacto_persona_id_is_indexed_but_not_unique(): void
    {
        $indexes = Schema::getIndexes('contacto');

        $personaIdIndex = array_filter(
            $indexes,
            fn ($i) => in_array('persona_id', $i['columns'], true)
        );

        $this->assertNotEmpty(
            $personaIdIndex,
            'contacto.persona_id must have an index (REQ-PRRL-001)'
        );

        // No UNIQUE — a persona can be multiple contactos across entities.
        $unique = array_filter(
            $personaIdIndex,
            fn ($i) => $i['unique'] === true
        );
        $this->assertEmpty(
            $unique,
            'contacto.persona_id must NOT have a UNIQUE index (a persona is many contactos)'
        );
    }

    // ── 3.2 — migration applies on table with existing contacto rows ───

    #[Test]
    public function migration_preserves_existing_contacto_rows_with_null_persona_id(): void
    {
        $entidad = $this->createEntidad();

        // Simulate "data exists BEFORE the migration runs" by rolling back
        // my 3 PR-E migrations (which drops the columns + removes the
        // migration rows from the `migrations` table). Then insert rows
        // under the pre-migration schema. Then re-run migrate to add the
        // columns back. The migrations table state is fully restored at
        // the end (rollback removed rows → migrate re-adds them), so
        // subsequent tests in this class see a consistent state.
        $this->artisan('migrate:rollback', ['--step' => 3])->assertExitCode(0);

        $now = now();
        DB::table('contacto')->insert([
            [
                'entidad_id' => $entidad->id,
                'nombres' => 'Existing One',
                'apellidos' => 'A',
                'estado' => 'Activo',
                'score' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'entidad_id' => $entidad->id,
                'nombres' => 'Existing Two',
                'apellidos' => 'B',
                'estado' => 'Activo',
                'score' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'entidad_id' => $entidad->id,
                'nombres' => 'Existing Three',
                'apellidos' => 'C',
                'estado' => 'Activo',
                'score' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Sanity: rows exist before migration re-run.
        $this->assertSame(3, DB::table('contacto')->count());

        // Re-apply all pending migrations (re-adds columns + re-registers
        // the 3 migration rows).
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        // All 3 rows survive.
        $this->assertSame(
            3,
            DB::table('contacto')->count(),
            'pre-existing contacto rows must survive the additive migration'
        );

        // All 3 rows have persona_id = NULL.
        $rowsWithPersona = DB::table('contacto')
            ->whereNotNull('persona_id')
            ->count();
        $this->assertSame(
            0,
            $rowsWithPersona,
            'pre-existing contacto rows must have persona_id = NULL after migration'
        );

        // Original data preserved (nombres, apellidos, entidad_id intact).
        $names = DB::table('contacto')->orderBy('id')->pluck('nombres')->all();
        $this->assertSame(
            ['Existing One', 'Existing Two', 'Existing Three'],
            $names,
            'pre-existing contacto rows must keep their original field values'
        );
    }

    // ── 3.3 — colaboradores.persona_id exists and is UNIQUE ────────────

    #[Test]
    public function colaboradores_persona_id_column_exists_and_is_unique(): void
    {
        $this->assertTrue(
            Schema::hasColumn('colaboradores', 'persona_id'),
            'colaboradores.persona_id column must exist (REQ-PRRL-002)'
        );

        $indexes = Schema::getIndexes('colaboradores');

        $uniquePersona = array_filter(
            $indexes,
            fn ($i) => $i['unique'] === true
                && in_array('persona_id', $i['columns'], true)
        );

        $this->assertNotEmpty(
            $uniquePersona,
            'colaboradores.persona_id must have a UNIQUE index (RQ-1: 1 persona per colaborador)'
        );
    }

    #[Test]
    public function two_colaboradores_with_same_persona_id_throws_unique_violation(): void
    {
        $persona = $this->createPersona();
        $entidad = $this->createEntidad();

        // First colaborador: succeeds.
        Colaborador::create([
            'identificacion' => 'COL-'.uniqid(),
            'nombres' => 'First',
            'apellidos' => 'Colab',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
        ]);

        // Second colaborador with the SAME persona_id: must throw.
        $this->expectException(QueryException::class);
        Colaborador::create([
            'identificacion' => 'COL-'.uniqid(),
            'nombres' => 'Second',
            'apellidos' => 'Colab',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
        ]);
    }

    // ── 3.4 — proveedores.persona_id exists, nullable, indexed, NOT unique

    #[Test]
    public function proveedores_persona_id_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(
            Schema::hasColumn('proveedores', 'persona_id'),
            'proveedores.persona_id column must exist (REQ-PRRL-003)'
        );

        // Insert without persona_id must succeed.
        Proveedor::create([
            'identificacion' => 'PROV-'.uniqid(),
            'nombres' => 'Vendor',
            'apellidos' => 'X',
            'estado' => 'Activo',
        ]);

        $row = DB::table('proveedores')->where('nombres', 'Vendor')->first();
        $this->assertNull(
            $row->persona_id,
            'proveedores.persona_id must default to NULL when omitted (nullable)'
        );
    }

    #[Test]
    public function proveedores_persona_id_is_indexed_but_not_unique(): void
    {
        $indexes = Schema::getIndexes('proveedores');

        $personaIdIndex = array_filter(
            $indexes,
            fn ($i) => in_array('persona_id', $i['columns'], true)
        );

        $this->assertNotEmpty(
            $personaIdIndex,
            'proveedores.persona_id must have an index (REQ-PRRL-003)'
        );

        $unique = array_filter(
            $personaIdIndex,
            fn ($i) => $i['unique'] === true
        );
        $this->assertEmpty(
            $unique,
            'proveedores.persona_id must NOT have a UNIQUE index (a persona is many vendors)'
        );
    }

    #[Test]
    public function two_proveedores_with_same_persona_id_succeed_no_unique_constraint(): void
    {
        $persona = $this->createPersona();

        Proveedor::create([
            'identificacion' => 'PROV-A-'.uniqid(),
            'nombres' => 'Vendor A',
            'apellidos' => 'A',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
        ]);

        Proveedor::create([
            'identificacion' => 'PROV-B-'.uniqid(),
            'nombres' => 'Vendor B',
            'apellidos' => 'B',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
        ]);

        $this->assertSame(
            2,
            DB::table('proveedores')->where('persona_id', $persona->id)->count(),
            'two proveedores with the same persona_id must coexist (no UNIQUE)'
        );
    }

    // ── 3.5 — nullOnDelete on all three ────────────────────────────────

    #[Test]
    public function deleting_a_persona_nulls_contacto_persona_id(): void
    {
        $persona = $this->createPersona();
        $entidad = $this->createEntidad();
        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Will Orphan',
            'apellidos' => 'C',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
            'score' => 0,
        ]);

        // Sanity: FK is wired before delete.
        $this->assertSame((int) $persona->id, (int) $contacto->fresh()->persona_id);

        // Hard-delete the persona. Persona uses SoftDeletes; forceDelete()
        // bypasses that to fire the ON DELETE SET NULL clause.
        $persona->forceDelete();

        $survivor = Contacto::withTrashed()->find($contacto->id);
        $this->assertNotNull($survivor, 'contacto row must survive persona delete');
        $this->assertNull(
            $survivor->persona_id,
            'contacto.persona_id must be NULL after persona delete (nullOnDelete)'
        );
    }

    #[Test]
    public function deleting_a_persona_nulls_colaboradores_persona_id(): void
    {
        $persona = $this->createPersona();
        $colaborador = Colaborador::create([
            'identificacion' => 'COL-DEL-'.uniqid(),
            'nombres' => 'Will',
            'apellidos' => 'Orphan',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
        ]);

        $this->assertSame((int) $persona->id, (int) $colaborador->fresh()->persona_id);

        $persona->forceDelete();

        $survivor = Colaborador::withTrashed()->find($colaborador->id);
        $this->assertNotNull($survivor, 'colaborador row must survive persona delete');
        $this->assertNull(
            $survivor->persona_id,
            'colaboradores.persona_id must be NULL after persona delete (nullOnDelete)'
        );
    }

    #[Test]
    public function deleting_a_persona_nulls_proveedores_persona_id(): void
    {
        $persona = $this->createPersona();
        $proveedor = Proveedor::create([
            'identificacion' => 'PROV-DEL-'.uniqid(),
            'nombres' => 'Will',
            'apellidos' => 'Orphan',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
        ]);

        $this->assertSame((int) $persona->id, (int) $proveedor->fresh()->persona_id);

        $persona->forceDelete();

        $survivor = Proveedor::withTrashed()->find($proveedor->id);
        $this->assertNotNull($survivor, 'proveedor row must survive persona delete');
        $this->assertNull(
            $survivor->persona_id,
            'proveedores.persona_id must be NULL after persona delete (nullOnDelete)'
        );
    }

    // ── 3.6 — all three migrations reverse cleanly + entidad_id preserved

    #[Test]
    public function down_reverses_all_three_migrations_and_preserves_entidad_id(): void
    {
        // Sanity: each new column exists after RefreshDatabase.
        $this->assertTrue(
            Schema::hasColumn('contacto', 'persona_id'),
            'precondition: contacto.persona_id should exist before rollback'
        );
        $this->assertTrue(
            Schema::hasColumn('colaboradores', 'persona_id'),
            'precondition: colaboradores.persona_id should exist before rollback'
        );
        $this->assertTrue(
            Schema::hasColumn('proveedores', 'persona_id'),
            'precondition: proveedores.persona_id should exist before rollback'
        );

        // Roll back the 3 most-recent migrations (10, 11, 12).
        $this->artisan('migrate:rollback', ['--step' => 3])->assertExitCode(0);

        // All three persona_id columns are gone.
        $this->assertFalse(
            Schema::hasColumn('contacto', 'persona_id'),
            'contacto.persona_id must be gone after rollback (REQ-PRRL-004)'
        );
        $this->assertFalse(
            Schema::hasColumn('colaboradores', 'persona_id'),
            'colaboradores.persona_id must be gone after rollback (REQ-PRRL-004)'
        );
        $this->assertFalse(
            Schema::hasColumn('proveedores', 'persona_id'),
            'proveedores.persona_id must be gone after rollback (REQ-PRRL-004)'
        );

        // REQ-PRRL-006: the existing `entidad_id` FK on contacto is untouched.
        $this->assertTrue(
            Schema::hasColumn('contacto', 'entidad_id'),
            'contacto.entidad_id must still exist after rollback (REQ-PRRL-006)'
        );

        // contacto.entidad_id still works as an FK target (smoke insert).
        $entidad = $this->createEntidad();
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'After Rollback',
            'apellidos' => 'Smoke',
            'estado' => 'Activo',
            'score' => 0,
        ]);
        $this->assertSame(
            1,
            DB::table('contacto')->where('nombres', 'After Rollback')->count(),
            'contacto.entidad_id must remain a working FK after rollback'
        );

        // Restore state so subsequent tests in this class see the
        // post-PR-E schema (RefreshDatabase rolls back data but DDL is
        // committed, so without this re-apply subsequent relation tests
        // would find a missing `persona_id` column).
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    // ── 3.7 — relations work on all three models ───────────────────────

    #[Test]
    public function contacto_persona_relation_resolves_the_linked_persona(): void
    {
        $persona = $this->createPersona();
        $entidad = $this->createEntidad();
        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Rel',
            'apellidos' => 'Test',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
            'score' => 0,
        ]);

        $reloaded = Contacto::find($contacto->id);

        $this->assertNotNull(
            $reloaded->persona,
            'Contacto->persona must resolve the related Persona model'
        );
        $this->assertSame(
            (int) $persona->id,
            (int) $reloaded->persona->id,
            'Contacto->persona must return the linked persona'
        );
    }

    #[Test]
    public function colaborador_persona_relation_resolves_the_linked_persona(): void
    {
        $persona = $this->createPersona();
        $colaborador = Colaborador::create([
            'identificacion' => 'COL-REL-'.uniqid(),
            'nombres' => 'Rel',
            'apellidos' => 'Colab',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
        ]);

        $reloaded = Colaborador::find($colaborador->id);

        $this->assertNotNull(
            $reloaded->persona,
            'Colaborador->persona must resolve the related Persona model'
        );
        $this->assertSame(
            (int) $persona->id,
            (int) $reloaded->persona->id,
            'Colaborador->persona must return the linked persona'
        );
    }

    #[Test]
    public function proveedor_persona_relation_resolves_the_linked_persona(): void
    {
        $persona = $this->createPersona();
        $proveedor = Proveedor::create([
            'identificacion' => 'PROV-REL-'.uniqid(),
            'nombres' => 'Rel',
            'apellidos' => 'Vendor',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
        ]);

        $reloaded = Proveedor::find($proveedor->id);

        $this->assertNotNull(
            $reloaded->persona,
            'Proveedor->persona must resolve the related Persona model'
        );
        $this->assertSame(
            (int) $persona->id,
            (int) $reloaded->persona->id,
            'Proveedor->persona must return the linked persona'
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function createEntidad(array $overrides = []): Entidad
    {
        return Entidad::create(array_merge([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Test Entity',
            'identificacion' => 'TEST-'.uniqid(),
            'estado' => 'Activo',
        ], $overrides));
    }

    private function createPersona(array $overrides = []): Persona
    {
        return Persona::create(array_merge([
            'nombres' => 'Persona',
            'apellidos' => 'Test',
            'email_principal' => 'persona-'.uniqid().'@example.test',
        ], $overrides));
    }
}
