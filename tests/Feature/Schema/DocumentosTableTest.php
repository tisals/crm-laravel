<?php

namespace Tests\Feature\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 3 — schema test for `documentos`.
 */
class DocumentosTableTest extends TestCase
{
    use RefreshDatabase;

    private int $personaId;

    private int $entidadId;

    private int $usuarioId;

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

        $rolId = DB::table('roles')->insertGetId([
            'nombre' => 'Admin',
            'estado' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Commit 2 made `usuarios.persona_id` NOT NULL. We need a
        // persona to back the usuario. Reuse the persona seeded above
        // so we don't need a second insert (and the FK holds).
        $this->usuarioId = DB::table('usuarios')->insertGetId([
            'persona_id' => $this->personaId,
            'nombre' => 'Admin User',
            'email' => 'admin@example.test',
            'password_hash' => bcrypt('password'),
            'rol_id' => $rolId,
            'estado' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function insert_with_only_persona_succeeds(): void
    {
        DB::table('documentos')->insert([
            'persona_id' => $this->personaId,
            'tipo_documento' => 'hv',
            'nombre_archivo' => 'ada_hv.pdf',
            'path_storage' => 'https://mercurio.example.com/Evidencias/ada/ada_hv.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12345,
            'uploaded_at' => now(),
            'uploaded_by' => $this->usuarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('documentos')->where('persona_id', $this->personaId)->count());
    }

    #[Test]
    public function insert_with_only_entidad_succeeds(): void
    {
        DB::table('documentos')->insert([
            'entidad_id' => $this->entidadId,
            'tipo_documento' => 'rut',
            'nombre_archivo' => 'testcorp_rut.pdf',
            'path_storage' => 'https://mercurio.example.com/Evidencias/testcorp/testcorp_rut.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 67890,
            'uploaded_at' => now(),
            'uploaded_by' => $this->usuarioId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('documentos')->where('entidad_id', $this->entidadId)->count());
    }

    #[Test]
    public function insert_with_neither_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        DB::table('documentos')->insert([
            'tipo_documento' => 'otro',
            'nombre_archivo' => 'orphan.pdf',
            'path_storage' => 'https://mercurio.example.com/Evidencias/orphan.pdf',
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function tipo_documento_enforces_strict_enum(): void
    {
        $this->expectException(QueryException::class);
        DB::table('documentos')->insert([
            'persona_id' => $this->personaId,
            'tipo_documento' => 'invalid_document_type',
            'nombre_archivo' => 'x.pdf',
            'path_storage' => 'https://mercurio.example.com/x.pdf',
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
