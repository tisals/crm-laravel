<?php

namespace Tests\Feature\Seeders;

use App\Application\UseCases\Oportunidad\OportunidadCsvImportUseCase;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pipeline Cotización now has 5 canonical stages identified by STABLE codigos:
 *   BORRADOR, ENVIADA, EN_NEGOCIACION, ACEPTADA, RECHAZADA
 *
 * The `nombre` column is the human-readable label (parametrizable in the future).
 *
 * Legacy stages ('Ganada', 'Perdida', 'Aprobado', 'Enviado', 'En Negociación',
 * 'Rechazado') are removed in the migration. The seeder:
 *   - Maps estado=22 (Ganado) → ACEPTADA (last positive stage)
 *   - Maps estado=23 (Perdido) → RECHAZADA (last negative stage)
 *   - Maps estado=21 (En negociacion) → EN_NEGOCIACION
 */
class CotizacionPipelineStagesTest extends TestCase
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
    public function pipeline_crea_solo_las_5_etapas_canonicas_con_codigo(): void
    {
        // Codigo must follow the canonical format GC-{SS}-{YYYY}-{NNN}
        // (see OportunidadCsvImportUseCase validation). The legacy
        // `TEST-001` placeholder bypassed the import counter because the
        // malformed-code branch increments `errors` instead of `created`.
        $this->importRow([
            'codigo' => 'GC-01-2026-001',
            'fecha' => '15/06/2026',
            'estado' => '20',
            'empresa' => 'Test Corp',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test@example.com',
            'contacto' => 'Test User',
        ]);

        $pipelineId = DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');
        $etapas = DB::table('pipeline_etapas')
            ->where('pipeline_id', $pipelineId)
            ->orderBy('orden')
            ->pluck('codigo')
            ->toArray();

        $this->assertSame(
            ['BORRADOR', 'ENVIADA', 'EN_NEGOCIACION', 'ACEPTADA', 'RECHAZADA'],
            $etapas,
            'Pipeline Cotización debe tener exactamente 5 etapas con códigos estables en el orden correcto'
        );
    }

    #[Test]
    public function estado_22_ganado_cae_en_codigo_aceptada(): void
    {
        // Codigo must follow the canonical format GC-{SS}-{YYYY}-{NNN}.
        $this->importRow([
            'codigo' => 'GC-01-2026-022',
            'fecha' => '15/06/2026',
            'estado' => '22', // Ganado en maestro
            'empresa' => 'Test Corp Ganado',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test-ganado@example.com',
            'contacto' => 'Test User Ganado',
        ]);

        $opp = DB::table('oportunidad')->where('codigo', 'GC-01-2026-022')->first();
        $stageCodigo = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('codigo');
        $this->assertSame('ACEPTADA', $stageCodigo, 'Estado Ganado debe mapear a código ACEPTADA');
    }

    #[Test]
    public function estado_23_perdido_cae_en_codigo_rechazada(): void
    {
        $this->importRow([
            'codigo' => 'GC-01-2026-023',
            'fecha' => '15/06/2026',
            'estado' => '23', // Perdido en maestro
            'empresa' => 'Test Corp Perdido',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test-perdido@example.com',
            'contacto' => 'Test User Perdido',
        ]);

        $opp = DB::table('oportunidad')->where('codigo', 'GC-01-2026-023')->first();
        $stageCodigo = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('codigo');
        $this->assertSame('RECHAZADA', $stageCodigo, 'Estado Perdido debe mapear a código RECHAZADA');
    }

    #[Test]
    public function estado_21_en_negociacion_cae_en_codigo_correspondiente(): void
    {
        $this->importRow([
            'codigo' => 'GC-01-2026-021',
            'fecha' => '15/06/2026',
            'estado' => '21', // En negociación
            'empresa' => 'Test Corp Neg',
            'valor_sin_iva' => '1000000',
            'email_contacto' => 'test-neg@example.com',
            'contacto' => 'Test User Neg',
        ]);

        $opp = DB::table('oportunidad')->where('codigo', 'GC-01-2026-021')->first();
        $stageCodigo = DB::table('pipeline_etapas')->where('id', $opp->pipeline_etapa_id)->value('codigo');
        $this->assertSame('EN_NEGOCIACION', $stageCodigo, 'Estado 21 debe mapear a código EN_NEGOCIACION');
    }
}
