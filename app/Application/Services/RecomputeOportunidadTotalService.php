<?php

namespace App\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recomputes the aggregate `vr_total` on the parent `oportunidad` row
 * by summing `vr_total` across all non-soft-deleted `detalle_oportunidad`
 * rows that reference it.
 *
 * Defensive no-op: the `oportunidad.vr_total` column is NOT in the current
 * schema (see `2026_05_06_000015_create_oportunidad_table.php`). This service
 * checks at runtime whether the column exists and silently no-ops if not.
 * When the column is added in a future migration, this service will start
 * updating it without any caller changes.
 *
 * Called by:
 *   - UpdateDetalleOportunidadUseCase (after a detalle numeric change)
 *   - DestroyDetalleOportunidadUseCase (after a soft-delete)
 */
class RecomputeOportunidadTotalService
{
    public function recompute(int $oportunidadId): void
    {
        if (! Schema::hasColumn('oportunidad', 'vr_total')) {
            // Forward-compat: no parent column yet, nothing to do.
            return;
        }

        DB::table('oportunidad')
            ->where('id', $oportunidadId)
            ->update([
                'vr_total' => DB::raw(
                    '(SELECT COALESCE(SUM(vr_total), 0) FROM detalle_oportunidad '
                    ."WHERE oportunidad_id = {$oportunidadId} AND deleted_at IS NULL)"
                ),
            ]);
    }
}
