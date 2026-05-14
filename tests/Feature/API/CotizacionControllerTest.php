<?php

namespace Tests\Feature\API;

use App\Models\Oportunidad;
use App\Models\Entidad;
use App\Models\Contacto;
use App\Models\DetalleOportunidad;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CotizacionControllerTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $user;
    private string $token;
    private Oportunidad $oportunidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->user = Usuario::create([
            'nombre' => 'Test',
            'email' => 'test@crm.dev',
            'password_hash' => bcrypt('password'),
            'rol_id' => 1,
            'estado' => 'Activo',
        ]);
        $this->token = $this->user->createToken('test')->plainTextToken;

        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '999999999-9',
            'nombre' => 'Test Corp',
            'dominio' => 'testcorp.com',
            'estado' => 'Activo',
        ]);

        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'email_contacto' => 'juan@testcorp.com',
            'movil' => '3000000000',
            'estado' => 'Activo',
        ]);

        $this->oportunidad = Oportunidad::create([
            'codigo' => 'TEST-001',
            'entidad_id' => $entidad->id,
            'contacto_id' => $contacto->id,
            'fecha' => now()->toDateString(),
            'estado' => 'Borrador',
            'created_by' => $this->user->id,
        ]);

        $producto = Producto::create([
            'nombre' => 'Servicio de prueba',
            'medida' => 'Und',
            'precio' => 100000,
            'estado' => 'Activo',
        ]);

        DetalleOportunidad::create([
            'oportunidad_id' => $this->oportunidad->id,
            'producto_id' => $producto->id,
            'concepto' => 'Servicio de prueba',
            'cantidad' => 1,
            'vr_unitario' => 100000,
            'vr_total' => 100000,
            'created_by' => $this->user->id,
        ]);
    }

    #[Test]
    public function enviar_rejects_non_borrador()
    {
        $this->oportunidad->update(['estado' => 'Enviada']);

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/oportunidades/{$this->oportunidad->id}/enviar");

        $response->assertStatus(422);
    }

    #[Test]
    public function ganar_rejects_non_aceptada()
    {
        $response = $this->withToken($this->token)
            ->postJson("/api/v1/oportunidades/{$this->oportunidad->id}/ganar");

        $response->assertStatus(422);
    }

    #[Test]
    public function aprobar_rejects_non_enviada()
    {
        $response = $this->withToken($this->token)
            ->postJson("/api/v1/oportunidades/{$this->oportunidad->id}/aprobar");

        $response->assertStatus(422);
    }

    #[Test]
    public function enviar_requires_detalle_lines()
    {
        $this->oportunidad->detalles()->delete();

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/oportunidades/{$this->oportunidad->id}/enviar");

        $response->assertStatus(422);
    }

    #[Test]
    public function enviar_requires_contacto_email()
    {
        $this->oportunidad->contacto->update(['email_contacto' => null]);

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/oportunidades/{$this->oportunidad->id}/enviar");

        $response->assertStatus(422);
    }
}
