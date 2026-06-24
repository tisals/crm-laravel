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
 * oportunidad.pipeline_etapa_id must point to the "Ganada" stage, NOT "Borrador".
 *
 * This is the user-reported bug: only "Enviado" was being recognized; the
 * other maestro IDs were falling back to "Borrador".
 */
class OportunidadEstadoMappingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed required maestro data
        DB::table('maestros')->insert([
            ['id' => 19, 'nombre' => 'Borrador', 'campo' => 'Estado oportunidad', 'habilitado' => 'Y', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'nombre' => 'Enviado', 'campo' => 'Estado oportunidad', 'habilitado' => 'Y', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 21, 'nombre' => 'En negociacion', 'campo' => 'Estado oportunidad', 'habilitado' => 'Y', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 22, 'nombre' => 'Ganado', 'campo' => 'Estado oportunidad', 'habilitado' => 'Y', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 23, 'nombre' => 'Perdido', 'campo' => 'Estado oportunidad', 'habilitado' => 'Y', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Create a fallback product
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
    public function it_maps_estado_22_ganado_to_Ganada_stage(): void
    {
        $result = $this->importRow([
            'codigo' => 'TEST-001',
            'fecha' => '15/06/2026',
            'estado' => '22', // Ganado
            'empresa' => 'Test Corp',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test@example.com',
            'contacto' => 'Test User',
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['errors']);

        $opp = DB::table('oportunidad')->where('codigo', 'TEST-001')->first();
        $this->assertNotNull($opp);

        $stageNombre = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('nombre');
        $this->assertSame('Ganada', $stageNombre, "Expected stage 'Ganada' but got '{$stageNombre}'");
    }

    #[Test]
    public function it_maps_estado_23_perdido_to_Perdida_stage(): void
    {
        $this->importRow([
            'codigo' => 'TEST-002',
            'fecha' => '15/06/2026',
            'estado' => '23', // Perdido
            'empresa' => 'Test Corp 2',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test2@example.com',
            'contacto' => 'Test User 2',
        ]);

        $opp = DB::table('oportunidad')->where('codigo', 'TEST-002')->first();
        $stageNombre = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('nombre');

        $this->assertSame('Perdida', $stageNombre);
    }

    #[Test]
    public function it_maps_text_Generada_to_Enviada_stage(): void
    {
        $this->importRow([
            'codigo' => 'TEST-003',
            'fecha' => '15/06/2026',
            'estado' => 'Generada', // 9 CSV rows use this textual form
            'empresa' => 'Test Corp 3',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test3@example.com',
            'contacto' => 'Test User 3',
        ]);

        $opp = DB::table('oportunidad')->where('codigo', 'TEST-003')->first();
        $stageNombre = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('nombre');

        $this->assertSame('Enviada', $stageNombre);
    }

    #[Test]
    public function it_maps_estado_20_enviado_to_Enviada_stage(): void
    {
        $this->importRow([
            'codigo' => 'TEST-004',
            'fecha' => '15/06/2026',
            'estado' => '20', // Enviado
            'empresa' => 'Test Corp 4',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test4@example.com',
            'contacto' => 'Test User 4',
        ]);

        $opp = DB::table('oportunidad')->where('codigo', 'TEST-004')->first();
        $stageNombre = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('nombre');

        $this->assertSame('Enviada', $stageNombre);
    }
}
