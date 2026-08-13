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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetalleOportunidadControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): string
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Admin User',
            'email' => 'admin@test.com',
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
            'codigo' => 'COT-000001',
            'entidad_id' => $entidad->id,
            'contacto_id' => $contacto->id,
            'fecha' => '2026-05-10',
            'estado' => 'Borrador',
        ]);
    }

    #[Test]
    public function it_lists_detalles_by_oportunidad(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/oportunidades/{$oportunidad->id}/detalles");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_detalle(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Consultoría ERP',
                'medida' => 'Srv',
                'cantidad' => 2,
                'vr_unitario' => 100000,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.producto_id', $producto->id)
            ->assertJsonPath('data.cantidad', 2)
            ->assertJsonPath('data.vr_unitario', 100000);

        // Auto-calc: vr_total = (2 * 100000) + (200000 * 0.19) = 200000 + 38000 = 238000
        $response->assertJsonPath('data.vr_total', 238000);
        $response->assertJsonPath('data.iva', 38000);
    }

    #[Test]
    public function it_shows_a_detalle(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Consultoría ERP',
                'medida' => 'Srv',
                'cantidad' => 1,
                'vr_unitario' => 50000,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/detalles-oportunidad/{$id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id);
    }

    #[Test]
    public function it_updates_a_detalle(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Consultoría ERP',
                'medida' => 'Srv',
                'cantidad' => 1,
                'vr_unitario' => 50000,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/detalles-oportunidad/{$id}", [
                'cantidad' => 3,
                'vr_unitario' => 50000,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cantidad', 3);
    }

    #[Test]
    public function it_deletes_a_detalle(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Test',
                'medida' => 'Und',
                'cantidad' => 1,
                'vr_unitario' => 10000,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/detalles-oportunidad/{$id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_nonexistent_detalle(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/detalles-oportunidad/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_handles_zero_iva_product(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 0]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Producto exento',
                'medida' => 'Und',
                'cantidad' => 5,
                'vr_unitario' => 10000,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.iva', 0)
            // vr_total = (5 * 10000) + 0 = 50000
            ->assertJsonPath('data.vr_total', 50000);
    }

    // ====================================================================
    // #14 — DetalleOportunidad PUT/DELETE proper HTTP semantics (PR-A2)
    // Spec scenarios from: Docs/changes/production-blockers-and-ux-fixes/specs/oportunidad/spec.md
    // Requirement 2: DetalleOportunidad PUT/DELETE use precise HTTP semantics
    // ====================================================================

    #[Test]
    public function test_put_updates_all_provided_fields(): void
    {
        // GIVEN: a detalle with cantidad=2, vr_unitario=1000, descripcion='old', notas=null
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Consultoría ERP',
                'medida' => 'Srv',
                'cantidad' => 2,
                'vr_unitario' => 1000,
                'descripcion' => 'old',
            ]);
        $id = $createResponse->json('data.id');

        // WHEN: client PUTs with updated fields including notas + descripcion + numeric changes
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/detalles-oportunidad/{$id}", [
                'cantidad' => 3,
                'vr_unitario' => 1000,
                'descripcion' => 'new',
                'notas' => 'urgent',
            ]);

        // THEN: 200 with all fields persisted
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cantidad', 3)
            ->assertJsonPath('data.vr_unitario', 1000)
            ->assertJsonPath('data.descripcion', 'new')
            ->assertJsonPath('data.notas', 'urgent');

        // AND: vr_total recalculated = (3 * 1000) + iva(19%) = 3000 + 570 = 3570
        $response->assertJsonPath('data.vr_total', 3570)
            ->assertJsonPath('data.iva', 570);

        // AND: DB row matches
        $this->assertDatabaseHas('detalle_oportunidad', [
            'id' => $id,
            'cantidad' => 3,
            'vr_unitario' => 1000,
            'descripcion' => 'new',
            'notas' => 'urgent',
        ]);
    }

    #[Test]
    public function test_put_preserves_totals_when_only_text_fields_change(): void
    {
        // GIVEN: a detalle with cantidad=2, vr_unitario=1000, vr_total=2380, iva=380
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Consultoría ERP',
                'medida' => 'Srv',
                'cantidad' => 2,
                'vr_unitario' => 1000,
                'descripcion' => 'follow up tomorrow',
            ]);
        $id = $createResponse->json('data.id');

        // Confirm initial totals
        $createResponse->assertJsonPath('data.vr_total', 2380)
            ->assertJsonPath('data.iva', 380);

        // WHEN: client PUTs ONLY notas (no numeric fields)
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/detalles-oportunidad/{$id}", [
                'notas' => 'client will confirm Friday',
            ]);

        // THEN: 200, notas updated, vr_total + iva UNCHANGED
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.notas', 'client will confirm Friday')
            ->assertJsonPath('data.vr_total', 2380)
            ->assertJsonPath('data.iva', 380);

        // AND: DB confirms totals unchanged
        $this->assertDatabaseHas('detalle_oportunidad', [
            'id' => $id,
            'vr_total' => 2380,
            'iva' => 380,
            'notas' => 'client will confirm Friday',
        ]);
    }

    #[Test]
    public function test_put_returns_404_for_nonexistent_detalle(): void
    {
        // GIVEN: no detalle exists with id=99999
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        // WHEN: client PUTs to non-existent id with valid payload
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/detalles-oportunidad/99999', [
                'producto_id' => $producto->id,
                'cantidad' => 1,
                'vr_unitario' => 1000,
            ]);

        // THEN: 404 with error body — NOT 500
        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Detalle no encontrado.');
    }

    #[Test]
    public function test_put_returns_422_for_missing_required_fields(): void
    {
        // GIVEN: an existing detalle
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Test',
                'medida' => 'Und',
                'cantidad' => 1,
                'vr_unitario' => 5000,
            ]);
        $id = $createResponse->json('data.id');
        $originalCantidad = $createResponse->json('data.cantidad');

        // WHEN: client PUTs with empty body (no fields)
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/detalles-oportunidad/{$id}", []);

        // THEN: 422 — but since PUT is nullable for numeric fields, the spec scenario
        // "PUT with missing required field returns 422" must be triggered by an
        // explicitly missing required field such as sending only invalid types.
        // For a PUT that doesn't touch required fields, Laravel accepts it as valid (partial update).
        // So we verify: still 200 OR 422 depending on PUT relaxation.
        // For this PR, PUT allows partial updates — only validation failure on bad types returns 422.
        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        // AND: original row NOT changed
        $this->assertDatabaseHas('detalle_oportunidad', [
            'id' => $id,
            'cantidad' => $originalCantidad,
        ]);
    }

    #[Test]
    public function test_delete_returns_200_and_soft_deletes(): void
    {
        // GIVEN: a detalle exists
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Test delete',
                'medida' => 'Und',
                'cantidad' => 1,
                'vr_unitario' => 10000,
            ]);
        $id = $createResponse->json('data.id');

        // WHEN: client DELETEs
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/detalles-oportunidad/{$id}");

        // THEN: 200 with body shape { success: true, data: { deleted: true, id: <id> } }
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.id', $id);

        // AND: row soft-deleted (deleted_at set)
        $this->assertSoftDeleted('detalle_oportunidad', [
            'id' => $id,
        ]);
    }

    #[Test]
    public function test_delete_returns_404_for_nonexistent_detalle(): void
    {
        // GIVEN: no detalle exists with id=99999
        $token = $this->authenticate();

        // WHEN: client DELETEs non-existent id
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/detalles-oportunidad/99999');

        // THEN: 404 with error body — NOT 500
        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Detalle no encontrado.');
    }

    #[Test]
    public function test_delete_returns_422_for_fk_constraint(): void
    {
        // GIVEN: a detalle exists + a delete is blocked by FK (mocked QueryException SQLSTATE 23000)
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Test FK',
                'medida' => 'Und',
                'cantidad' => 1,
                'vr_unitario' => 5000,
            ]);
        $id = $createResponse->json('data.id');

        // Mock the repository to throw a real FK constraint violation.
        // errorInfo[0]='23000' is the SQLSTATE for integrity constraint violation.
        $pdoException = new \PDOException(
            'SQLSTATE[23000]: Integrity constraint violation: 1451 Cannot delete or update a parent row'
        );
        $pdoException->errorInfo = ['23000', 1451, 'Cannot delete or update a parent row'];

        $mock = \Mockery::mock(\App\Domain\Repositories\DetalleOportunidadRepositoryInterface::class);
        $mock->shouldReceive('findById')
            ->with($id)
            ->andReturn((object) ['id' => $id, 'oportunidad_id' => $oportunidad->id]);
        $mock->shouldReceive('delete')
            ->with($id)
            ->andThrow(new \Illuminate\Database\QueryException(
                'mysql',
                'DELETE FROM detalle_oportunidad WHERE id = ?',
                [],
                $pdoException,
            ));
        $this->app->instance(\App\Domain\Repositories\DetalleOportunidadRepositoryInterface::class, $mock);

        // WHEN: client DELETEs
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/detalles-oportunidad/{$id}");

        // THEN: 422 with structured error identifying the FK block
        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        // AND: original row still exists (not soft-deleted)
        $this->assertDatabaseHas('detalle_oportunidad', [
            'id' => $id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function test_post_returns_201_and_persists_derived_totals(): void
    {
        // GIVEN: a valid POST payload
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        // WHEN: client POSTs
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'cantidad' => 2,
                'vr_unitario' => 1000,
                'iva' => 19,
            ]);

        // THEN: 201 with derived totals
        // vr_total = (2 * 1000) + (2000 * 0.19) = 2000 + 380 = 2380
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cantidad', 2)
            ->assertJsonPath('data.vr_unitario', 1000)
            ->assertJsonPath('data.iva', 380)
            ->assertJsonPath('data.vr_total', 2380);

        // AND: DB row matches
        $this->assertDatabaseHas('detalle_oportunidad', [
            'oportunidad_id' => $oportunidad->id,
            'cantidad' => 2,
            'vr_unitario' => 1000,
            'iva' => 380,
            'vr_total' => 2380,
        ]);
    }

    #[Test]
    public function test_post_returns_422_for_missing_required_fields(): void
    {
        // GIVEN: payload missing cantidad
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        // WHEN: client POSTs without cantidad
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'vr_unitario' => 1000,
            ]);

        // THEN: 422 with error naming 'cantidad'
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
        // The validation error must include 'cantidad' somewhere in the response
        $responseContent = json_encode($response->json());
        $this->assertStringContainsString('cantidad', $responseContent);

        // AND: no row inserted
        $this->assertDatabaseMissing('detalle_oportunidad', [
            'oportunidad_id' => $oportunidad->id,
        ]);
    }
}
