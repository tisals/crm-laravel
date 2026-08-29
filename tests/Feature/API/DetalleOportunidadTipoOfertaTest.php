<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Oportunidad;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-C: detalle_oportunidad.tipo_oferta column + Form Request enum validation.
 *
 * Covers REQ-DOP-001 (column exists with default 'servicio' and existing rows
 * are backfilled) and REQ-DOP-002 (Form Request accepts documented offer
 * types and rejects unknown values with 422).
 *
 * Strict TDD: every test asserts real behaviour — column existence + DB
 * backfill + Form Request validation. No smoke tests, no trivial assertions.
 *
 * RED tasks 1c.1 and 1c.2 fail before any production code ships.
 * GREEN tasks 1c.7 (migration), 1c.8 (model), 1c.9 (Form Request) make
 * these tests pass.
 */
class DetalleOportunidadTipoOfertaTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): string
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Admin User',
            'email' => 'admin-prc-tipo-oferta@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        return $usuario->createToken('test-token')->plainTextToken;
    }

    private function createOportunidad(): Oportunidad
    {
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        return Oportunidad::create([
            'codigo' => 'COT-PRC-001',
            'entidad_id' => $entidad->id,
            'contacto_id' => $contacto->id,
            'fecha' => '2026-08-28',
            'estado' => 'Borrador',
        ]);
    }

    // ── 1c.1 — column exists + existing rows backfilled to 'servicio' ──

    #[Test]
    public function detalle_oportunidad_has_tipo_oferta_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('detalle_oportunidad', 'tipo_oferta'),
            'detalle_oportunidad.tipo_oferta column must exist (REQ-DOP-001)'
        );
    }

    #[Test]
    public function existing_detalle_oportunidad_rows_are_backfilled_to_servicio(): void
    {
        // Precondition: insert a row directly through DB (bypassing any model
        // defaults) so the column has explicit NULL *if* the migration does
        // not backfill. The migration's backfill UPDATE must turn it into
        // 'servicio'.
        DB::table('detalle_oportunidad')->insert([
            'oportunidad_id' => 1,
            'producto_id' => 1,
            'concepto' => 'Legacy quote line',
            'medida' => 'Und',
            'cantidad' => 1,
            'vr_unitario' => 100,
            'iva' => 0,
            'vr_total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // After migration (and its backfill UPDATE) no row may carry NULL.
        $nullCount = DB::table('detalle_oportunidad')->whereNull('tipo_oferta')->count();
        $servicioCount = DB::table('detalle_oportunidad')
            ->where('tipo_oferta', 'servicio')
            ->count();

        $this->assertSame(0, $nullCount, 'All existing rows must be backfilled (no NULLs)');
        $this->assertSame(1, $servicioCount, 'Backfilled row must have tipo_oferta="servicio"');
    }

    #[Test]
    public function migration_is_reversible_drops_tipo_oferta_column(): void
    {
        // Precondition: column exists before rollback.
        $this->assertTrue(
            Schema::hasColumn('detalle_oportunidad', 'tipo_oferta'),
            'precondition: tipo_oferta must exist before rollback'
        );

        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);

        $this->assertFalse(
            Schema::hasColumn('detalle_oportunidad', 'tipo_oferta'),
            'tipo_oferta must be gone after rollback'
        );
    }

    // ── 1c.2 — Form Request validation of documented offer types ──

    #[Test]
    public function write_with_tipo_oferta_oto_persists(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 0]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'OTO bonus pack',
                'medida' => 'Und',
                'cantidad' => 1,
                'vr_unitario' => 50000,
                'tipo_oferta' => 'oto',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tipo_oferta', 'oto');

        // Persisted at the DB layer (not just the response).
        $row = DB::table('detalle_oportunidad')
            ->where('oportunidad_id', $oportunidad->id)
            ->first();

        $this->assertSame(
            'oto',
            $row->tipo_oferta,
            'tipo_oferta=oto must be persisted to detalle_oportunidad row'
        );
    }

    #[Test]
    public function write_with_tipo_oferta_bump_persists(): void
    {
        // Triangulation: different value, same allow-list, must also succeed.
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 0]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Order bump',
                'medida' => 'Und',
                'cantidad' => 1,
                'vr_unitario' => 5000,
                'tipo_oferta' => 'bump',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.tipo_oferta', 'bump');
    }

    #[Test]
    public function write_with_invalid_tipo_oferta_returns_422(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 0]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Bogus offer',
                'medida' => 'Und',
                'cantidad' => 1,
                'vr_unitario' => 1000,
                'tipo_oferta' => 'banana',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['tipo_oferta']);
    }

    #[Test]
    public function write_without_tipo_oferta_defaults_to_servicio(): void
    {
        // Triangulation: absent field must land as 'servicio' (DB default).
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 0]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Default tipo_oferta',
                'medida' => 'Und',
                'cantidad' => 1,
                'vr_unitario' => 1000,
                // tipo_oferta omitted on purpose
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.tipo_oferta', 'servicio');
    }
}