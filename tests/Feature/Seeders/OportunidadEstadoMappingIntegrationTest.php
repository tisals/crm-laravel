<?php

namespace Tests\Feature\Seeders;

use App\Application\UseCases\Oportunidad\OportunidadCsvImportUseCase;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end test: when the CSV has estado=22 (Ganado), the resulting
 * oportunidad.pipeline_etapa_id must point to the ACEPTADA stage.
 *
 * After the pipeline refactor (5 canonical stages with stable codigos):
 *   - estado 20 (Enviado)    → ENVIADA
 *   - estado 21 (En negociación) → EN_NEGOCIACION
 *   - estado 22 (Ganado)     → ACEPTADA   (last positive stage, equivalent of legacy "Ganada")
 *   - estado 23 (Perdido)    → RECHAZADA  (last negative stage, equivalent of legacy "Perdida")
 *   - texto "Generada"       → ENVIADA
 */
class OportunidadEstadoMappingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('maestros')->insert([
            ['id' => 19, 'nombre' => 'Borrador', 'campo' => 'Estado oportunidad', 'habilitado' => 'Y', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'nombre' => 'Enviado', 'campo' => 'Estado oportunidad', 'habilitado' => 'Y', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 21, 'nombre' => 'En negociación', 'campo' => 'Estado oportunidad', 'habilitado' => 'Y', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 22, 'nombre' => 'Ganado', 'campo' => 'Estado oportunidad', 'habilitado' => 'Y', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 23, 'nombre' => 'Perdido', 'campo' => 'Estado oportunidad', 'habilitado' => 'Y', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Producto::create([
            'nombre' => 'Test Product',
            'tipo' => 'Servicio',
            'estado' => 'Activo',
            'medida' => 'Und',
            'iva' => 19,
        ]);
    }

    private function importRow(array $row): array
    {
        $useCase = new OportunidadCsvImportUseCase;
        $useCase
            ->setEntityMap([])
            ->setContactDedup([])
            ->setProductMap(Producto::all()->all())
            ->setFallbackProduct(Producto::first())
            ->setDefaultUserId(1)
            ->setEstadoMap(DB::table('maestros')
                ->where('campo', 'Estado oportunidad')
                ->pluck('nombre', 'id')
                ->toArray())
            ->setClientesFacturacion(['nits' => [], 'names' => []]);

        return $useCase->import([$row]);
    }

    #[Test]
    public function it_maps_estado_22_ganado_to_aceptada_stage(): void
    {
        // Codigo must follow the canonical format GC-{SS}-{YYYY}-{NNN};
        // see OportunidadCsvImportUseCase::import() validation.
        $result = $this->importRow([
            'codigo' => 'GC-01-2026-001',
            'fecha' => '15/06/2026',
            'estado' => '22',
            'empresa' => 'Test Corp',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test@example.com',
            'contacto' => 'Test User',
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['errors']);

        $opp = DB::table('oportunidad')->where('codigo', 'GC-01-2026-001')->first();
        $this->assertNotNull($opp);

        $stageCodigo = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('codigo');
        $this->assertSame('ACEPTADA', $stageCodigo, "Expected stage 'ACEPTADA' but got '{$stageCodigo}'");
    }

    #[Test]
    public function it_maps_estado_23_perdido_to_rechazada_stage(): void
    {
        $this->importRow([
            'codigo' => 'GC-01-2026-002',
            'fecha' => '15/06/2026',
            'estado' => '23',
            'empresa' => 'Test Corp 2',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test2@example.com',
            'contacto' => 'Test User 2',
        ]);

        $opp = DB::table('oportunidad')->where('codigo', 'GC-01-2026-002')->first();
        $stageCodigo = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('codigo');

        $this->assertSame('RECHAZADA', $stageCodigo);
    }

    #[Test]
    public function it_maps_text_Generada_to_enviada_stage(): void
    {
        $this->importRow([
            'codigo' => 'GC-01-2026-003',
            'fecha' => '15/06/2026',
            'estado' => 'Generada',
            'empresa' => 'Test Corp 3',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test3@example.com',
            'contacto' => 'Test User 3',
        ]);

        $opp = DB::table('oportunidad')->where('codigo', 'GC-01-2026-003')->first();
        $stageCodigo = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('codigo');

        $this->assertSame('ENVIADA', $stageCodigo);
    }

    #[Test]
    public function it_maps_estado_20_enviado_to_enviada_stage(): void
    {
        $this->importRow([
            'codigo' => 'GC-01-2026-004',
            'fecha' => '15/06/2026',
            'estado' => '20',
            'empresa' => 'Test Corp 4',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test4@example.com',
            'contacto' => 'Test User 4',
        ]);

        $opp = DB::table('oportunidad')->where('codigo', 'GC-01-2026-004')->first();
        $stageCodigo = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('codigo');

        $this->assertSame('ENVIADA', $stageCodigo);
    }
}
