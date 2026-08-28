<?php

namespace Tests\Feature\Migration;

use App\Models\Entidad;
use App\Models\Persona;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-A: personas table extensions.
 *
 * Covers REQ-PRAPI-001, REQ-PNCE-001, REQ-PNCE-005 (extension schema);
 * verifies the migration is fully reversible per REQ-ISCF-005 principles.
 *
 * Strict TDD: tests 1a.1–1a.4 are RED before the migration ships.
 */
class PersonaTableExtensionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function personas_has_tipo_persona_and_entidad_id_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumn('personas', 'tipo_persona'),
            'Expected personas.tipo_persona column to exist'
        );
        $this->assertTrue(
            Schema::hasColumn('personas', 'entidad_id'),
            'Expected personas.entidad_id column to exist'
        );
    }

    #[Test]
    public function personas_apellidos_is_nullable(): void
    {
        // After the migration, inserting a row with apellidos => null must succeed.
        $persona = Persona::create([
            'nombres' => 'Ada',
            'apellidos' => null,
            'email_principal' => 'ada@example.test',
        ]);

        $this->assertNotNull($persona->id, 'Persona with null apellidos should persist');
        $this->assertNull($persona->fresh()->apellidos);
    }

    #[Test]
    public function entidad_id_fk_uses_null_on_delete(): void
    {
        // Build a parent entidad, link a persona to it, delete the entidad,
        // and prove the persona row survives with entidad_id = null.
        $entidad = Entidad::create([
            'tipo_persona' => 'Natural',
            'tipo_id' => 'CC',
            'identificacion' => '9999999999',
            'nombre' => 'Parent Entidad',
            'estado' => 'Activo',
        ]);

        $persona = Persona::create([
            'nombres' => 'Bea',
            'apellidos' => 'Lovelace',
            'email_principal' => 'bea@example.test',
            'entidad_id' => $entidad->id,
        ]);

        // Sanity: FK is wired before delete.
        $this->assertSame((int) $entidad->id, (int) $persona->fresh()->entidad_id);

        // Hard-delete the parent entidad. Entidad uses SoftDeletes, so the
        // default ->delete() would soft-delete (UPDATE deleted_at) and NOT
        // trigger ON DELETE SET NULL. forceDelete() bypasses SoftDeletes
        // and issues an actual DELETE statement that fires the FK action.
        $entidad->forceDelete();

        // nullOnDelete means the persona row keeps existing with entidad_id = null.
        $survivor = Persona::withTrashed()->find($persona->id);
        $this->assertNotNull($survivor, 'Persona row must survive parent entidad delete');
        $this->assertNull(
            $survivor->entidad_id,
            'Persona.entidad_id must be null after parent delete (nullOnDelete)'
        );
    }

    #[Test]
    public function down_reverses_all_changes(): void
    {
        // Precondition: the new columns exist after RefreshDatabase.
        // This forces the test to be RED when the migration is missing.
        $this->assertTrue(
            Schema::hasColumn('personas', 'tipo_persona'),
            'precondition: tipo_persona should exist before rollback'
        );
        $this->assertTrue(
            Schema::hasColumn('personas', 'entidad_id'),
            'precondition: entidad_id should exist before rollback'
        );

        // Roll back the last applied migration — by timestamp ordering this
        // is 2026_08_28_000001_add_tipo_persona_and_entidad_id_to_personas_table.
        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);

        // Post-rollback: columns gone.
        $this->assertFalse(
            Schema::hasColumn('personas', 'tipo_persona'),
            'tipo_persona column should be gone after rollback'
        );
        $this->assertFalse(
            Schema::hasColumn('personas', 'entidad_id'),
            'entidad_id column should be gone after rollback'
        );

        // apellidos back to NOT NULL: insert with null must fail at DB level.
        $this->expectException(QueryException::class);
        Persona::create([
            'nombres' => 'Cami',
            'apellidos' => null,
            'email_principal' => 'cami@example.test',
        ]);
    }
}
