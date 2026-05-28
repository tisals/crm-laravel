<?php

namespace Tests\Feature\API;

use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Seguimiento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityDashboardTest extends TestCase
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
    public function it_returns_dashboard_kpis(): void
    {
        $token = $this->authenticate();

        // Create additional users
        Usuario::create([
            'nombre' => 'User Two',
            'email' => 'user2@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => 1,
            'estado' => 'Activo',
        ]);
        Usuario::create([
            'nombre' => 'Inactive User',
            'email' => 'inactive@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => 1,
            'estado' => 'Inactivo',
        ]);

        // Create products
        Producto::create(['nombre' => 'Producto A', 'linea_negocio' => 'TI', 'iva' => 19, 'estado' => 'Activo']);
        Producto::create(['nombre' => 'Producto B', 'linea_negocio' => 'Consultoría', 'iva' => 19, 'estado' => 'Activo']);

        // Create Propia brand
        Entidad::create([
            'nombre' => 'Tecnoinnsoft',
            'estado' => 'Propia',
            'identificacion' => '900123456-7',
            'tipo_identificacion' => 'NIT',
            'tipo_persona' => 'Jurídica',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/seguridad/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');

        // Verify KPI structure
        $this->assertArrayHasKey('kpi', $data);
        $this->assertArrayHasKey('distribucion_roles', $data);
        $this->assertArrayHasKey('actividad_reciente', $data);

        // Verify KPI values: 3 users (1 admin + 2 created), 2 active
        $this->assertEquals(3, $data['kpi']['total_usuarios']);
        $this->assertEquals(2, $data['kpi']['usuarios_activos']);
        $this->assertEquals(2, $data['kpi']['total_productos']);
        $this->assertEquals(1, $data['kpi']['total_marcas']);
    }

    #[Test]
    public function it_returns_zero_kpis_when_empty(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/seguridad/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $kpi = $response->json('data.kpi');

        $this->assertEquals(1, $kpi['total_usuarios']);  // the authenticated user exists
        $this->assertEquals(1, $kpi['usuarios_activos']);
        $this->assertEquals(0, $kpi['total_productos']);
        $this->assertEquals(0, $kpi['total_marcas']);
    }

    #[Test]
    public function it_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/v1/seguridad/dashboard');

        $response->assertStatus(401);
    }
}
