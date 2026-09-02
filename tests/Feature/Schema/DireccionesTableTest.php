<?php

namespace Tests\Feature\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 3 — schema test for `direcciones`.
 *
 * Notes:
 *  - The FK target on `ciudad_codigo` is `ciudades.cod_municipio`
 *    (the ciudades PK), not `ciudades.id` (which doesn't exist).
 *  - `nombre_sede` is the slot the backfill writes legacy free-text
 *    city names to (see 2026_09_02_130200 migration).
 */
class DireccionesTableTest extends TestCase
{
    use RefreshDatabase;

    private int $personaId;

    private int $entidadId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personaId = DB::table('personas')->insertGetId([
            'nombres' => 'Ada',
            'email_principal' => 'ada@example.test',
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

        // Seed one ciudad for the FK test. The `ciudades` table uses
        // `cod_municipio` (VARCHAR) as its PK — there's no `id` column.
        // `insertGetId` on a VARCHAR PK returns the value we just
        // inserted (the cod_municipio itself).
        $this->ciudadCodigo = '11001';
        DB::table('ciudades')->insert([
            'cod_municipio' => $this->ciudadCodigo,
            'nombre' => 'Bogotá',
            'departamento' => 'Cundinamarca',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private string $ciudadCodigo;

    #[Test]
    public function insert_with_only_persona_succeeds(): void
    {
        DB::table('direcciones')->insert([
            'persona_id' => $this->personaId,
            'direccion_principal' => 'Calle 1 #2-3',
            'ciudad_codigo' => $this->ciudadCodigo,
            'pais' => 'CO',
            'tipo' => 'casa',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('direcciones')->where('persona_id', $this->personaId)->count());
    }

    #[Test]
    public function insert_with_only_entidad_succeeds(): void
    {
        DB::table('direcciones')->insert([
            'entidad_id' => $this->entidadId,
            'direccion_principal' => 'Av 9 #10-20',
            'ciudad_codigo' => $this->ciudadCodigo,
            'pais' => 'CO',
            'tipo' => 'oficina',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('direcciones')->where('entidad_id', $this->entidadId)->count());
    }

    #[Test]
    public function insert_with_neither_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        DB::table('direcciones')->insert([
            'direccion_principal' => 'Orphan address',
            'tipo' => 'casa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function multiple_sucursales_per_entidad_allowed(): void
    {
        // Per user request, entidades can have multiple "sucursales".
        foreach (['Sucursal Norte', 'Sucursal Centro', 'Sucursal Sur'] as $nombre) {
            DB::table('direcciones')->insert([
                'entidad_id' => $this->entidadId,
                'nombre_sede' => $nombre,
                'direccion_principal' => "Calle de {$nombre}",
                'ciudad_codigo' => $this->ciudadCodigo,
                'pais' => 'CO',
                'tipo' => 'sucursal',
                'es_principal' => $nombre === 'Sucursal Norte',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(3, DB::table('direcciones')->where('entidad_id', $this->entidadId)->count());
        $sedes = DB::table('direcciones')->orderBy('nombre_sede')->pluck('nombre_sede')->all();
        $this->assertSame(['Sucursal Centro', 'Sucursal Norte', 'Sucursal Sur'], $sedes);
    }

    #[Test]
    public function ciudad_codigo_with_invalid_value_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        DB::table('direcciones')->insert([
            'persona_id' => $this->personaId,
            'ciudad_codigo' => '99999-INVALID',
            'tipo' => 'casa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
