<?php

/*
|--------------------------------------------------------------------------
| Hermes Agent Platform — Profile Configuration
|--------------------------------------------------------------------------
|
| Hermes is the customer-facing AI-agent platform consumed by entities
| (Tecnoinnsoft clients). It exposes a per-profile configuration: each
| "perfil" (agent persona) is owned by a canonical brand entity. This
| config drives `database/seeders/HermesAppSeeder.php` (PR-D) and is the
| single source of truth for which 5 profiles ship with Hermes.
|
| The mapping rules are:
|   - 'identificacion' is the FK target used by `Entidad::firstOrCreate`
|     (so re-running the seeder does NOT duplicate rows).
|   - 'nombre' is only used on the FIRST run when the entidad is created;
|     subsequent runs match by identificacion and leave the existing row alone.
|   - The 5 slug keys are the EXACT allow-list enforced by
|     `App\Models\AppEntidad::PERFILES_ACEPTADOS` and by the
|     `perfil` validation rule in `AppController::assignAppToEntidad`.
|     Changing the keys here MUST be mirrored there.
|
| `auto_seed` controls whether `DatabaseSeeder` invokes the HermesAppSeeder
| on `php artisan db:seed`. Default false to keep production migrations
| lean — HermesAppSeeder is an opt-in profile bootstrap, not a baseline.
|
| See design.md AD-2, OI-3, §5.5 and spec REQ-HPBN-002 / REQ-HPBN-003.
|
*/

return [
    /*
     * Whether `DatabaseSeeder` should automatically invoke HermesAppSeeder.
     * Default false (Hermes profile bootstrap is opt-in). Local/dev/seeder
     * workflows can flip this to true.
     */
    'auto_seed' => (bool) env('HERMES_AUTO_SEED', false),

    /*
     * The canonical Hermes app record attributes (REQ-HPBN-002).
     * `slug='hermes'` is the lookup key used everywhere (seeder,
     * /api/v1/me/apps, RBAC).
     */
    'app' => [
        'slug' => 'hermes',
        'nombre' => 'Hermes Agent Platform',
        'tipo' => 'customer',
        'auth_type' => 'sanctum',
        'activo' => true,
        'descripcion' => 'Plataforma de agentes IA para setters, marketing y soporte SST.',
    ],

    /*
     * Hermes profile slugs → canonical brand entity.
     *
     * Each entry seeds one `app_entidad` row with `app_id=hermes`,
     * `perfil=<slug>`, `entidad_id=<brand entity>`, `estado='Activo'`.
     *
     * The 5 slug keys are the contract; do not rename without also
     * updating `App\Models\AppEntidad::PERFILES_ACEPTADOS` and the
     * Form Request validation rule.
     */
    'profiles' => [
        'setter-safe-health' => [
            'identificacion' => 'HERMES-SETTER-SAFE-HEALTH',
            'nombre' => 'Safe Health Colombia — Hermes Setter',
        ],
        'setter-tis' => [
            'identificacion' => 'HERMES-SETTER-TIS',
            'nombre' => 'TIS — Hermes Setter',
        ],
        'setter-alejandro' => [
            'identificacion' => 'HERMES-SETTER-ALEJANDRO',
            'nombre' => 'Alejandro — Hermes Setter',
        ],
        'marketing-sailus' => [
            'identificacion' => 'HERMES-MARKETING-SAILUS',
            'nombre' => 'SAIlus — Hermes Marketing',
        ],
        'sst-support-safe-health' => [
            'identificacion' => 'HERMES-SST-SAFE-HEALTH',
            'nombre' => 'Safe Health — Hermes SST Support',
        ],
    ],
];
