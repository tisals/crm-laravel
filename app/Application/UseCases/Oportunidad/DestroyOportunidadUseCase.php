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
     * Soft-delete an oportunidad.
     *
     * If the deleted row was the latest version of its family, promote the
     * next-highest non-deleted version to (is_latest=1, estado='Activa',
     * parent_id=NULL) so the family keeps showing up in the default query.
     * Otherwise just soft-delete — no promotion needed.
     */
    public function execute(int $id): bool
    {
        $opp = Oportunidad::find($id);
        if (! $opp) {
            return false;
        }

        $wasLatest = (bool) $opp->is_latest;
        $baseCodigo = CrearVersionOportunidadUseCase::stripVersionSuffix($opp->codigo);

        $deleted = $this->repository->delete($id);
        if (! $deleted || ! $wasLatest) {
            return $deleted;
        }

        // Promote the next-highest non-deleted version of the same family
        return DB::transaction(function () use ($baseCodigo) {
            $next = Oportunidad::where(function ($q) use ($baseCodigo) {
                $q->where('codigo', $baseCodigo)
                    ->orWhere('codigo', 'like', $baseCodigo.' v%');
            })->whereNull('deleted_at')
                ->orderByDesc('version')
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
                ->whereNull('deleted_at')
                ->update(['parent_id' => $next->id]);

            return true;
        });
    }
}
