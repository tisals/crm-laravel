<?php

namespace App\Application\Services;

use App\Models\Permiso;

class RbacService
{
    public function hasPermission(int $rolId, string $vista): bool
    {
        return Permiso::where('rol_id', $rolId)
            ->where(function ($query) use ($vista) {
                $query->where('vista', $vista)
                    ->orWhere('vista', '*');
            })
            ->exists();
    }
}
