<?php

namespace Tests\Feature\API;

use App\Models\Contacto;
use App\Models\DetalleOportunidad;
use App\Models\Entidad;
use App\Models\Oportunidad;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\CiudadSeeder;
use Database\Seeders\PermisoSeeder;
use Database\Seeders\RoleSeeder;
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
        $this->seed([
            RoleSeeder::class,
            PermisoSeeder::class,
            CiudadSeeder::class,
        ]);

        $superAdmin = Rol::where('nombre', 'SuperAdmin')->first() ?? Rol::create(['nombre' => 'SuperAdmin', 'estado' => 'Activo']);
        Permiso::firstOrCreate([
            'rol_id' => $superAdmin->id,
            'vista' => '*',
        ]);

        $this->user = Usuario::create([
            'nombre' => 'Test',
            'email' => 'test@crm.dev',
            'password_hash' => bcrypt('password'),
            'rol_id' => $superAdmin->id,
        ]);
        $this->token = $this->user->createToken('test')->plainTextToken;

        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '999999999-9',
            'nombre' => 'Test Corp',
            'dominio' => 'testcorp.com',
        ]);

        $contacto = Contacto::create([
            // Per commit fe99f70: `contacto.entidad_id` was dropped; the
            // contacto's entidad binding lives on `entidad_persona`,
            // keyed on the contacto's persona_id.
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'email_contacto' => 'juan@testcorp.com',
            'movil' => '3000000000',
        ]);
        // Stamp the pivot so CotizacionController's "belongs to entidad"
        // check via the pivot still resolves.
        $contacto->persona_id = \App\Models\Persona::create([
            'nombres' => 'Juan',
        ])->id;
        $contacto->save();
        \Illuminate\Support\Facades\DB::table('entidad_persona')->insert([
            'persona_id' => $contacto->persona_id,
            'entidad_id' => $entidad->id,
            'categoria' => 'asignacion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->oportunidad = Oportunidad::create([
            'codigo' => 'TEST-001',
            'entidad_id' => $entidad->id,
            'contacto_id' => $contacto->id,
            'fecha' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $producto = Producto::create([
            'nombre' => 'Servicio de prueba',
            'medida' => 'Und',
            'precio' => 100000,
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
