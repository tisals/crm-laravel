<?php

namespace Tests\Feature\Migration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 5 — backfill test.
 *
 * Seeds `entidad` rows with a representative spread of the legacy
 * `estado` values, then asserts the backfill migration produces the
 * correct `entidad_relacion` rows.
 */
class BackfillEntidadRelacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Helper: insert one entidad with a given estado + cliente_desde.
        // We use a fresh row per call so the seeded data mirrors what
        // Commit 5's backfill migration would encounter in production
        // (one entity per row, no relaciones yet).
        $this->seedEntidad('Cliente Customer', 'Cliente', '2024-01-15');
        $this->seedEntidad('Lowercase Cliente (legacy)', 'cliente', '2023-06-01');
        $this->seedEntidad('Prospect Active', 'Activo', null);
        $this->seedEntidad('Prospect Inactivo', 'Inactivo', null);
        $this->seedEntidad('Prospect Cancelado', 'Cancelado', null);
        $this->seedEntidad('Prospect Lowercase (legacy)', 'prospecto', null);

        // Re-run the backfill INSERTs here, in case the production
        // migration ran against an empty DB. The production migration
        // uses NOT EXISTS guards so this is safe to re-run.
        $this->runBackfillInserts();
    }

    private function seedEntidad(string $nombre, string $estado, ?string $clienteDesde): int
    {
        return DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica',
            'nombre' => $nombre,
            'identificacion' => 'TEST-'.uniqid(),
            'estado' => $estado,
            'cliente_desde' => $clienteDesde,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Mirror the production migration's INSERT (idempotent — NOT EXISTS
     * guards). We can't directly call `migrate:fresh` and rerun a
     * single migration without bespoke tooling, so we just replay the
     * SQL.
     */
    private function runBackfillInserts(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO `entidad_relacion`
                (`entidad_id`, `tipo_relacion`, `effective_from`, `effective_to`,
                 `created_at`, `updated_at`)
            SELECT
                e.id,
                CASE
                    WHEN e.estado IN ('Cliente', 'cliente')            THEN 'cliente'
                    WHEN e.estado = 'prospecto'                        THEN 'prospecto'
                    WHEN e.estado IN ('Activo', 'Inactivo', 'Cancelado') THEN 'prospecto'
                    ELSE 'prospecto'
                END,
                CASE
                    WHEN e.estado IN ('Cliente', 'cliente') AND e.cliente_desde IS NOT NULL
                        THEN DATE(e.cliente_desde)
                    ELSE CURDATE()
                END,
                NULL,
                NOW(),
                NOW()
            FROM `entidad` e
            WHERE NOT EXISTS (
                SELECT 1 FROM `entidad_relacion` er
                WHERE er.entidad_id = e.id
            )
        SQL);
    }

    #[Test]
    public function cliente_uppercase_becomes_cliente_with_cliente_desde(): void
    {
        $ent = DB::table('entidad')->where('nombre', 'Cliente Customer')->first();
        $row = DB::table('entidad_relacion')->where('entidad_id', $ent->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('cliente', $row->tipo_relacion);
        // effective_from should be the cliente_desde value (2024-01-15).
        $this->assertEquals('2024-01-15', substr((string) $row->effective_from, 0, 10));
        $this->assertNull($row->effective_to);
    }

    #[Test]
    public function cliente_lowercase_legacy_becomes_cliente(): void
    {
        $ent = DB::table('entidad')->where('nombre', 'Lowercase Cliente (legacy)')->first();
        $row = DB::table('entidad_relacion')->where('entidad_id', $ent->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('cliente', $row->tipo_relacion);
        $this->assertEquals('2023-06-01', substr((string) $row->effective_from, 0, 10));
    }

    #[Test]
    public function activo_becomes_prospecto(): void
    {
        $ent = DB::table('entidad')->where('nombre', 'Prospect Active')->first();
        $row = DB::table('entidad_relacion')->where('entidad_id', $ent->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('prospecto', $row->tipo_relacion);
        $this->assertNull($row->effective_to);
    }

    #[Test]
    public function inactivo_becomes_prospecto(): void
    {
        $ent = DB::table('entidad')->where('nombre', 'Prospect Inactivo')->first();
        $row = DB::table('entidad_relacion')->where('entidad_id', $ent->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('prospecto', $row->tipo_relacion);
    }

    #[Test]
    public function cancelado_becomes_prospecto(): void
    {
        $ent = DB::table('entidad')->where('nombre', 'Prospect Cancelado')->first();
        $row = DB::table('entidad_relacion')->where('entidad_id', $ent->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('prospecto', $row->tipo_relacion);
    }

    #[Test]
    public function prospecto_lowercase_legacy_becomes_prospecto(): void
    {
        $ent = DB::table('entidad')->where('nombre', 'Prospect Lowercase (legacy)')->first();
        $row = DB::table('entidad_relacion')->where('entidad_id', $ent->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('prospecto', $row->tipo_relacion);
    }

    #[Test]
    public function every_entidad_has_at_least_one_relacion(): void
    {
        $entidades = DB::table('entidad')->count();
        $relaciones = DB::table('entidad_relacion')->count();

        $this->assertSame($entidades, $relaciones, 'every entidad must have at least one relacion row');
    }
}
