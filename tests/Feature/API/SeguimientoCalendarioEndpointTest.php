<?php

namespace Tests\Feature\API;

use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\Seguimiento;
use Modules\Shared\Models\Usuario;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-BE-19: GET /api/v1/usuarios/{usuarioId}/calendario?mes=YYYY-MM
 *   - Self: 200 with grouped-by-day payload.
 *   - Cross-user without admin: 403.
 *   - Admin: 200 for any user.
 *   - Invalid mes param: 422.
 */
class SeguimientoCalendarioEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $comercial;
    private Usuario $otherComercial;
    private Usuario $admin;
    private string $comercialToken;
    private string $otherComercialToken;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $comercialRol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        $adminRol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        $autorRol = Rol::create(['nombre' => 'Autor', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $comercialRol->id, 'vista' => '*']);
        Permiso::create(['rol_id' => $adminRol->id, 'vista' => '*']);

        $this->comercial = Usuario::create([
            'nombre' => 'Comercial Cal',
            'email' => 'comercial@cal.test',
            'password_hash' => bcrypt('password'),
            'rol_id' => $comercialRol->id,
            'estado' => 'Activo',
        ]);
        $this->otherComercial = Usuario::create([
            'nombre' => 'Other Comercial',
            'email' => 'other@cal.test',
            'password_hash' => bcrypt('password'),
            'rol_id' => $comercialRol->id,
            'estado' => 'Activo',
        ]);
        $this->admin = Usuario::create([
            'nombre' => 'Admin Cal',
            'email' => 'admin@cal.test',
            'password_hash' => bcrypt('password'),
            'rol_id' => $adminRol->id,
            'estado' => 'Activo',
        ]);
        $autor = Usuario::create([
            'nombre' => 'Autor Cal',
            'email' => 'autor@cal.test',
            'password_hash' => bcrypt('password'),
            'rol_id' => $autorRol->id,
            'estado' => 'Activo',
        ]);

        $this->comercialToken = $this->comercial->createToken('test')->plainTextToken;
        $this->otherComercialToken = $this->otherComercial->createToken('test')->plainTextToken;
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;

        $entidad = Entidad::create([
            'nombre' => 'Cal Entidad',
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900333333',
            'estado' => 'Cliente',
        ]);
        DB::table('entidad_usuario')->insert([
            'entidad_id' => $entidad->id,
            'usuario_id' => $this->comercial->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3 seguimientos in June 2026
        foreach (['2026-06-05', '2026-06-15', '2026-06-25'] as $i => $fecha) {
            Seguimiento::create([
                'entidad_id' => $entidad->id,
                'tipo' => 'Llamada',
                'fecha' => $fecha,
                'estado' => 'Pendiente',
                'notas' => "cal-$i",
                'autor_id' => $autor->id,
                'created_by' => $autor->id,
            ]);
        }
    }

    #[Test]
    public function user_can_view_own_calendar(): void
    {
        $response = $this->withToken($this->comercialToken)
            ->getJson("/api/v1/usuarios/{$this->comercial->id}/calendario?mes=2026-06");

        $response->assertStatus(200);
        $this->assertSame('2026-06', $response->json('data.mes'));

        $dias = $response->json('data.dias');
        // 3 days with seguimientos: 2026-06-05, 2026-06-15, 2026-06-25
        $this->assertCount(3, $dias);
        $this->assertCount(1, $dias['2026-06-05']);
        $this->assertCount(1, $dias['2026-06-15']);
        $this->assertCount(1, $dias['2026-06-25']);
        $this->assertSame('cal-0', $dias['2026-06-05'][0]['notas']);
    }

    #[Test]
    public function cross_user_without_admin_returns_403(): void
    {
        // otherComercial tries to view comercial's calendar
        $response = $this->withToken($this->otherComercialToken)
            ->getJson("/api/v1/usuarios/{$this->comercial->id}/calendario?mes=2026-06");

        $response->assertStatus(403);
    }

    #[Test]
    public function admin_can_view_any_calendar(): void
    {
        $response = $this->withToken($this->adminToken)
            ->getJson("/api/v1/usuarios/{$this->comercial->id}/calendario?mes=2026-06");

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.dias'));
    }

    #[Test]
    public function invalid_mes_returns_422(): void
    {
        $response = $this->withToken($this->comercialToken)
            ->getJson("/api/v1/usuarios/{$this->comercial->id}/calendario?mes=not-a-date");

        $response->assertStatus(422);
    }

    #[Test]
    public function defaults_to_current_month_when_mes_omitted(): void
    {
        $currentMes = now()->format('Y-m');
        $response = $this->withToken($this->comercialToken)
            ->getJson("/api/v1/usuarios/{$this->comercial->id}/calendario");

        $response->assertStatus(200);
        $this->assertSame($currentMes, $response->json('data.mes'));
    }
}
