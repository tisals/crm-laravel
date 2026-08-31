<?php

namespace Database\Seeders;

use App\Models\App;
use App\Models\AppEntidad;
use App\Models\Entidad;
use Illuminate\Database\Seeder;

/**
 * PR-D (Phase 2): bind SAIlus Agent as an `apps` row + 5 `app_entidad`
 * profile rows. (Renamed from HermesAppSeeder on 2026-08-28 — see
 * `config/sailus.php` header for the brand rename history.)
 *
 * The seeder is config-driven (`config/sailus.php`) and idempotent:
 *   - The SAIlus Agent `apps` row is matched by `slug='sailus'` (unique).
 *   - Each profile's canonical brand entity is matched by `identificacion`
 *     via `firstOrCreate` (so the entity is created on the FIRST run and
 *     left untouched on subsequent runs).
 *   - Each `app_entidad` row is matched by the `(app_id, perfil)` pair via
 *     `updateOrCreate` (so re-running does NOT duplicate profile rows).
 *
 * This seeder does NOT touch any pre-existing `app_entidad` rows for OTHER
 * apps (REQ-HPBN-004): the match key for the pivot is `app_id=sailus.id`,
 * so legacy CRM/Mercurio/etc. rows are invisible to it.
 *
 * See design.md AD-2 + §5.5 and spec REQ-HPBN-002, REQ-HPBN-003,
 * REQ-HPBN-004.
 */
class SailusAgentSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Guard: bail out cleanly if the config is malformed.
        //    A misconfigured deployment MUST fail loudly, not silently skip.
        $appConfig = config('sailus.app');
        $profiles = config('sailus.profiles');

        if (! is_array($appConfig) || empty($appConfig['slug'])) {
            throw new \RuntimeException(
                'SailusAgentSeeder: config("sailus.app") is missing or invalid.'
            );
        }

        if (! is_array($profiles) || count($profiles) !== 5) {
            throw new \RuntimeException(sprintf(
                'SailusAgentSeeder: config("sailus.profiles") must contain exactly 5 entries, %d given.',
                is_array($profiles) ? count($profiles) : 0
            ));
        }

        // 2. Upsert the SAIlus Agent apps row (REQ-HPBN-002).
        $sailusApp = App::updateOrCreate(
            ['slug' => $appConfig['slug']],
            $appConfig
        );

        // 3. For each profile, ensure the canonical brand entity exists,
        //    then upsert the app_entidad pivot row (REQ-HPBN-003).
        foreach ($profiles as $perfil => $entry) {
            if (! isset($entry['identificacion'], $entry['nombre'])) {
                throw new \RuntimeException(sprintf(
                    'SailusAgentSeeder: profile "%s" is missing identificacion or nombre in config/sailus.php.',
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
                    'app_id' => $sailusApp->id,
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
