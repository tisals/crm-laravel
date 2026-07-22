<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;
use App\Models\Oportunidad;
use Illuminate\Support\Facades\DB;

class DestroyOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $repository,
    ) {}

    /**
     * Hard-delete an oportunidad.
     *
     * The row is removed from the database entirely (no soft delete).
     * If the deleted row was the latest version of its family, promote the
     * next-highest existing version to (is_latest=1, estado='Activa',
     * parent_id=NULL) so the family keeps showing up in the default query.
     * Otherwise just delete — no promotion needed.
     */
    public function execute(int $id): bool
    {
        $opp = Oportunidad::find($id);
        if (! $opp) {
            return false;
        }

        $wasLatest = (bool) $opp->is_latest;
        $baseCodigo = CrearVersionOportunidadUseCase::stripVersionSuffix($opp->codigo);

        return DB::transaction(function () use ($id, $opp, $wasLatest, $baseCodigo) {
            // Hard delete the row (force delete bypasses SoftDeletes scope)
            $deleted = $opp->forceDelete();
            if (! $deleted) {
                return false;
            }

            if (! $wasLatest) {
                return true;
            }

            // Promote the next-highest existing version of the same family
            $next = Oportunidad::where(function ($q) use ($baseCodigo) {
                $q->where('codigo', $baseCodigo)
                    ->orWhere('codigo', 'like', $baseCodigo.' v%');
            })->orderByDesc('version')
                ->first();

            if (! $next) {
                return true; // nothing to promote (last version of family)
            }

            $next->update([
                'is_latest' => 1,
                'estado' => 'Activa',
                'parent_id' => null,
            ]);

            // Rewire remaining versions' parent_id to the new latest
            Oportunidad::where(function ($q) use ($baseCodigo) {
                $q->where('codigo', $baseCodigo)
                    ->orWhere('codigo', 'like', $baseCodigo.' v%');
            })->where('id', '!=', $next->id)
                ->update(['parent_id' => $next->id]);

            return true;
        });
    }
}
