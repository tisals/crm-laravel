<?php

namespace Tests\Feature\API;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Entidad;
use App\Models\Contacto;
use App\Models\Oportunidad;
use App\Models\DetalleOportunidad;
use App\Models\Producto;
use App\Models\Ciudad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $this->seed(\Database\Seeders\PipelineSeeder::class);
    }

    private function createAdminUser(): array
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

        return [
            'usuario' => $usuario,
            'token' => $usuario->createToken('test-token')->plainTextToken,
        ];
    }

    private function createVentasUser(): array
    {
        $rol = Rol::create(['nombre' => 'Ventas', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Ventas User',
            'email' => 'ventas@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        return [
            'usuario' => $usuario,
            'token' => $usuario->createToken('test-token')->plainTextToken,
        ];
    }

    #[Test]
    public function dashboard_returns_expected_structure(): void
    {
        $auth = $this->createAdminUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'prospectos' => [
                        'nuevos_leads_mes',
                        'tasa_conversion',
                        'entidades_por_mes',
                        'entidades_convertidas_mes',
                        'oportunidades_por_estado',
                    ],
                    'ventas' => [
                        'ventas_mes',
                        'ventas_por_mes',
                        'ltv',
                        'funnel',
                    ],
                    'chart' => [
                        'meses',
                        'entidades_convertidas',
                        'ventas',
                    ],
                    'actividades_recientes',
                ],
            ]);
    }

    #[Test]
    public function conversion_rate_is_ganadas_vs_total(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        // Create 10 opps, 2 ganadas
        for ($i = 0; $i < 8; $i++) {
            Oportunidad::factory()->create([
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'estado' => 'Perdida',
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            Oportunidad::factory()->create([
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'estado' => 'Ganada',
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/dashboard');

        // tasa = 2/10 = 20%
        $response->assertJsonPath('data.prospectos.tasa_conversion', 20);
    }

    #[Test]
    public function conversion_rate_zero_when_no_opportunities(): void
    {
        $auth = $this->createAdminUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.prospectos.tasa_conversion', 0);
    }

    #[Test]
    public function ventas_mes_is_annual_total_divided_by_12(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        // Create a product
        $producto = Producto::factory()->create();

        // Create 2 won opps in diff months each with different amounts
        $opp1 = Oportunidad::factory()->create([
            'entidad_id' => $entidad->id,
            'contacto_id' => $contacto->id,
            'estado' => 'Ganada',
            'fecha' => now()->subMonths(2)->format('Y-m-d'),
        ]);
        DetalleOportunidad::factory()->create([
            'oportunidad_id' => $opp1->id,
            'producto_id' => $producto->id,
            'vr_total' => 1000,
        ]);

        $opp2 = Oportunidad::factory()->create([
            'entidad_id' => $entidad->id,
            'contacto_id' => $contacto->id,
            'estado' => 'Ganada',
            'fecha' => now()->subMonth()->format('Y-m-d'),
        ]);
        DetalleOportunidad::factory()->create([
            'oportunidad_id' => $opp2->id,
            'producto_id' => $producto->id,
            'vr_total' => 500,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/dashboard');

        // ventas_mes = total anual / 12 = 1500 / 12 = 125
        $this->assertEquals(125.0, $response->json('data.ventas.ventas_mes'));
    }

    #[Test]
    public function ltv_is_average_monthly_billing_per_client(): void
    {
        $auth = $this->createAdminUser();
        $producto = Producto::factory()->create();

        // Entity 1: 2 won opps in DIFFERENT months → total 3000 / 2 months = 1500/mes
        $ent1 = Entidad::factory()->create();
        $c1 = Contacto::factory()->create(['entidad_id' => $ent1->id]);
        $opp1 = Oportunidad::factory()->create([
            'entidad_id' => $ent1->id, 'contacto_id' => $c1->id, 'estado' => 'Ganada',
            'fecha' => '2026-01-15',
        ]);
        DetalleOportunidad::factory()->create(['oportunidad_id' => $opp1->id, 'producto_id' => $producto->id, 'vr_total' => 2000]);
        $opp2 = Oportunidad::factory()->create([
            'entidad_id' => $ent1->id, 'contacto_id' => $c1->id, 'estado' => 'Ganada',
            'fecha' => '2026-02-10',
        ]);
        DetalleOportunidad::factory()->create(['oportunidad_id' => $opp2->id, 'producto_id' => $producto->id, 'vr_total' => 1000]);

        // Entity 2: 1 won opp in 1 month → 500 / 1 = 500/mes
        $ent2 = Entidad::factory()->create();
        $c2 = Contacto::factory()->create(['entidad_id' => $ent2->id]);
        $opp3 = Oportunidad::factory()->create([
            'entidad_id' => $ent2->id, 'contacto_id' => $c2->id, 'estado' => 'Ganada',
            'fecha' => '2026-01-20',
        ]);
        DetalleOportunidad::factory()->create(['oportunidad_id' => $opp3->id, 'producto_id' => $producto->id, 'vr_total' => 500]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/dashboard');

        // LTV = avg(1500, 500) = 1000
        $this->assertEquals(1000.0, $response->json('data.ventas.ltv'));
    }

    #[Test]
    public function chart_returns_12_months(): void
    {
        $auth = $this->createAdminUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/dashboard');

        $chart = $response->json('data.chart');

        $this->assertCount(12, $chart['meses']);
        $this->assertCount(12, $chart['entidades_convertidas']);
        $this->assertCount(12, $chart['ventas']);
    }

    #[Test]
    public function ventas_user_sees_only_assigned_entities(): void
    {
        $admin = $this->createAdminUser();
        $ventas = $this->createVentasUser();

        $producto = Producto::factory()->create();

        // Entity A: assigned to ventas user
        $entA = Entidad::factory()->create();
        $cA = Contacto::factory()->create(['entidad_id' => $entA->id]);
        $entA->usuarios()->attach($ventas['usuario']->id);
        $oppA = Oportunidad::factory()->create(['entidad_id' => $entA->id, 'contacto_id' => $cA->id, 'estado' => 'Ganada']);
        DetalleOportunidad::factory()->create(['oportunidad_id' => $oppA->id, 'producto_id' => $producto->id, 'vr_total' => 1000]);

        // Entity B: NOT assigned to ventas user
        $entB = Entidad::factory()->create();
        $cB = Contacto::factory()->create(['entidad_id' => $entB->id]);
        $oppB = Oportunidad::factory()->create(['entidad_id' => $entB->id, 'contacto_id' => $cB->id, 'estado' => 'Ganada']);
        DetalleOportunidad::factory()->create(['oportunidad_id' => $oppB->id, 'producto_id' => $producto->id, 'vr_total' => 2000]);

        // Admin sees all (LTV = 3000/2 = 1500)
        $adminResponse = $this->withHeader('Authorization', 'Bearer ' . $admin['token'])
            ->getJson('/api/v1/dashboard');
        $adminLtv = $adminResponse->json('data.ventas.ltv');

        // Ventas sees only Entity A (LTV = 1000/1 = 1000)
        // Use actingAs instead of Bearer token to avoid Sanctum session reuse from admin request
        $ventasResponse = $this->actingAs($ventas['usuario'])
            ->getJson('/api/v1/dashboard');
        $ventasLtv = $ventasResponse->json('data.ventas.ltv');

        $this->assertEquals(1500.0, $adminLtv);
        $this->assertEquals(1000.0, $ventasLtv);
    }

    #[Test]
    public function admin_can_filter_by_comercial_id(): void
    {
        $admin = $this->createAdminUser();
        $ventas = $this->createVentasUser();

        $producto = Producto::factory()->create();

        // Entity assigned to ventas
        $ent = Entidad::factory()->create();
        $c = Contacto::factory()->create(['entidad_id' => $ent->id]);
        $ent->usuarios()->attach($ventas['usuario']->id);
        $opp = Oportunidad::factory()->create(['entidad_id' => $ent->id, 'contacto_id' => $c->id, 'estado' => 'Ganada']);
        DetalleOportunidad::factory()->create(['oportunidad_id' => $opp->id, 'producto_id' => $producto->id, 'vr_total' => 500]);

        // Admin filters by comercial
        $response = $this->withHeader('Authorization', 'Bearer ' . $admin['token'])
            ->getJson('/api/v1/dashboard?comercial_id=' . $ventas['usuario']->id);

        $response->assertStatus(200);
        $this->assertEquals(500.0, $response->json('data.ventas.ltv'));
    }

    #[Test]
    public function date_range_filters_are_accepted(): void
    {
        $auth = $this->createAdminUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/dashboard?fecha_inicio=2026-01-01&fecha_fin=2026-06-01');

        $response->assertStatus(200);
    }

    #[Test]
    public function funnel_returns_estados_with_counts_and_amounts(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);
        $producto = Producto::factory()->create();

        $opp = Oportunidad::factory()->create([
            'entidad_id' => $entidad->id,
            'contacto_id' => $contacto->id,
            'estado' => 'Ganada',
        ]);
        DetalleOportunidad::factory()->create([
            'oportunidad_id' => $opp->id,
            'producto_id' => $producto->id,
            'vr_total' => 1500,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/dashboard');

        $funnel = $response->json('data.ventas.funnel');
        $ganadaEntry = collect($funnel)->firstWhere('estado', 'Ganada');

        $this->assertNotNull($ganadaEntry);
        $this->assertEquals(1, $ganadaEntry['total']);
        $this->assertEquals(1500.0, (float) $ganadaEntry['monto']);
    }

    #[Test]
    public function oportunidades_por_estado_returns_count_per_state(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        Oportunidad::factory()->create(['entidad_id' => $entidad->id, 'contacto_id' => $contacto->id, 'estado' => 'Borrador']);
        Oportunidad::factory()->count(2)->create(['entidad_id' => $entidad->id, 'contacto_id' => $contacto->id, 'estado' => 'Enviada']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/dashboard');

        $estados = $response->json('data.prospectos.oportunidades_por_estado');
        $borrador = collect($estados)->firstWhere('estado', 'Borrador');
        $enviada = collect($estados)->firstWhere('estado', 'Enviada');

        $this->assertEquals(1, $borrador['total']);
        $this->assertEquals(2, $enviada['total']);
    }

    #[Test]
    public function nuevos_leads_mes_counts_contacts_created_this_month(): void
    {
        $auth = $this->createAdminUser();

        // Create contacts this month
        Contacto::factory()->count(3)->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/dashboard');

        $this->assertEquals(3, $response->json('data.prospectos.nuevos_leads_mes'));
    }
}
