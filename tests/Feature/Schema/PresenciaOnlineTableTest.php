<?php

namespace Tests\Feature\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 3 — schema test for `presencia_online`.
 */
class PresenciaOnlineTableTest extends TestCase
{
    use RefreshDatabase;

    private int $personaId;

    private int $entidadId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personaId = DB::table('personas')->insertGetId([
            'nombres' => 'Ada',
            
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
        DB::table('presencia_online')->insert([
            'persona_id' => $this->personaId,
            'tipo' => 'red_social',
            'plataforma' => 'linkedin',
            'url' => 'https://linkedin.com/in/ada',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('presencia_online')->where('persona_id', $this->personaId)->count());
    }

    #[Test]
    public function insert_with_only_entidad_succeeds(): void
    {
        DB::table('presencia_online')->insert([
            'entidad_id' => $this->entidadId,
            'tipo' => 'web',
            'plataforma' => 'otro',
            'url' => 'https://testcorp.com',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('presencia_online')->where('entidad_id', $this->entidadId)->count());
    }

    #[Test]
    public function insert_with_neither_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        DB::table('presencia_online')->insert([
            'tipo' => 'web',
            'plataforma' => 'otro',
            'url' => 'https://orphan.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function tipo_and_plataforma_enforce_strict_enums(): void
    {
        $this->expectException(QueryException::class);
        DB::table('presencia_online')->insert([
            'persona_id' => $this->personaId,
            'tipo' => 'invalid_tipo',
            'plataforma' => 'linkedin',
            'url' => 'https://linkedin.com/in/ada',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function multiple_platforms_per_entidad_allowed(): void
    {
        foreach ([
            ['red_social', 'linkedin', 'https://linkedin.com/company/test'],
            ['red_social', 'facebook', 'https://facebook.com/test'],
            ['web', 'wordpress', 'https://testcorp.com'],
            ['ecommerce', 'shopify', 'https://shop.testcorp.com'],
        ] as [$tipo, $plataforma, $url]) {
            DB::table('presencia_online')->insert([
                'entidad_id' => $this->entidadId,
                'tipo' => $tipo,
                'plataforma' => $plataforma,
                'url' => $url,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(4, DB::table('presencia_online')->where('entidad_id', $this->entidadId)->count());
    }
}
