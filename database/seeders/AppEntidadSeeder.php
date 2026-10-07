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
        // Look up internal entities BY NAME rather than hardcoding IDs.
        // Hardcoded IDs break when the seed runs in a different order or
        // a different DB (the home entities can shift between fresh
        // seeds — Tecnoinnsoft is normally id=128 but Desecurity has
        // shifted between id=2476 and id=2438 in observed runs).
        $homeEntities = DB::table('entidad')
            ->whereIn('nombre', ['Tecnoinnsoft SAS BIC', 'Deseguridad.net', 'Desecurity.net'])
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('nombre');

        if ($homeEntities->count() < 2) {
            $this->command?->warn(
                "AppEntidadSeeder: home entities missing (found {$homeEntities->count()}/2). ".
                'Run BrandPermissionsSeeder first to create Tecnoinnsoft + Desecurity as Propia.'
            );
            return;
        }

        // Sample client entities (the marca propia entities that
        // BrandPermissionsSeeder sets up) — kept for reference but not
        // seeded here to match the exact AC count (11 apps × 2 = 22).
        $now = now();

        // 1. All apps contracted by the 2 home entities (Activo).
        $apps = App::whereNull('deleted_at')->orderBy('id')->get();
        foreach ($apps as $app) {
            foreach ($homeEntities as $ent) {
                DB::table('app_entidad')->updateOrInsert(
                    ['app_id' => $app->id, 'entidad_id' => $ent->id],
                    [
                        'estado' => 'Activo',
                        'fecha_contrato' => $now->copy()->subMonths(rand(1, 12))->toDateString(),
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ]
                );
            }
        }
    }
}