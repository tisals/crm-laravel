<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
use Modules\CRM\Actions\IngestLeadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IngestLeadActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $this->seed(\Database\Seeders\PipelineSeeder::class);
    }

    #[Test]
    public function it_ingests_lead_data_fully_through_pipeline(): void
    {
        $data = [
            'nombres' => 'Alice',
            'apellidos' => 'Wonderland',
            'email' => 'alice@redqueen.corp',
            'cargo' => 'Chief Executive Officer',
            'empresa' => 'Red Queen Corp',
            'utm_source' => 'GoogleAds',
            'utm_medium' => 'PPC',
        ];

        $action = new IngestLeadAction();
        $result = $action->execute($data);

        // Assert entity got created
        $this->assertDatabaseHas('entidad', [
            'nombre' => 'Red Queen Corp',
            'estado' => 'Prospecto',
        ]);

        // Assert contact got created
        $this->assertDatabaseHas('contacto', [
            'nombres' => 'Alice',
            'apellidos' => 'Wonderland',
            'email_contacto' => 'alice@redqueen.corp',
            'cargo' => 'Chief Executive Officer',
        ]);

        // Assert score calculated: Base 10 + 30 (CEO) + 20 (redqueen.corp corporate domain) = 60
        $this->assertEquals(60, $result['contacto']->score);

        // Assert opportunity got created
        $this->assertDatabaseHas('oportunidad', [
            'entidad_id' => $result['entidad']->id,
            'contacto_id' => $result['contacto']->id,
            'is_latest' => true,
            'estado' => 'Activa',
        ]);
    }
}
