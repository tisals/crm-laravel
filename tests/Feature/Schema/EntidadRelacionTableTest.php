<?php

namespace Tests\Feature\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 5 — schema test for `entidad_relacion`.
 *
 * The pivot row is always required: every row needs an `entidad_id`
 * and a `tipo_relacion` + `effective_from`. `effective_to` is the
 * only optional column (NULL = current/open-ended).
 */
class EntidadRelacionTableTest extends TestCase
{
    use RefreshDatabase;

    private int $entidadId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entidadId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Test Corp',
            'identificacion' => 'TEST-'.uniqid(),
            'estado' => 'Cliente',
            'cliente_desde' => '2024-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function cliente_relation_with_effective_from_works(): void
    {
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $this->entidadId,
            'tipo_relacion' => 'cliente',
            'effective_from' => '2024-01-15',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('entidad_relacion')->where('entidad_id', $this->entidadId)->count());
    }

    #[Test]
    public function prospecto_relation_with_explicit_effective_to_works(): void
    {
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $this->entidadId,
            'tipo_relacion' => 'prospecto',
            'effective_from' => '2023-01-01',
            'effective_to' => '2024-01-14',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('entidad_relacion')->first();
        $this->assertSame('prospecto', $row->tipo_relacion);
        $this->assertSame('2024-01-14', $row->effective_to);
    }

    #[Test]
    public function multiple_temporal_rows_per_entidad_allowed(): void
    {
        // An entity was a prospect in 2023, became a cliente in 2024.
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $this->entidadId,
            'tipo_relacion' => 'prospecto',
            'effective_from' => '2023-01-01',
            'effective_to' => '2024-01-14',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $this->entidadId,
            'tipo_relacion' => 'cliente',
            'effective_from' => '2024-01-15',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('entidad_relacion')->where('entidad_id', $this->entidadId)->count());
    }

    #[Test]
    public function tipo_relacion_enforces_strict_enum(): void
    {
        $this->expectException(QueryException::class);
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $this->entidadId,
            'tipo_relacion' => 'invalid_tipo',
            'effective_from' => '2024-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function effective_from_is_required(): void
    {
        $this->expectException(QueryException::class);
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $this->entidadId,
            'tipo_relacion' => 'cliente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function entidad_id_is_required(): void
    {
        $this->expectException(QueryException::class);
        DB::table('entidad_relacion')->insert([
            'tipo_relacion' => 'cliente',
            'effective_from' => '2024-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function entidad_id_fk_cascade_works(): void
    {
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $this->entidadId,
            'tipo_relacion' => 'cliente',
            'effective_from' => '2024-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Hard-delete the entidad; the relacion row should cascade away.
        DB::table('entidad')->where('id', $this->entidadId)->update(['deleted_at' => now()]);
        DB::table('entidad')->where('id', $this->entidadId)->delete();

        $this->assertSame(0, DB::table('entidad_relacion')->where('entidad_id', $this->entidadId)->count());
    }
}
