<?php

namespace Tests\Feature\API;

use App\Models\Entidad;
use App\Models\Contacto;
use Modules\CRM\Actions\AssignScoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssignScoreActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
    }

    #[Test]
    public function it_calculates_base_score_only(): void
    {
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Jane',
            'apellidos' => 'Doe',
            'cargo' => 'Auxiliar',
            'email_contacto' => 'jane@gmail.com',
            'fuente' => 'Llamada fría',
        ]);

        $action = new AssignScoreAction();
        $score = $action->execute($contacto);

        // Base 10 + 0 (not CEO) + 0 (not corporate email) + 0 (not high value source) = 10
        $this->assertEquals(10, $score);
        $this->assertEquals(10, $contacto->fresh()->score);
    }

    #[Test]
    public function it_calculates_high_score_for_corporate_ceo(): void
    {
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'John',
            'apellidos' => 'Smith',
            'cargo' => 'Gerente General',
            'email_contacto' => 'john@tecnoinnsoft.com',
            'fuente' => 'Web Form',
        ]);

        $action = new AssignScoreAction();
        $score = $action->execute($contacto);

        // Base 10 + 30 (Gerente) + 20 (Web) + 20 (Corporate) = 80
        $this->assertEquals(80, $score);
        $this->assertEquals(80, $contacto->fresh()->score);
    }
}
