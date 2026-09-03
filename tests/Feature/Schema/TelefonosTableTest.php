<?php

namespace Tests\Feature\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 3 — schema test for `telefonos`.
 *
 * Verifies the entity-or-person invariant (enforced by a TRIGGER,
 * not a CHECK constraint, because MariaDB 10.11 rejects CHECK
 * constraints whose target columns also participate in FKs). See
 * `2026_09_02_120000_create_telefonos_table.php` for the full story.
 */
class TelefonosTableTest extends TestCase
{
    use RefreshDatabase;

    private int $personaId;

    private int $entidadId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personaId = DB::table('personas')->insertGetId([
            'nombres' => 'Ada',
            'apellidos' => 'Lovelace',
            
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entidadId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Test Corp',
            'identificacion' => 'TEST-'.uniqid(),
            'estado' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function insert_with_only_persona_succeeds(): void
    {
        DB::table('telefonos')->insert([
            'persona_id' => $this->personaId,
            'numero' => '+57 300 1234567',
            'tipo' => 'movil',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('telefonos')->where('persona_id', $this->personaId)->count());
    }

    #[Test]
    public function insert_with_only_entidad_succeeds(): void
    {
        DB::table('telefonos')->insert([
            'entidad_id' => $this->entidadId,
            'numero' => '+57 601 5551212',
            'tipo' => 'trabajo',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('telefonos')->where('entidad_id', $this->entidadId)->count());
    }

    #[Test]
    public function insert_with_both_is_allowed(): void
    {
        DB::table('telefonos')->insert([
            'persona_id' => $this->personaId,
            'entidad_id' => $this->entidadId,
            'numero' => '+57 300 9999999',
            'tipo' => 'movil',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('telefonos')->first();
        $this->assertSame($this->personaId, (int) $row->persona_id);
        $this->assertSame($this->entidadId, (int) $row->entidad_id);
    }

    #[Test]
    public function insert_with_neither_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        DB::table('telefonos')->insert([
            'numero' => '+57 300 0000000',
            'tipo' => 'movil',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function tipo_enforces_strict_enum(): void
    {
        $this->expectException(QueryException::class);
        DB::table('telefonos')->insert([
            'persona_id' => $this->personaId,
            'numero' => '+57 300 0000000',
            'tipo' => 'invalid_tipo_value',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function es_principal_and_temporal_validity_coexist(): void
    {
        DB::table('telefonos')->insert([
            'persona_id' => $this->personaId,
            'numero' => '+57 300 1111111',
            'tipo' => 'movil',
            'es_principal' => true,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('telefonos')->first();
        $this->assertTrue((bool) $row->es_principal);
        $this->assertSame('2026-01-01', $row->valid_from);
        $this->assertSame('2026-12-31', $row->valid_to);
    }

    #[Test]
    public function soft_delete_preserves_row(): void
    {
        $id = DB::table('telefonos')->insertGetId([
            'persona_id' => $this->personaId,
            'numero' => '+57 300 2222222',
            'tipo' => 'movil',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('telefonos')->where('id', $id)->update(['deleted_at' => now()]);

        // Direct query builder doesn't filter soft-deleted rows; the
        // soft-delete behavior is enforced by the Eloquent model's
        // global scope. Use a `whereNull('deleted_at')` filter to
        // emulate that scope and confirm the row is excluded from
        // active lookups.
        $this->assertSame(0, DB::table('telefonos')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->count());
        // The row is preserved (soft delete, not hard delete).
        $this->assertSame(1, DB::table('telefonos')
            ->where('id', $id)
            ->whereNotNull('deleted_at')
            ->count());
    }
}
