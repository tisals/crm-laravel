<?php

namespace App\Application\UseCases\App;

use Illuminate\Support\Facades\DB;

class RemoveAppFromEntidadUseCase
{
    /**
     * Removes the app↔entidad assignment. Idempotent: returns true even
     * if the assignment doesn't exist (no error).
     */
    public function execute(int $appId, int $entidadId): bool
    {
        return DB::table('app_entidad')
            ->where('app_id', $appId)
            ->where('entidad_id', $entidadId)
            ->delete() > 0;
    }
}
