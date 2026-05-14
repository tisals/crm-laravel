<?php

namespace Tests\Unit\Application\Services;

use App\Application\Services\RbacService;
use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RbacServiceTest extends TestCase
{
    use RefreshDatabase;

    private RbacService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RbacService();
    }

    #[Test]
    public function it_returns_true_when_permission_exists(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'entidad.index']);

        $result = $this->service->hasPermission($rol->id, 'entidad.index');

        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_when_permission_missing(): void
    {
        $rol = Rol::create(['nombre' => 'Ventas', 'estado' => 'Activo']);

        $result = $this->service->hasPermission($rol->id, 'entidad.index');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_handles_wildcard_permission(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $result = $this->service->hasPermission($rol->id, 'entidad.index');

        $this->assertTrue($result);
    }
}
