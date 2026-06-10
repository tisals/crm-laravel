<?php

namespace Tests\Feature\API;

use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OportunidadClienteDesdeTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): string
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Admin User',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        return $usuario->createToken('test-token')->plainTextToken;
    }

    #[Test]
    public function cliente_desde_set_when_first_opp_won(): void
    {
        $token = $this->authenticate();
        $entidad = Entidad::factory()->create(['cliente_desde' => null]);
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        // Create opp in Aceptada state
        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'fecha' => now()->format('Y-m-d'),
                'estado' => 'Aceptada',
            ]);
        $id = $createResponse->json('data.id');

        // Mark as Ganada
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id, [
                'estado' => 'Ganada',
            ]);

        // Verify cliente_desde was set
        $this->assertNotNull(
            Entidad::find($entidad->id)->cliente_desde,
            'cliente_desde should be set when first opp is won'
        );
    }

    #[Test]
    public function cliente_desde_not_overwritten_on_second_win(): void
    {
        $token = $this->authenticate();
        $entidad = Entidad::factory()->create(['cliente_desde' => null]);
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        // First opp won
        $r1 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'fecha' => now()->format('Y-m-d'),
                'estado' => 'Aceptada',
            ]);
        $id1 = $r1->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id1, ['estado' => 'Ganada']);

        $originalClienteDesde = Entidad::find($entidad->id)->cliente_desde;

        // Small delay to ensure different timestamp
        sleep(1);

        // Second opp won for same entity
        $r2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'fecha' => now()->format('Y-m-d'),
                'estado' => 'Aceptada',
            ]);
        $id2 = $r2->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id2, ['estado' => 'Ganada']);

        $this->assertEquals(
            $originalClienteDesde,
            Entidad::find($entidad->id)->cliente_desde,
            'cliente_desde should NOT be overwritten on second win'
        );
    }

    #[Test]
    public function cliente_desde_cleared_when_opp_removed_from_ganada(): void
    {
        $token = $this->authenticate();
        $entidad = Entidad::factory()->create(['cliente_desde' => null]);
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        // Win an opp
        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'fecha' => now()->format('Y-m-d'),
                'estado' => 'Aceptada',
            ]);
        $id = $r->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id, ['estado' => 'Ganada']);

        $this->assertNotNull(Entidad::find($entidad->id)->cliente_desde);

        // Change estado from Ganada to something else
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id, ['estado' => 'Perdida']);

        $this->assertNull(
            Entidad::find($entidad->id)->cliente_desde,
            'cliente_desde should be cleared when opp leaves Ganada state'
        );
    }
}
