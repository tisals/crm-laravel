<?php

/*
|--------------------------------------------------------------------------
| SAIlus Agent Platform — Profile Configuration
|--------------------------------------------------------------------------
|
| SAIlus Agent (slug `sailus`, formerly branded "Hermes") is the
    customer-facing AI-agent platform consumed by entities (Tecnoinnsoft
    clients). It exposes a per-profile configuration: each "perfil"
    (agent persona) is owned by a canonical brand entity. This config
    drives `database/seeders/SailusAgentSeeder.php` (formerly
    HermesAppSeeder, PR-D) and is the single source of truth for which
    5 profiles ship with SAIlus Agent.
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
| `auto_seed` controls whether `DatabaseSeeder` invokes the SailusAgentSeeder
| on `php artisan db:seed`. Default false to keep production migrations
| lean — SailusAgentSeeder is an opt-in profile bootstrap, not a baseline.
|
| Brand rename history:
|   - 2026-08-28: rebrand Hermes → SAIlus Agent (config moved from
|     `config/hermes.php` to `config/sailus.php`, slug `hermes` → `sailus`).
|
| See design.md AD-2, OI-3, §5.5 and spec REQ-HPBN-002 / REQ-HPBN-003.
|
*/

return [
    /*
     * Whether `DatabaseSeeder` should automatically invoke SailusAgentSeeder.
     * Default false (SAIlus Agent profile bootstrap is opt-in).
     * Local/dev/seeder workflows can flip this to true via `SAILUS_AUTO_SEED=true`.
     */
    'auto_seed' => (bool) env('SAILUS_AUTO_SEED', false),

    /*
     * The canonical SAIlus Agent app record attributes (REQ-HPBN-002).
     * `slug='sailus'` is the lookup key used everywhere (seeder,
     * /api/v1/me/apps, RBAC).
     */
    'app' => [
        'slug' => 'sailus',
        'nombre' => 'SAIlus Agent Platform',
        'tipo' => 'internal',
        'auth_type' => 'sanctum',
        'activo' => true,
        'descripcion' => 'Plataforma de agentes IA para setters, marketing y soporte SST.',
    ],

    /*
     * SAIlus Agent profile slugs → canonical brand entity.
     *
     * Each entry seeds one `app_entidad` row with `app_id=sailus`,
     * `perfil=<slug>`, `entidad_id=<brand entity>`, `estado='Activo'`.
     *
     * The 5 slug keys are the contract; do not rename without also
     * updating `App\Models\AppEntidad::PERFILES_ACEPTADOS` and the
     * Form Request validation rule.
     */
    'profiles' => [
        'setter-safe-health' => [
            'identificacion' => 'SAILUS-SETTER-SAFE-HEALTH',
            'nombre' => 'Safe Health Colombia — SAIlus Agent Setter',
        ],
        'setter-tis' => [
            'identificacion' => 'SAILUS-SETTER-TIS',
            'nombre' => 'TIS — SAIlus Agent Setter',
        ],
        'setter-alejandro' => [
            'identificacion' => 'SAILUS-SETTER-ALEJANDRO',
            'nombre' => 'Alejandro — SAIlus Agent Setter',
        ],
        'marketing-sailus' => [
            'identificacion' => 'SAILUS-MARKETING-SAILUS',
            'nombre' => 'SAIlus — SAIlus Agent Marketing',
        ],
        'sst-support-safe-health' => [
            'identificacion' => 'SAILUS-SST-SAFE-HEALTH',
            'nombre' => 'Safe Health — SAIlus Agent SST Support',
        ],
    ],
];
