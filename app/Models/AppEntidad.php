<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the `app_entidad` pivot table.
 *
 * This pivot carries the relationship between entities (clients/tenants)
 * and the apps they have contracted. It is mutated by:
 *   - `App\Application\UseCases\App\AssignAppToEntidadUseCase`
 *     (programmatic assignment via use cases)
 *   - `Modules\CRM\app\Http\Controllers\AppController::assignAppToEntidad`
 *     (REST endpoint)
 *   - `database/seeders/SailusAgentSeeder` (PR-D profile bootstrap, formerly HermesAppSeeder)
 *
 * The `perfil` column was added by the `2026_08_28_000006` migration
 * (PR-C) for SAIlus Agent profile binding. It is metadata for the SAIlus
 * Agent runtime; it is NOT part of the crm-laravel RBAC contract
 * (user access remains transitive via `entidad_persona`, resolved through
 * `usuarios.persona_id` FK added in migration 000003).
 *
 * See spec REQ-HPBN-005 (closed allow-list) and design.md AD-2.
 */
class AppEntidad extends Model
{
    /**
     * The pivot table does not carry its own updated_at semantics for
     * `updateOrCreate` callers — timestamps are managed by the Eloquent
     * layer on save. Keep `$timestamps = true`.
     */
    protected $table = 'app_entidad';

    protected $fillable = [
        'app_id',
        'entidad_id',
        'perfil',
        'fecha_contrato',
        'fecha_vencimiento',
        'estado',
        'notas',
        'created_by',
    ];

    /**
     * Closed allow-list of valid `perfil` values (REQ-HPBN-005).
     *
     * Only the 5 seeded SAIlus Agent profiles are accepted; `null` is the
     * "no profile" sentinel (covered by `nullable` validation rules,
     * not by this constant — `Rule::in()` skips null unless explicitly
     * configured). Keep in sync with `config/sailus.php` `profiles` map
     * and the OpenAPI schema for the assign-app endpoint.
     *
     * Adding a profile here without adding it to `config/sailus.php`
     * will fail the sailus seeder's 5-entry guard. Conversely, adding
     * to `config/sailus.php` without updating this list will cause the
     * Form Request to reject the new slug.
     */
    public const PERFILES_ACEPTADOS = [
        'setter-safe-health',
        'setter-tis',
        'setter-alejandro',
        'marketing-sailus',
        'sst-support-safe-health',
    ];
}
