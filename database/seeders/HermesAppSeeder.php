<?php

namespace Database\Seeders;

use App\Models\App;
use App\Models\AppEntidad;
use App\Models\Entidad;
use Illuminate\Database\Seeder;

/**
 * PR-D (Phase 2): bind Hermes as an `apps` row + 5 `app_entidad` profile rows.
 *
 * The seeder is config-driven (`config/hermes.php`) and idempotent:
 *   - The hermes `apps` row is matched by `slug='hermes'` (unique).
 *   - Each profile's canonical brand entity is matched by `identificacion`
 *     via `firstOrCreate` (so the entity is created on the FIRST run and
 *     left untouched on subsequent runs).
 *   - Each `app_entidad` row is matched by the `(app_id, perfil)` pair via
 *     `updateOrCreate` (so re-running does NOT duplicate profile rows).
 *
 * This seeder does NOT touch any pre-existing `app_entidad` rows for OTHER
 * apps (REQ-HPBN-004): the match key for the pivot is `app_id=hermes.id`,
 * so legacy CRM/Sailus/Mercurio/etc. rows are invisible to it.
 *
 * See design.md AD-2 + §5.5 and spec REQ-HPBN-002, REQ-HPBN-003,
 * REQ-HPBN-004.
 */
class HermesAppSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Hermetic guard: bail out cleanly if the config is malformed.
        //    A misconfigured deployment MUST fail loudly, not silently skip.
        $appConfig = config('hermes.app');
        $profiles = config('hermes.profiles');

        if (! is_array($appConfig) || empty($appConfig['slug'])) {
            throw new \RuntimeException(
                'HermesAppSeeder: config("hermes.app") is missing or invalid.'
            );
        }

        if (! is_array($profiles) || count($profiles) !== 5) {
            throw new \RuntimeException(sprintf(
                'HermesAppSeeder: config("hermes.profiles") must contain exactly 5 entries, %d given.',
                is_array($profiles) ? count($profiles) : 0
            ));
        }

        // 2. Upsert the hermes apps row (REQ-HPBN-002).
        $hermesApp = App::updateOrCreate(
            ['slug' => $appConfig['slug']],
            $appConfig
        );

        // 3. For each profile, ensure the canonical brand entity exists,
        //    then upsert the app_entidad pivot row (REQ-HPBN-003).
        foreach ($profiles as $perfil => $entry) {
            if (! isset($entry['identificacion'], $entry['nombre'])) {
                throw new \RuntimeException(sprintf(
                    'HermesAppSeeder: profile "%s" is missing identificacion or nombre in config/hermes.php.',
                    (string) $perfil
                ));
            }

            $brandEntidad = Entidad::firstOrCreate(
                ['identificacion' => $entry['identificacion']],
                [
                    'tipo_persona' => 'Juridica',
                    'nombre' => $entry['nombre'],
                    'estado' => 'Propia',
                ]
            );

            AppEntidad::updateOrCreate(
                [
                    'app_id' => $hermesApp->id,
                    'perfil' => $perfil,
                ],
                [
                    'entidad_id' => $brandEntidad->id,
                    'estado' => 'Activo',
                    'fecha_contrato' => now()->toDateString(),
                ]
            );
        }
    }
}
