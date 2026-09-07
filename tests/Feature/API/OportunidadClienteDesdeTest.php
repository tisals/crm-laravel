<?php

namespace Tests\Feature\API;

use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OportunidadClienteDesdeTest extends TestCase
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
        ]);

        return $usuario->createToken('test-token')->plainTextToken;
    }

    /**
     * Read the open `cliente` pivot row's `effective_from` for the
     * given entidad (Commit 5.5 — `entidad.cliente_desde` is gone).
     * Returns null when there is no open cliente pivot row.
     */
    private function clienteDesde(int $entidadId): ?string
    {
        return DB::table('entidad_relacion')
            ->where('entidad_id', $entidadId)
            ->where('tipo_relacion', 'cliente')
            ->whereNull('effective_to')
            ->value('effective_from');
    }

    #[Test]
    public function cliente_desde_set_when_first_opp_won(): void
    {
        $token = $this->authenticate();
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        // Create opp in Aceptada state
        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'fecha' => now()->format('Y-m-d'),
                'estado' => 'Aceptada',
            ]);
        $id = $createResponse->json('data.id');

        // Mark as Ganada
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id, [
                'estado' => 'Ganada',
            ]);

        // Verify cliente pivot row was opened
        $this->assertNotNull(
            $this->clienteDesde($entidad->id),
            'cliente pivot row should be opened when first opp is won'
        );
    }

    #[Test]
    public function cliente_desde_not_overwritten_on_second_win(): void
    {
        $token = $this->authenticate();
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        // First opp won
        $r1 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'fecha' => now()->format('Y-m-d'),
                'estado' => 'Aceptada',
            ]);
        $id1 = $r1->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id1, ['estado' => 'Ganada']);

        $originalClienteDesde = $this->clienteDesde($entidad->id);

        // Small delay to ensure different timestamp
        sleep(1);

        // Second opp won for same entity
        $r2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'fecha' => now()->format('Y-m-d'),
                'estado' => 'Aceptada',
            ]);
        $id2 = $r2->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id2, ['estado' => 'Ganada']);

        $this->assertEquals(
            $originalClienteDesde,
            $this->clienteDesde($entidad->id),
            'cliente pivot row effective_from should NOT be overwritten on second win'
        );
    }

    #[Test]
    public function cliente_desde_cleared_when_opp_removed_from_ganada(): void
    {
        $token = $this->authenticate();
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        // Win an opp
        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'fecha' => now()->format('Y-m-d'),
                'estado' => 'Aceptada',
            ]);
        $id = $r->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id, ['estado' => 'Ganada']);

        $this->assertNotNull($this->clienteDesde($entidad->id));

        // Change estado from Ganada to something else
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id, ['estado' => 'Perdida']);

        $this->assertNull(
            $this->clienteDesde($entidad->id),
            'cliente pivot row should be closed when opp leaves Ganada state'
        );
    }
}
