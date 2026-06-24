<?php

namespace Tests\Feature\Seeders;

use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Oportunidad;
use Database\Seeders\ContactoCsvSeeder;
use Database\Seeders\OportunidadCsvSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DOD (Definition of Done): no entity should ever have more than 10
 * opportunities and 10 contacts. Older rows are truncated.
 */
class DodTruncateTest extends TestCase
{
    use RefreshDatabase;

    private function makeEntidad(string $nombre): Entidad
    {
        return Entidad::create([
            'nombre' => $nombre,
            'nombre_comercial' => $nombre,
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900'.random_int(100000, 999999),
            'estado' => 'Cliente',
        ]);
    }

    private function makeOportunidad(Entidad $entidad, string $fecha, string $codigo): void
    {
        DB::table('oportunidad')->insert([
            'codigo' => $codigo,
            'entidad_id' => $entidad->id,
            'fecha' => $fecha,
            'estado' => 'Enviada',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeContacto(Entidad $entidad, string $email): void
    {
        DB::table('contacto')->insert([
            'entidad_id' => $entidad->id,
            'email_contacto' => $email,
            'nombres' => 'Test',
            'apellidos' => 'User',
            'estado' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function oportunidad_seeder_truncates_to_10_oldest_removed(): void
    {
        $entidad = $this->makeEntidad('Test Corp 15ops');
        $entidadId = $entidad->id;

        // 15 oportunidades escalonadas día a día (1 al 15 de enero 2026)
        // La más antigua es OP-001 (2026-01-01), la más reciente es OP-015 (2026-01-15)
        for ($i = 1; $i <= 15; $i++) {
            $this->makeOportunidad(
                $entidad,
                sprintf('2026-01-%02d', $i),
                sprintf('OP-%03d', $i)
            );
        }

        $this->assertSame(15, Oportunidad::where('entidad_id', $entidadId)->count());

        $seeder = new OportunidadCsvSeeder;
        $stats = $seeder->applyDodCap(maxOps: 10, maxContactos: 10);

        // Verify count is now 10
        $this->assertSame(10, Oportunidad::where('entidad_id', $entidadId)->count());

        // Verify the 5 OLDEST are gone (OP-001..OP-005)
        $remainingCodigos = Oportunidad::where('entidad_id', $entidadId)
            ->orderBy('codigo')
            ->pluck('codigo')
            ->all();
        $this->assertSame(
            ['OP-006', 'OP-007', 'OP-008', 'OP-009', 'OP-010', 'OP-011', 'OP-012', 'OP-013', 'OP-014', 'OP-015'],
            $remainingCodigos
        );

        // Verify stats report
        $this->assertSame(5, $stats['oportunidades_eliminadas'] ?? null);
    }

    #[Test]
    public function oportunidad_seeder_keeps_all_when_under_cap(): void
    {
        $entidad = $this->makeEntidad('Test Corp 7ops');
        for ($i = 1; $i <= 7; $i++) {
            $this->makeOportunidad($entidad, '2026-06-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), sprintf('KEEP-%03d', $i));
        }

        $seeder = new OportunidadCsvSeeder;
        $seeder->applyDodCap(maxOps: 10, maxContactos: 10);

        $this->assertSame(7, Oportunidad::where('entidad_id', $entidad->id)->count());
    }

    #[Test]
    public function contacto_seeder_truncates_to_10_oldest_removed(): void
    {
        $entidad = $this->makeEntidad('Test Corp 13contactos');
        for ($i = 1; $i <= 13; $i++) {
            $this->makeContacto($entidad, sprintf('user%02d@test.com', $i));
        }

        $this->assertSame(13, Contacto::where('entidad_id', $entidad->id)->count());

        $seeder = new ContactoCsvSeeder;
        $stats = $seeder->applyDodCap(maxOps: 10, maxContactos: 10);

        $this->assertSame(10, Contacto::where('entidad_id', $entidad->id)->count());
        $this->assertSame(3, $stats['contactos_eliminados'] ?? null);
    }

    #[Test]
    public function apply_dod_cap_enforces_per_entity_independently(): void
    {
        $e1 = $this->makeEntidad('Corp A 12ops');
        $e2 = $this->makeEntidad('Corp B 3ops');
        $e3 = $this->makeEntidad('Corp C 15ops');

        for ($i = 1; $i <= 12; $i++) {
            $this->makeOportunidad($e1, '2026-01-01', sprintf('A-%03d', $i));
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->makeOportunidad($e2, '2026-01-01', sprintf('B-%03d', $i));
        }
        for ($i = 1; $i <= 15; $i++) {
            $this->makeOportunidad($e3, '2026-01-01', sprintf('C-%03d', $i));
        }

        (new OportunidadCsvSeeder)->applyDodCap(maxOps: 10, maxContactos: 10);

        $this->assertSame(10, Oportunidad::where('entidad_id', $e1->id)->count());
        $this->assertSame(3, Oportunidad::where('entidad_id', $e2->id)->count());
        $this->assertSame(10, Oportunidad::where('entidad_id', $e3->id)->count());
    }
}
