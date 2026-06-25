<?php

namespace Tests\Feature\Notifications;

use App\Application\Seguimiento\Services\NotificacionRecipientsResolver;
use App\Models\Entidad;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CRM\Models\Seguimiento;
use Modules\Shared\Models\Usuario;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-BE-10: FollowUpNotification routing:
 *   - Primary: comercials mapped to seguimiento's entidad via entidad_usuario.
 *   - Fallback: Admin and SuperAdmin users.
 *   - No recipients: returns empty collection (caller logs warning).
 *
 * Tests target the NotificacionRecipientsResolver service directly so they're
 * isolated from the controller's HTTP path.
 */
class FollowUpNotificationRoutingTest extends TestCase
{
    use RefreshDatabase;

    private NotificacionRecipientsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(NotificacionRecipientsResolver::class);
    }

    private function makeRol(string $nombre): int
    {
        return Rol::firstOrCreate(['nombre' => $nombre], ['estado' => 'Activo'])->id;
    }

    private function makeUsuario(string $email, int $rolId, string $estado = 'Activo'): Usuario
    {
        return Usuario::create([
            'nombre' => $email,
            'email' => $email,
            'password_hash' => bcrypt('password'),
            'rol_id' => $rolId,
            'estado' => $estado,
        ]);
    }

    private function makeSeguimiento(int $entidadId): Seguimiento
    {
        // Autor uses a neutral 'Autor' rol so it doesn't skew admin/comercial counts.
        $autorRol = $this->makeRol('Autor');
        $autor = $this->makeUsuario('autor@test.com', $autorRol);

        return Seguimiento::create([
            'entidad_id' => $entidadId,
            'tipo' => 'Llamada',
            'fecha' => '2026-06-15',
            'estado' => 'Pendiente',
            'autor_id' => $autor->id,
            'created_by' => $autor->id,
        ]);
    }

    private function makeEntidad(string $identificacion): Entidad
    {
        return Entidad::create([
            'nombre' => 'Test ' . $identificacion,
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => $identificacion,
            'estado' => 'Cliente',
        ]);
    }

    #[Test]
    public function only_mapped_comercial_receives(): void
    {
        $comercialRol = $this->makeRol('Comercial');
        $comercial = $this->makeUsuario('comercial@test.com', $comercialRol);
        $otherComercial = $this->makeUsuario('other@test.com', $comercialRol);

        $entidad = $this->makeEntidad('900111111');
        \DB::table('entidad_usuario')->insert([
            'entidad_id' => $entidad->id,
            'usuario_id' => $comercial->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $seguimiento = $this->makeSeguimiento($entidad->id);

        $recipients = $this->resolver->resolve($seguimiento);

        $this->assertCount(1, $recipients);
        $this->assertSame($comercial->id, $recipients->first()->id);
        $this->assertNotSame($otherComercial->id, $recipients->first()->id);
    }

    #[Test]
    public function multiple_comercials_all_receive(): void
    {
        $comercialRol = $this->makeRol('Comercial');
        $c1 = $this->makeUsuario('c1@test.com', $comercialRol);
        $c2 = $this->makeUsuario('c2@test.com', $comercialRol);

        $entidad = $this->makeEntidad('900222222');
        \DB::table('entidad_usuario')->insert([
            ['entidad_id' => $entidad->id, 'usuario_id' => $c1->id, 'created_at' => now(), 'updated_at' => now()],
            ['entidad_id' => $entidad->id, 'usuario_id' => $c2->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $seguimiento = $this->makeSeguimiento($entidad->id);

        $recipients = $this->resolver->resolve($seguimiento);

        $this->assertCount(2, $recipients);
    }

    #[Test]
    public function falls_back_to_admins_when_no_comercial(): void
    {
        $comercialRol = $this->makeRol('Comercial');
        $adminRol = $this->makeRol('Admin');
        $superAdminRol = $this->makeRol('SuperAdmin');

        $this->makeUsuario('unmapped@test.com', $comercialRol);
        $admin = $this->makeUsuario('admin@test.com', $adminRol);
        $superAdmin = $this->makeUsuario('super@test.com', $superAdminRol);

        $entidad = $this->makeEntidad('900333333');
        // No entidad_usuario row.

        $seguimiento = $this->makeSeguimiento($entidad->id);

        $recipients = $this->resolver->resolve($seguimiento);

        $this->assertCount(2, $recipients);
        $ids = $recipients->pluck('id')->sort()->values()->all();
        $this->assertSame([$admin->id, $superAdmin->id], $ids);
    }

    #[Test]
    public function returns_empty_when_no_comercial_and_no_admins(): void
    {
        $comercialRol = $this->makeRol('Comercial');
        $this->makeUsuario('c@test.com', $comercialRol);

        $entidad = $this->makeEntidad('900444444');

        $seguimiento = $this->makeSeguimiento($entidad->id);

        $recipients = $this->resolver->resolve($seguimiento);

        $this->assertCount(0, $recipients);
    }

    #[Test]
    public function inactive_comercial_is_excluded(): void
    {
        $comercialRol = $this->makeRol('Comercial');

        $active = $this->makeUsuario('active@test.com', $comercialRol, 'Activo');
        $inactive = $this->makeUsuario('inactive@test.com', $comercialRol, 'Inactivo');

        $entidad = $this->makeEntidad('900555555');
        \DB::table('entidad_usuario')->insert([
            ['entidad_id' => $entidad->id, 'usuario_id' => $active->id, 'created_at' => now(), 'updated_at' => now()],
            ['entidad_id' => $entidad->id, 'usuario_id' => $inactive->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $seguimiento = $this->makeSeguimiento($entidad->id);

        $recipients = $this->resolver->resolve($seguimiento);

        $this->assertCount(1, $recipients);
        $this->assertSame($active->id, $recipients->first()->id);
    }
}
