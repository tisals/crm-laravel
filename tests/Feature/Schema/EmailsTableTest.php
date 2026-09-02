<?php

namespace Tests\Feature\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 3 — schema test for `emails`.
 */
class EmailsTableTest extends TestCase
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
    }

    #[Test]
    public function insert_with_only_persona_succeeds(): void
    {
        DB::table('emails')->insert([
            'persona_id' => $this->personaId,
            'email' => 'ada@example.test',
            'tipo' => 'personal',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('emails')->where('persona_id', $this->personaId)->count());
    }

    #[Test]
    public function insert_with_only_entidad_succeeds(): void
    {
        DB::table('emails')->insert([
            'entidad_id' => $this->entidadId,
            'email' => 'contact@testcorp.com',
            'tipo' => 'trabajo',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('emails')->where('entidad_id', $this->entidadId)->count());
    }

    #[Test]
    public function insert_with_neither_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        DB::table('emails')->insert([
            'email' => 'orphan@example.com',
            'tipo' => 'personal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function tipo_enforces_strict_enum(): void
    {
        $this->expectException(QueryException::class);
        DB::table('emails')->insert([
            'persona_id' => $this->personaId,
            'email' => 'ada@example.test',
            'tipo' => 'invalid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function multiple_emails_per_person_allowed(): void
    {
        foreach (['personal', 'trabajo', 'otro'] as $tipo) {
            DB::table('emails')->insert([
                'persona_id' => $this->personaId,
                'email' => "ada+{$tipo}@example.test",
                'tipo' => $tipo,
                'es_principal' => $tipo === 'personal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(3, DB::table('emails')->where('persona_id', $this->personaId)->count());
    }
}
