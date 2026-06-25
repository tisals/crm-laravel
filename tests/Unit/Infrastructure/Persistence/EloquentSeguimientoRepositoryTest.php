<?php

namespace Tests\Unit\Infrastructure\Persistence;

use App\Domain\Repositories\SeguimientoRepositoryInterface;
use App\Models\Entidad;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\Seguimiento;
use Modules\Shared\Models\Usuario;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-BE-13: New repo methods scope by user role.
 *
 * - findForUser(): Comercial sees only entities mapped via entidad_usuario.
 *                    Admin/SuperAdmin see all.
 * - findCalendarForUser(): same scope, plus date range filter, default
 *                          estado=Pendiente.
 */
class EloquentSeguimientoRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private SeguimientoRepositoryInterface $repo;

    /** @var int Per-test counter for unique nit values within the same test. */
    private int $seq;
    private Usuario $autor;
    private int $autorRol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(SeguimientoRepositoryInterface::class);
        $this->seq = 0;
        // Create a single autor user reused across seguimientos in this test.
        $this->autorRol = $this->makeRol('Autor');
        $this->autor = $this->makeUsuario('autor@test.com', $this->autorRol);
    }

    private function makeRol(string $nombre): int
    {
        return Rol::firstOrCreate(['nombre' => $nombre], ['estado' => 'Activo'])->id;
    }

    private function makeUsuario(string $email, int $rolId): Usuario
    {
        return Usuario::create([
            'nombre' => $email,
            'email' => $email,
            'password_hash' => bcrypt('password'),
            'rol_id' => $rolId,
            'estado' => 'Activo',
        ]);
    }

    private function makeEntidad(string $nit): Entidad
    {
        return Entidad::create([
            'nombre' => 'Corp ' . $nit,
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => $nit,
            'estado' => 'Cliente',
        ]);
    }

    private function makeSeguimiento(int $entidadId, string $fecha, string $estado = 'Pendiente', string $notas = 'test'): Seguimiento
    {
        $this->seq++;

        return Seguimiento::create([
            'entidad_id' => $entidadId,
            'tipo' => 'Llamada',
            'fecha' => $fecha,
            'estado' => $estado,
            'notas' => $notas,
            'autor_id' => $this->autor->id,
            'created_by' => $this->autor->id,
        ]);
    }

    private function mapComercial(int $userId, int $entidadId): void
    {
        DB::table('entidad_usuario')->insert([
            'entidad_id' => $entidadId,
            'usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function find_for_user_as_comercial_returns_only_mapped_entities(): void
    {
        $comercialRol = $this->makeRol('Comercial');
        $comercial = $this->makeUsuario('c@test.com', $comercialRol);

        $e1 = $this->makeEntidad('900111111');
        $e2 = $this->makeEntidad('900222222');
        $e3 = $this->makeEntidad('900333333');

        // Map ONLY e1 and e2 to the comercial
        $this->mapComercial($comercial->id, $e1->id);
        $this->mapComercial($comercial->id, $e2->id);
        // e3 is NOT mapped

        $this->makeSeguimiento($e1->id, '2026-06-15', 'Pendiente', 'mapped 1');
        $this->makeSeguimiento($e2->id, '2026-06-16', 'Pendiente', 'mapped 2');
        $this->makeSeguimiento($e3->id, '2026-06-17', 'Pendiente', 'unmapped');

        $result = $this->repo->findForUser($comercial->id, 50);

        $this->assertSame(2, $result->total());
        $notas = $result->getCollection()->pluck('notas')->sort()->values()->all();
        $this->assertSame(['mapped 1', 'mapped 2'], $notas);
    }

    #[Test]
    public function find_for_user_as_admin_returns_all(): void
    {
        $adminRol = $this->makeRol('Admin');
        $admin = $this->makeUsuario('admin@test.com', $adminRol);

        $e1 = $this->makeEntidad('900111111');
        $e2 = $this->makeEntidad('900222222');

        $this->makeSeguimiento($e1->id, '2026-06-15', 'Pendiente', 'a');
        $this->makeSeguimiento($e2->id, '2026-06-16', 'Pendiente', 'b');

        $result = $this->repo->findForUser($admin->id, 50);

        $this->assertSame(2, $result->total());
    }

    #[Test]
    public function find_for_user_filters_compose(): void
    {
        $comercialRol = $this->makeRol('Comercial');
        $comercial = $this->makeUsuario('c@test.com', $comercialRol);
        $e = $this->makeEntidad('900111111');
        $this->mapComercial($comercial->id, $e->id);

        $this->makeSeguimiento($e->id, '2026-06-15', 'Pendiente', 'pendiente');
        $this->makeSeguimiento($e->id, '2026-06-15', 'Completado', 'completado');

        $result = $this->repo->findForUser($comercial->id, 50, null, ['estado' => 'Pendiente']);

        $this->assertSame(1, $result->total());
        $this->assertSame('pendiente', $result->getCollection()->first()->notas);
    }

    #[Test]
    public function find_calendar_for_user_filters_by_date_range(): void
    {
        $adminRol = $this->makeRol('Admin');
        $admin = $this->makeUsuario('admin@test.com', $adminRol);

        $e = $this->makeEntidad('900111111');
        $this->makeSeguimiento($e->id, '2026-06-10', 'Pendiente', 'june 10');
        $this->makeSeguimiento($e->id, '2026-06-20', 'Pendiente', 'june 20');
        $this->makeSeguimiento($e->id, '2026-07-01', 'Pendiente', 'july 1');

        $result = $this->repo->findCalendarForUser($admin->id, '2026-06-01', '2026-06-30');

        $this->assertCount(2, $result);
        $notas = $result->pluck('notas')->sort()->values()->all();
        $this->assertSame(['june 10', 'june 20'], $notas);
    }

    #[Test]
    public function find_calendar_excludes_completado_by_default(): void
    {
        $adminRol = $this->makeRol('Admin');
        $admin = $this->makeUsuario('admin@test.com', $adminRol);
        $e = $this->makeEntidad('900111111');

        $this->makeSeguimiento($e->id, '2026-06-15', 'Pendiente', 'p');
        $this->makeSeguimiento($e->id, '2026-06-16', 'Completado', 'c');

        $result = $this->repo->findCalendarForUser($admin->id, '2026-06-01', '2026-06-30');

        $this->assertCount(1, $result);
        $this->assertSame('p', $result->first()->notas);
    }
}
