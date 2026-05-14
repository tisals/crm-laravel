<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContactoControllerTest extends TestCase
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
    public function it_lists_contactos(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/contacto');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_contacto(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/contacto', [
                'nombres' => 'Juan',
                'apellidos' => 'Pérez',
                'area' => 'Ventas',
                'cargo' => 'Gerente Comercial',
                'email_contacto' => 'juan@example.com',
                'tel_contacto' => '3001234567',
                'estado' => 'Activo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombres', 'Juan')
            ->assertJsonPath('data.apellidos', 'Pérez');
    }

    #[Test]
    public function it_shows_a_contacto(): void
    {
        $token = $this->authenticate();
        $contacto = Contacto::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/contacto/' . $contacto->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $contacto->id);
    }

    #[Test]
    public function it_updates_a_contacto(): void
    {
        $token = $this->authenticate();
        $contacto = Contacto::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/contacto/' . $contacto->id, [
                'nombres' => 'Updated Name',
                'apellidos' => $contacto->apellidos,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombres', 'Updated Name');
    }

    #[Test]
    public function it_deletes_a_contacto(): void
    {
        $token = $this->authenticate();
        $contacto = Contacto::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/contacto/' . $contacto->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_creates_contacto_with_entidad(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/contacto', [
                'entidad_id' => $entidad->id,
                'nombres' => 'Carlos',
                'apellidos' => 'López',
                'email_contacto' => 'carlos@empresa.com',
                'estado' => 'Activo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entidad_id', $entidad->id);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/contacto', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_missing_contacto(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/contacto/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
