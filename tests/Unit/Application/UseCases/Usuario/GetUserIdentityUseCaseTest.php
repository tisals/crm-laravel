<?php

namespace Tests\Unit\Application\UseCases\Usuario;

use App\Application\UseCases\Usuario\GetUserIdentityUseCase;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GetUserIdentityUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private GetUserIdentityUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        \Cache::flush();
        $this->seed(\Database\Seeders\PermisoSeeder::class);
        $this->useCase = new GetUserIdentityUseCase;
    }

    #[Test]
    public function it_returns_null_for_nonexistent_user(): void
    {
        $result = $this->useCase->execute(9999);
        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_user_with_empty_apps_when_no_entidades(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        $user = Usuario::create([
            'nombre' => 'Test',
            'email' => 'test@x.com',
            'password_hash' => bcrypt('p'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $bundle = $this->useCase->execute($user->id);

        $this->assertNotNull($bundle);
        $this->assertSame('Test', $bundle['user']['nombre']);
        $this->assertSame('Admin', $bundle['user']['rol']['nombre']);
        $this->assertSame([], $bundle['apps']);
        $this->assertSame('v1', $bundle['scope_label']);
    }

    #[Test]
    public function it_includes_rol_defaults_in_apps_payload(): void
    {
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        // Grant a few rol defaults
        \DB::table('permisos')->insert([
            ['rol_id' => $rol->id, 'vista' => 'entidad.index', 'created_at' => now(), 'updated_at' => now()],
            ['rol_id' => $rol->id, 'vista' => 'contacto.update', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $user = Usuario::create([
            'nombre' => 'T', 'email' => 't@x.com', 'password_hash' => bcrypt('p'),
            'rol_id' => $rol->id, 'estado' => 'Activo',
        ]);

        $app = \DB::table('apps')->insertGetId([
            'slug' => 'brp', 'nombre' => 'BRP', 'tipo' => 'internal', 'auth_type' => 'sanctum',
            'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // User has access to BRP via entidad_usuario + app_entidad
        $entidad = \DB::table('entidad')->insertGetId([
            'nombre' => 'Acme', 'identificacion' => '1', 'estado' => 'Activo', 'tipo_persona' => 'Juridica',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('entidad_usuario')->insert([
            'usuario_id' => $user->id, 'entidad_id' => $entidad, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('app_entidad')->insert([
            'app_id' => $app, 'entidad_id' => $entidad, 'estado' => 'Activo',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $bundle = $this->useCase->execute($user->id);

        $this->assertCount(1, $bundle['apps']);
        $app = $bundle['apps'][0];
        $this->assertSame('brp', $app['slug']);
        $this->assertSame([], $app['permisos_scoped']);
        // permisos_efectivos = scoped ∪ rol_defaults
        $this->assertEqualsCanonicalizing(
            ['contacto.update', 'entidad.index'],
            $app['permisos_efectivos']
        );
        $this->assertEqualsCanonicalizing(
            ['contacto.update', 'entidad.index'],
            $bundle['rol_defaults']
        );
    }

    #[Test]
    public function it_includes_scoped_overrides_in_apps_payload(): void
    {
        $rol = Rol::create(['nombre' => 'C', 'estado' => 'Activo']);
        \DB::table('permisos')->insert(['rol_id' => $rol->id, 'vista' => 'entidad.index', 'created_at' => now(), 'updated_at' => now()]);

        $user = Usuario::create([
            'nombre' => 'T', 'email' => 't2@x.com', 'password_hash' => bcrypt('p'),
            'rol_id' => $rol->id, 'estado' => 'Activo',
        ]);

        $app = \DB::table('apps')->insertGetId([
            'slug' => 'brp', 'nombre' => 'BRP', 'tipo' => 'internal', 'auth_type' => 'sanctum',
            'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entidad = \DB::table('entidad')->insertGetId([
            'nombre' => 'A', 'identificacion' => '1', 'estado' => 'Activo', 'tipo_persona' => 'Juridica',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('entidad_usuario')->insert([
            'usuario_id' => $user->id, 'entidad_id' => $entidad, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('app_entidad')->insert([
            'app_id' => $app, 'entidad_id' => $entidad, 'estado' => 'Activo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Scoped override
        \DB::table('usuario_app_permisos')->insert([
            'usuario_id' => $user->id, 'app_id' => $app, 'vista' => 'brp.admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $bundle = $this->useCase->execute($user->id);

        $app = $bundle['apps'][0];
        $this->assertSame(['brp.admin'], $app['permisos_scoped']);
        $this->assertEqualsCanonicalizing(
            ['brp.admin', 'entidad.index'],
            $app['permisos_efectivos']
        );
    }
}
