<?php

namespace App\Application\UseCases\Persona;

use App\Domain\Entities\Persona as PersonaEntity;
use App\Domain\Events\PersonaChanged;
use App\Domain\Repositories\PersonaRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * PR-J — UpdatePersonaUseCase dispatches `PersonaChanged(action='updated')`
 * IFF the patch produces a real field change (REQ-PSWH-006, AD-8, R-3).
 *
 * The "no effective change" rule is enforced by comparing the user-facing
 * field set of the pre- and post-update entity snapshots with
 * `array_diff_assoc`. Timestamps are intentionally EXCLUDED so a PATCH
 * that only bumps `updated_at` does NOT emit a webhook (RQ-4 — silence on
 * pure timestamp advances keeps Mercurio's mirror free of noise).
 *
 * Why `array_diff_assoc` and not `!==`: the comparison is field-by-field
 * against identical keys (the domain entity has a fixed schema). The diff
 * returns entries where $old != $new; an empty diff means "no real change".
 */
class UpdatePersonaUseCase
{
    /**
     * User-facing fields compared for the "no effective change" rule.
     * Excludes timestamps (`created_at`, `updated_at`, `deleted_at`) and
     * the auto-incrementing PK. Mirrors the columns in `PersonaResource`.
     */
    private const COMPARED_FIELDS = [
        'identificacion_tipo',
        'identificacion_numero',
        'nombres',
        'apellidos',
        'email_principal',
        'telefono_principal',
        'direccion',
        'ciudad',
        'pais',
        'tipo_persona',
        'entidad_id',
    ];

    public function __construct(
        private PersonaRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        // Read on the read connection (handled inside the repository) so
        // we capture the pre-update shape from the same source of truth
        // the API consumer would see. The actual write still routes to
        // master via the repository's `update()`.
        $existing = $this->repository->findById($id);

        if (! $existing) {
            return null;
        }

        $old = $this->projectForComparison($existing);

        $updated = DB::transaction(fn () => $this->repository->update($id, $data));

        if (! $updated) {
            return null;
        }

        $new = $this->projectForComparison($updated);

        if ($this->hasFieldChange($old, $new)) {
            event(new PersonaChanged(
                action: 'updated',
                persona_id: (int) $updated->id,
                snapshot: $updated->toArray(),
                occurred_at: now()->toIso8601String(),
            ));
        }

        return $updated;
    }

    /**
     * Restrict the entity to the user-facing field set so timestamps
     * cannot poison the diff.
     *
     * @return array<string, mixed>
     */
    private function projectForComparison(PersonaEntity $persona): array
    {
        $full = $persona->toArray();

        return array_intersect_key($full, array_flip(self::COMPARED_FIELDS));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function hasFieldChange(array $old, array $new): bool
    {
        // Both arrays have the SAME keys (projectForComparison ensures
        // this), so array_diff_assoc produces a meaningful entry-set of
        // fields that differ. Empty diff → no effective change.
        return array_diff_assoc($old, $new) !== [];
    }
}
