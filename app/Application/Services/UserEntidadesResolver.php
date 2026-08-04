<?php

namespace App\Application\Services;

use App\Models\Usuario;
use App\Models\Contacto;
use App\Models\Entidad;
use App\Infrastructure\Persistence\ServiciosAppRepository;
use Illuminate\Support\Facades\DB;

/**
 * UserEntidadesResolver — returns the entities a user has access to.
 *
 * Logic for v1 (final):
 *   - User.persona_id loaded
 *   - Find Contacto where persona_id = user.persona_id AND entidad_id IS NOT NULL
 *   - For each entity, get apps: contratadasActivas(entity) ∩ user.apps
 *   - Filter contacto's 'consulta' tipo_relacion TODO (v1 simplification)
 */
class UserEntidadesResolver
{
    public function __construct(
        private ServiciosAppRepository $serviciosAppRepo,
    ) {}

    public function resolve(Usuario $user): array
    {
        if (! $user->persona_id) {
            return [];
        }

        $contactos = Contacto::where('persona_id', $user->persona_id)
            ->whereNotNull('entidad_id')
            ->whereNull('deleted_at')
            ->with('entidad')
            ->get();

        if ($contactos->isEmpty()) {
            return [];
        }

        $userApps = collect($this->getUserAppsSlugs($user));

        $results = [];
        foreach ($contactos as $contacto) {
            $entidad = $contacto->entidad;
            if (! $entidad || $entidad->deleted_at || $entidad->estado !== 'Activo') {
                continue;
            }

            $appsContratadas = $this->serviciosAppRepo->contratadasActivas($entidad->id);
            $apps = array_values(array_intersect($appsContratadas, $userApps->toArray()));

            $results[] = [
                'id' => (int) $entidad->id,
                'nombre' => $entidad->nombre,
                'rol' => $contacto->rol,
                'apps' => $apps,
            ];
        }

        return $results;
    }

    private function getUserAppsSlugs(Usuario $user): array
    {
        $user->load('apps');
        $slugs = $user->apps->pluck('slug')->toArray();

        // Super-admin: all active apps
        if ($user->isSuperAdmin()) {
            $slugs = DB::table('apps')->where('activo', true)->pluck('slug')->toArray();
        }

        return $slugs;
    }
}
