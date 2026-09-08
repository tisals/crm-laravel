<?php

namespace App\Application\UseCases\Entidad;

use App\Domain\Entities\Entidad as EntidadEntity;
use App\Domain\Events\EntidadChanged;
use App\Domain\Repositories\EntidadRepositoryInterface;
use App\Infrastructure\Webhook\EntidadSnapshotBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Commit 7 — `UpdateEntidadUseCase` dispatches `EntidadChanged(action='updated')`
 * IFF the patch produces a real field change. Mirrors
 * `UpdatePersonaUseCase` (PR-J, REQ-PSWH-006).
 *
 * The "no effective change" rule is enforced by comparing the user-facing
 * field set of the pre- and post-update entity snapshots with
 * `array_diff_assoc`. Timestamps are intentionally EXCLUDED so a PATCH
 * that only bumps `updated_at` does NOT emit a webhook (RQ-4 — silence
 * on pure timestamp advances keeps Mercurio's mirror free of noise).
 */
class UpdateEntidadUseCase
{
    /**
     * User-facing fields compared for the "no effective change" rule.
     * Excludes timestamps (`created_at`, `updated_at`, `deleted_at`),
     * the auto-incrementing PK, and the new `cantidad_empleados` /
     * `rut` / `logo` / `linea_negocio` text fields the controller may
     * legitimately PATCH without changing the receiver-side projection.
     *
     * The legacy `estado` column is intentionally absent — it was
     * dropped in Commit 5.5 and the derived `is_active` flag is
     * recomputed by the pivot observer instead.
     */
    private const COMPARED_FIELDS = [
        'tipo_persona',
        'tipo_id',
        'identificacion',
        'nombre',
        'nombre_comercial',
        'linea_negocio',
        'cantidad_empleados',
        'rut',
        'logo',
    ];

    public function __construct(
        private EntidadRepositoryInterface $repository,
        private EntidadSnapshotBuilder $snapshotBuilder,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        $existing = $this->repository->findById($id);

        if (! $existing) {
            return null;
        }

        $old = $this->projectForComparison($existing);

        $updated = $this->repository->update($id, $data);

        if (! $updated) {
            return null;
        }

        $new = $this->projectForComparison($updated);

        if (! $this->hasFieldChange($old, $new)) {
            return $updated;
        }

        $this->dispatchUpdated($updated);

        return $updated;
    }

    private function dispatchUpdated(EntidadEntity $entidad): void
    {
        $snapshot = $this->snapshotBuilder->buildForEntidadId((int) $entidad->id);

        if ($snapshot === null) {
            return;
        }

        event(new EntidadChanged(
            action: 'updated',
            entidad_id: (int) $entidad->id,
            snapshot: $snapshot,
            occurred_at: Carbon::now()->toIso8601String(),
            event_id: (string) Str::uuid(),
        ));
    }

    /**
     * Restrict the entity to the user-facing field set so timestamps
     * cannot poison the diff.
     *
     * @return array<string, mixed>
     */
    private function projectForComparison(EntidadEntity $entidad): array
    {
        $full = $entidad->toArray();

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