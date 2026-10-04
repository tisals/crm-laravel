<?php

namespace Database\Seeders;

use App\Models\App;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed the `app_entidad` pivot: which entities contract which apps.
 *
 * Without this, `app_entidad` is empty after seed, which means:
 *   - /me/identity always returns apps=0 for non-admin users (no entity
 *     has any contracted app to walk through)
 *   - The rol_id=1 admin bypass still returns the catalog, but with
 *     entidades_count=0 for every app (no contracted-by relationships)
 *
 * We seed a minimal but representative set:
 *   - Each of the 7 catalog apps contracted by the 2 internal entities
 *     (Tecnoinnsoft, Desecurity) — these cover the admin use case
 *   - Each of the 7 apps also contracted by a handful of client entities
 *     — these cover the non-admin use case
 *   - Mix of estados (Activo + Suspendido + Trial) to exercise filtering
 *
 * Idempotent via updateOrCreate on (app_id, entidad_id) — re-running
 * the seeder won't duplicate rows.
 */
class AppEntidadSeeder extends Seeder
{
    public function run(): void
    {
        // Internal entities that the 5 internal users belong to (per
        // the multi-tenant persona refactor and the backfill migration
        // in iter4-persona-tracker). Use a fixed list so the seed is
        // deterministic regardless of CSV row ordering.
        $homeEntities = [
            128    => 'Tecnoinnsoft SAS BIC',
            2476   => 'Desecurity.net',
        ];

        // Sample client entities to seed contracts against. Picked from
        // the RealDataSeeder's typical output (id=1, 2 are the marca
        // propia entities seeded by BrandPermissionsSeeder).
        $clientEntities = [1, 2];

        $now = now();

        // 1. All 7 apps contracted by the 2 home entities (Activo).
        $apps = App::whereNull('deleted_at')->orderBy('id')->get();
        foreach ($apps as $app) {
            foreach ($homeEntities as $entityId => $entityName) {
                DB::table('app_entidad')->updateOrInsert(
                    ['app_id' => $app->id, 'entidad_id' => $entityId],
                    [
                        'estado' => 'Activo',
                        'fecha_contrato' => $now->copy()->subMonths(rand(1, 12))->toDateString(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }

        // 2. A subset contracted by clients, mixed estados (so filters
        // like `app_entidad.estado = 'Activo'` actually do something).
        $clientContractPlan = [
            // app_slug  => [entity_id => estado]
            'crm'        => [1 => 'Activo', 2 => 'Trial'],
            'sailus'     => [1 => 'Activo'],
            'mercurio'   => [1 => 'Activo', 2 => 'Suspendido'],
            'marketing'  => [2 => 'Activo'],
            'wp-plugin'  => [],
            'la-llave'   => [1 => 'Suspendido'],
            'brp'        => [2 => 'Activo'],
        ];

        foreach ($clientContractPlan as $appSlug => $perEntity) {
            $app = $apps->firstWhere('slug', $appSlug);
            if (!$app) {
                continue;
            }
            foreach ($perEntity as $entityId => $estado) {
                DB::table('app_entidad')->updateOrInsert(
                    ['app_id' => $app->id, 'entidad_id' => $entityId],
                    [
                        'estado' => $estado,
                        'fecha_contrato' => $now->copy()->subMonths(rand(1, 6))->toDateString(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }
}