<?php

namespace Tests\Feature\UseCases;

use App\Application\UseCases\Seguimiento\StoreSeguimientoUseCase;
use App\Domain\Repositories\SeguimientoRepositoryInterface;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Modules\CRM\Models\Seguimiento;
use Modules\Shared\Models\Usuario;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-BE-05: autor_id and created_by MUST be auto-set from Auth::id().
 * Client-provided values MUST be ignored.
 */
class StoreSeguimientoUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private function makeUsuario(string $email, string $rolNombre = 'Admin'): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => $rolNombre], ['estado' => 'Activo']);

        return Usuario::create([
            'nombre' => 'Test User',
            'email' => $email,
            'password_hash' => bcrypt('password'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);
    }

    #[Test]
    public function it_auto_sets_autor_id_from_auth_user(): void
    {
        $user = $this->makeUsuario('auth@test.com');
        Auth::login($user);

        $captured = null;
        $repo = Mockery::mock(SeguimientoRepositoryInterface::class);
        $repo->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $data) use (&$captured) {
                $captured = $data;

                return new Seguimiento;
            });

        $useCase = new StoreSeguimientoUseCase($repo);
        $useCase->execute([
            'tipo' => 'Llamada',
            'fecha' => '2026-06-15',
        ]);

        $this->assertNotNull($captured, 'Repository should have been called');
        $this->assertSame($user->id, $captured['autor_id'], 'autor_id should match Auth::id()');
        $this->assertSame($user->id, $captured['created_by'], 'created_by should match Auth::id()');
    }

    #[Test]
    public function it_overrides_client_provided_autor_id_and_created_by(): void
    {
        $user = $this->makeUsuario('real@test.com');
        Auth::login($user);

        $captured = null;
        $repo = Mockery::mock(SeguimientoRepositoryInterface::class);
        $repo->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $data) use (&$captured) {
                $captured = $data;

                return new Seguimiento;
            });

        $useCase = new StoreSeguimientoUseCase($repo);
        $useCase->execute([
            'tipo' => 'Llamada',
            'fecha' => '2026-06-15',
            'autor_id' => 999,
            'created_by' => 999,
        ]);

        $this->assertNotNull($captured);
        // Client tried to inject id=999. Must be overridden by Auth::id().
        $this->assertSame($user->id, $captured['autor_id']);
        $this->assertSame($user->id, $captured['created_by']);
        $this->assertNotSame(999, $captured['autor_id']);
    }

    #[Test]
    public function integration_with_real_repository_persists_autor_id(): void
    {
        $user = $this->makeUsuario('integration@test.com');
        Auth::login($user);

        // Use the actual Eloquent repository (resolved from container)
        $useCase = app(StoreSeguimientoUseCase::class);
        $created = $useCase->execute([
            'tipo' => 'Nota',
            'fecha' => '2026-06-15',
            'notas' => 'test',
            'estado' => 'Pendiente',
        ]);

        $this->assertNotNull($created->id);
        $this->assertSame($user->id, $created->autor_id);
        $this->assertSame($user->id, $created->created_by);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
