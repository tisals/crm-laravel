<?php

namespace Tests\Feature\API;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Modules\Shared\Models\Usuario;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-BE-07: fecha_fin MUST be >= fecha when both are provided.
 * 422 with clear Spanish message on violation.
 */
class SeguimientoRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $user = Usuario::create([
            'nombre' => 'Admin',
            'email' => 'admin@fecha-test.com',
            'password_hash' => bcrypt('password'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);
        $this->token = $user->createToken('test-token')->plainTextToken;
        Auth::login($user);
    }

    #[Test]
    public function fecha_fin_equal_to_fecha_is_accepted(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/seguimientos', [
                'tipo' => 'Llamada',
                'fecha' => '2026-06-15',
                'fecha_fin' => '2026-06-15',
                'notas' => 'same day end',
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(201);
    }

    #[Test]
    public function fecha_fin_after_fecha_is_accepted(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/seguimientos', [
                'tipo' => 'Llamada',
                'fecha' => '2026-06-15',
                'fecha_fin' => '2026-06-30',
                'notas' => 'multi-day',
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(201);
    }

    #[Test]
    public function fecha_fin_before_fecha_returns_422(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/seguimientos', [
                'tipo' => 'Llamada',
                'fecha' => '2026-06-15',
                'fecha_fin' => '2026-06-01',
                'notas' => 'invalid range',
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.fecha_fin.0', 'La fecha de fin debe ser igual o posterior a la fecha de inicio.');
    }

    #[Test]
    public function only_fecha_without_fecha_fin_is_accepted(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/seguimientos', [
                'tipo' => 'Nota',
                'fecha' => '2026-06-15',
                'notas' => 'no end date',
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(201);
    }
}
