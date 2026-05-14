<?php

namespace Tests\Feature\API;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Entidad;
use App\Models\Contacto;
use App\Models\Ciudad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OportunidadGanarTest extends TestCase
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

    private function createReferences(): array
    {
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        return ['entidad' => $entidad, 'contacto' => $contacto];
    }

    #[Test]
    public function winning_oportunidad_creates_servicio(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        // Create an oportunidad
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);

        $id = $createResponse->json('data.id');

        // Mark as Ganada
        $updateResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/oportunidades/' . $id, [
                'estado' => 'Ganada',
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'Ganada');

        // Verify Servicio was auto-created
        $this->assertDatabaseHas('servicios', [
            'oportunidad_id' => $id,
            'entidad_id' => $refs['entidad']->id,
        ]);
    }
}
