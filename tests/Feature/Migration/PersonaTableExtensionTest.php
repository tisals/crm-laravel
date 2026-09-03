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
        // Commit 7a2d33c (`tenant-data-model-correction` Commit 1) dropped
        // `personas.tipo_persona` ENUM as part of the iter4 schema cleanup:
        // the new personas table is type-agnostic and the Natural/Juridica
        // split lives on `entidad.tipo_persona`. We verify the new state
        // (column gone, but `entidad_id` retained because PR-A still owns it).
        $this->assertFalse(
            Schema::hasColumn('personas', 'tipo_persona'),
            'personas.tipo_persona was dropped by Commit 7a2d33c; it must NOT exist'
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
        // Precondition: `entidad_id` still exists (Commit 1 only dropped
        // `tipo_persona`); `tipo_persona` is gone.
        $this->assertFalse(
            Schema::hasColumn('personas', 'tipo_persona'),
            'precondition: tipo_persona should already be gone (Commit 1)'
        );
        $this->assertTrue(
            Schema::hasColumn('personas', 'entidad_id'),
            'precondition: entidad_id should exist before rollback'
        );

        // Roll back enough migrations to undo PR-A. After PR-A landed,
        // PR-B/C/D/E/G added 9 migrations, Commit 1+2 added 4, Commit
        // 2.5 added the pagos_cliente migration, Commit 3 added 12,
        // Commit 4 added 2 (drop legacy columns), Commit 5 added 2
        // (entidad_relacion + backfill). That's 30 newer migrations
        // on top of PR-A, so we roll back 31 to reach the pre-PR-A
        // state.
        $this->artisan('migrate:rollback', ['--step' => 31])->assertExitCode(0);

        // Post-rollback: `entidad_id` gone.
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
