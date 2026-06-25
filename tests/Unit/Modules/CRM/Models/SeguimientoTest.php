<?php

namespace Tests\Unit\Modules\CRM\Models;

use App\Models\Entidad;
use App\Models\Oportunidad;
use App\Models\Seguimiento as LegacyAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CRM\Models\Seguimiento as CanonicalSeguimiento;
use Modules\Shared\Models\Usuario;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for the canonical Seguimiento model at `Modules\CRM\Models\Seguimiento`.
 *
 * The legacy alias `App\Models\Seguimiento` MUST continue to work.
 */
class SeguimientoTest extends TestCase
{
    use RefreshDatabase;

    private function makeUsuario(string $email): Usuario
    {
        // RefreshDatabase creates a clean schema but does NOT seed roles.
        // Ensure a rol exists so the FK on usuarios.rol_id is satisfied.
        \App\Models\Rol::firstOrCreate(
            ['nombre' => 'Admin'],
            ['estado' => 'Activo']
        );
        $rolId = \App\Models\Rol::where('nombre', 'Admin')->value('id');

        return Usuario::create([
            'nombre' => 'Test User',
            'email' => $email,
            'password_hash' => bcrypt('password'),
            'rol_id' => $rolId,
            'estado' => 'Activo',
        ]);
    }

    #[Test]
    public function canonical_model_class_exists(): void
    {
        $this->assertTrue(class_exists(CanonicalSeguimiento::class));
    }

    #[Test]
    public function legacy_alias_class_exists_and_extends_canonical(): void
    {
        $this->assertTrue(class_exists(LegacyAlias::class));
        $this->assertInstanceOf(CanonicalSeguimiento::class, new LegacyAlias);
    }

    #[Test]
    public function table_name_is_seguimiento(): void
    {
        $instance = new CanonicalSeguimiento;
        $this->assertSame('seguimiento', $instance->getTable());
    }

    #[Test]
    public function fillable_includes_all_required_fields(): void
    {
        $expected = [
            'oportunidad_id', 'contacto_id', 'entidad_id',
            'tipo', 'fecha', 'hora', 'fecha_fin',
            'notas', 'autor_id',
            'estado', 'created_by', 'updated_by',
        ];
        $instance = new CanonicalSeguimiento;
        $this->assertSame($expected, $instance->getFillable());
    }

    #[Test]
    public function relations_load_correctly(): void
    {
        // Create the related models
        $entidad = Entidad::create([
            'nombre' => 'Test Corp',
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900999999',
            'estado' => 'Cliente',
        ]);
        $autor = $this->makeUsuario('autor@test.com');

        $opp = Oportunidad::create([
            'codigo' => 'TEST-SEGUIMIENTO-001',
            'entidad_id' => $entidad->id,
            'fecha' => '2026-06-15',
            'estado' => 'Activa',
        ]);

        $seg = CanonicalSeguimiento::create([
            'oportunidad_id' => $opp->id,
            'entidad_id' => $entidad->id,
            'autor_id' => $autor->id,
            'tipo' => 'Llamada',
            'fecha' => '2026-06-15',
            'notas' => 'Test seguimiento',
            'estado' => 'Pendiente',
        ]);

        // Eager load all relations
        $loaded = CanonicalSeguimiento::with(['oportunidad', 'entidad', 'autor'])->find($seg->id);

        $this->assertNotNull($loaded);
        $this->assertNotNull($loaded->oportunidad);
        $this->assertSame($opp->id, $loaded->oportunidad->id);
        $this->assertSame($entidad->id, $loaded->entidad->id);
        $this->assertNotNull($loaded->autor);
        $this->assertSame($autor->id, $loaded->autor->id);
    }

    #[Test]
    public function fecha_is_cast_to_carbon_date(): void
    {
        $seg = new CanonicalSeguimiento(['fecha' => '2026-06-15']);
        // Without save, the cast may or may not apply. Verify via a fresh find.
        $instance = new CanonicalSeguimiento;
        $this->assertArrayHasKey('fecha', $instance->getCasts());
        $this->assertSame('date', $instance->getCasts()['fecha']);
    }

    #[Test]
    public function soft_deletes_are_enabled(): void
    {
        $instance = new CanonicalSeguimiento;
        $this->assertContains(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($instance));
    }
}
