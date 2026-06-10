<?php

namespace Tests\Feature\Events;

use App\Events\PipelineEtapaChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\CRM\Models\Oportunidad;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\PipelineEtapa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PipelineEtapaChangedDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Pipeline $pipeline;

    private PipelineEtapa $etapa1;

    private PipelineEtapa $etapa2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pipeline = Pipeline::factory()->create([
            'nombre' => 'Test Pipeline',
            'codigo' => 'TEST',
        ]);

        $this->etapa1 = PipelineEtapa::factory()->forPipeline($this->pipeline)->create([
            'nombre' => 'Etapa 1',
            'orden' => 1,
        ]);

        $this->etapa2 = PipelineEtapa::factory()->forPipeline($this->pipeline)->create([
            'nombre' => 'Etapa 2',
            'orden' => 2,
        ]);
    }

    private function createOportunidad(array $overrides = []): int
    {
        $entidadId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Natural',
            'nombre' => 'Test Entity',
            'identificacion' => 'TEST-'.rand(100000, 999999),
            'estado' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('oportunidad')->insertGetId(array_merge([
            'codigo' => 'TEST-'.rand(1000, 9999),
            'entidad_id' => $entidadId,
            'contacto_id' => null,
            'pipeline_id' => $this->pipeline->id,
            'pipeline_etapa_id' => $this->etapa1->id,
            'fecha' => now()->format('Y-m-d'),
            'estado' => 'Activa',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function debug_oportunidad_update_works(): void
    {
        $oportunidadId = $this->createOportunidad();

        $oportunidad = Oportunidad::findOrFail($oportunidadId);

        // Verify initial state
        $this->assertEquals($this->etapa1->id, $oportunidad->pipeline_etapa_id);
        $this->assertEquals('Activa', $oportunidad->estado);

        $oportunidad->update(['pipeline_etapa_id' => $this->etapa2->id]);

        // Reload from DB
        $updated = Oportunidad::findOrFail($oportunidadId);
        $this->assertEquals($this->etapa2->id, $updated->pipeline_etapa_id, 'pipeline_etapa_id should be updated in DB');
    }

    #[Test]
    public function event_dispatched_when_pipeline_etapa_changes(): void
    {
        $oportunidadId = $this->createOportunidad();

        Event::fake([PipelineEtapaChanged::class]);

        $oportunidad = Oportunidad::findOrFail($oportunidadId);
        $oportunidad->update(['pipeline_etapa_id' => $this->etapa2->id]);

        Event::assertDispatched(PipelineEtapaChanged::class);
    }

    #[Test]
    public function event_not_dispatched_when_pipeline_etapa_not_dirty(): void
    {
        $oportunidadId = $this->createOportunidad();

        Event::fake([PipelineEtapaChanged::class]);

        $oportunidad = Oportunidad::findOrFail($oportunidadId);
        $oportunidad->update(['observaciones' => 'Some notes here']);

        Event::assertNotDispatched(PipelineEtapaChanged::class);
    }
}
