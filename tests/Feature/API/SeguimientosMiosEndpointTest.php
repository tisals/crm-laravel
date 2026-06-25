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
 * T-BE-18: GET /api/v1/seguimientos/mios
 *   - Comercial returns only seguimientos for entidades in entidad_usuario.
 *   - Admin returns all seguimientos.
 *   - Filters compose (estado, fecha_desde, fecha_hasta, tipo).
 */
class SeguimientosMiosEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;
    private string $comercialToken;
    private Usuario $comercial;
    private Usuario $admin;
    private Entidad $eMapped;
    private Entidad $eUnmapped;

    protected function setUp(): void
    {
        parent::setUp();

        $comercialRol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        $adminRol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        $autorRol = Rol::create(['nombre' => 'Autor', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $comercialRol->id, 'vista' => '*']);
        Permiso::create(['rol_id' => $adminRol->id, 'vista' => '*']);

        $this->comercial = Usuario::create([
            'nombre' => 'Comercial Test',
            'email' => 'comercial@mios.test',
            'password_hash' => bcrypt('password'),
            'rol_id' => $comercialRol->id,
            'estado' => 'Activo',
        ]);
        $this->admin = Usuario::create([
            'nombre' => 'Admin Test',
            'email' => 'admin@mios.test',
            'password_hash' => bcrypt('password'),
            'rol_id' => $adminRol->id,
            'estado' => 'Activo',
        ]);
        $autor = Usuario::create([
            'nombre' => 'Autor',
            'email' => 'autor@mios.test',
            'password_hash' => bcrypt('password'),
            'rol_id' => $autorRol->id,
            'estado' => 'Activo',
        ]);

        $this->comercialToken = $this->comercial->createToken('test')->plainTextToken;
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;

        $this->eMapped = Entidad::create([
            'nombre' => 'Mapped',
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900111111',
            'estado' => 'Cliente',
        ]);
        $this->eUnmapped = Entidad::create([
            'nombre' => 'Unmapped',
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900222222',
            'estado' => 'Cliente',
        ]);

        DB::table('entidad_usuario')->insert([
            'entidad_id' => $this->eMapped->id,
            'usuario_id' => $this->comercial->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2 seguimientos for mapped entidad
        Seguimiento::create([
            'entidad_id' => $this->eMapped->id,
            'tipo' => 'Llamada',
            'fecha' => '2026-06-15',
            'estado' => 'Pendiente',
            'notas' => 'mapped a',
            'autor_id' => $autor->id,
            'created_by' => $autor->id,
        ]);
        Seguimiento::create([
            'entidad_id' => $this->eMapped->id,
            'tipo' => 'Correo',
            'fecha' => '2026-06-20',
            'estado' => 'Completado',
            'notas' => 'mapped b',
            'autor_id' => $autor->id,
            'created_by' => $autor->id,
        ]);

        // 1 seguimiento for unmapped entidad
        Seguimiento::create([
            'entidad_id' => $this->eUnmapped->id,
            'tipo' => 'Nota',
            'fecha' => '2026-06-10',
            'estado' => 'Pendiente',
            'notas' => 'unmapped',
            'autor_id' => $autor->id,
            'created_by' => $autor->id,
        ]);
    }

    #[Test]
    public function comercial_sees_only_mapped_entity_seguimientos(): void
    {
        $response = $this->withToken($this->comercialToken)
            ->getJson('/api/v1/seguimientos/mios');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(2, $data);
        foreach ($data as $seg) {
            $this->assertSame($this->eMapped->id, $seg['entidad_id']);
        }
    }

    #[Test]
    public function admin_sees_all_seguimientos(): void
    {
        $response = $this->withToken($this->adminToken)
            ->getJson('/api/v1/seguimientos/mios');

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('data.total'));
    }

    #[Test]
    public function filters_compose_with_estado(): void
    {
        $response = $this->withToken($this->adminToken)
            ->getJson('/api/v1/seguimientos/mios?estado=Pendiente');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(2, $data);  // 2 pendientes (mapped a + unmapped)
        foreach ($data as $seg) {
            $this->assertSame('Pendiente', $seg['estado']);
        }
    }

    #[Test]
    public function filters_compose_with_fecha_desde(): void
    {
        $response = $this->withToken($this->adminToken)
            ->getJson('/api/v1/seguimientos/mios?fecha_desde=2026-06-15');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        // mapped a (2026-06-15), mapped b (2026-06-20) — unmapped (2026-06-10) excluded
        $this->assertCount(2, $data);
    }

    #[Test]
    public function requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/seguimientos/mios');
        $response->assertStatus(401);
    }
}
