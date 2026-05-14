<?php

namespace Tests\Feature\API;

use App\Application\Services\RbacService;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RbacMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_allows_access_when_permission_exists(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'roles.index']);

        $usuario = Usuario::create([
            'nombre' => 'Admin User',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $token = $usuario->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/roles');

        $response->assertStatus(200);
    }

    #[Test]
    public function it_denies_access_when_permission_missing(): void
    {
        $rol = Rol::create(['nombre' => 'Ventas', 'estado' => 'Activo']);
        // No permiso for 'roles.index'

        $usuario = Usuario::create([
            'nombre' => 'Ventas User',
            'email' => 'ventas@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $token = $usuario->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/roles');

        $response->assertStatus(403);
    }
}
