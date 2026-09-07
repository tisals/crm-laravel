<?php

namespace Tests\Feature\Migration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 4 — verify the post-drop schema surface for the contact-data
 * tables that Commit 3 created.
 *
 * Commit 4 dropped the legacy columns (`email_principal`,
 * `telefono_principal`, `direccion`, `ciudad`, `pais` on
 * `personas`; `email`, `telefono`, `direccion`, `ciudad_cod`,
 * `dominio`, `red_social_url` on `entidad`). The canonical home for
 * these values is now the shared `emails` / `telefonos` / `direcciones`
 * / `presencia_online` tables. This test seeds those tables directly
 * and asserts the rows survive a `RefreshDatabase`-triggered
 * `migrate:fresh` cycle (i.e. the schema migrations for the new
 * tables still apply cleanly even with data present at the schema
 * snapshot).
 *
 * The backfill-specific logic that used to live here was the
 * `2026_09_02_130000..130600_backfill_*.php` migrations. Their SQL
 * is no longer exercised by these tests because the source data
 * (the legacy columns) no longer exists in the schema. The
 * backfill migrations themselves are still applied — they just
 * find zero rows to copy.
 */
class BackfillContactTablesTest extends TestCase
{
    use RefreshDatabase;

    private int $personaId;

    private int $entidadId;

    private string $ciudadCodigo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ciudadCodigo = '11001';
        DB::table('ciudades')->insert([
            'cod_municipio' => $this->ciudadCodigo,
            'nombre' => 'Bogotá',
            'departamento' => 'Cundinamarca',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function schema_supports_persona_email_telefono_direccion(): void
    {
        DB::table('emails')->insert([
            'persona_id' => $this->personaId,
            'email' => 'ada.lovelace@example.test',
            'tipo' => 'personal',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('telefonos')->insert([
            'persona_id' => $this->personaId,
            'numero' => '+57 300 1234567',
            'tipo' => 'movil',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('direcciones')->insert([
            'persona_id' => $this->personaId,
            'direccion_principal' => 'Calle 1 #2-3',
            'nombre_sede' => 'Bogotá',
            'pais' => 'CO',
            'tipo' => 'casa',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('emails')->where('persona_id', $this->personaId)->count());
        $this->assertSame(1, DB::table('telefonos')->where('persona_id', $this->personaId)->count());
        $this->assertSame(1, DB::table('direcciones')->where('persona_id', $this->personaId)->count());
    }

    #[Test]
    public function schema_supports_entidad_email_telefono_direccion(): void
    {
        DB::table('emails')->insert([
            'entidad_id' => $this->entidadId,
            'email' => 'contact@testcorp.com',
            'tipo' => 'trabajo',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('telefonos')->insert([
            'entidad_id' => $this->entidadId,
            'numero' => '+57 601 5551212',
            'tipo' => 'trabajo',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('direcciones')->insert([
            'entidad_id' => $this->entidadId,
            'direccion_principal' => 'Av 9 #10-20',
            'ciudad_codigo' => $this->ciudadCodigo,
            'tipo' => 'oficina',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('emails')->where('entidad_id', $this->entidadId)->count());
        $this->assertSame(1, DB::table('telefonos')->where('entidad_id', $this->entidadId)->count());
        $this->assertSame(1, DB::table('direcciones')->where('entidad_id', $this->entidadId)->count());
    }

    #[Test]
    public function schema_supports_presencia_online(): void
    {
        DB::table('presencia_online')->insert([
            'entidad_id' => $this->entidadId,
            'tipo' => 'web',
            'plataforma' => 'otro',
            'url' => 'testcorp.com.co',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('presencia_online')->insert([
            'entidad_id' => $this->entidadId,
            'tipo' => 'red_social',
            'plataforma' => 'facebook',
            'url' => 'facebook.com/testcorp',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('presencia_online')->where('entidad_id', $this->entidadId)->count());
    }

    #[Test]
    public function legacy_columns_are_dropped_on_personas(): void
    {
        // Commit 4 dropped these. The schema must not let callers write
        // to them anymore.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('personas', 'email_principal'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('personas', 'telefono_principal'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('personas', 'direccion'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('personas', 'ciudad'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('personas', 'pais'));
    }

    #[Test]
    public function legacy_columns_are_dropped_on_entidad(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('entidad', 'email'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('entidad', 'telefono'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('entidad', 'direccion'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('entidad', 'ciudad_cod'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('entidad', 'dominio'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('entidad', 'red_social_url'));
    }
}
