<?php

namespace App\Application\UseCases\Seguridad;

use App\Models\ActividadLog;
use App\Models\Entidad;
use App\Models\Producto;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class GetSecurityDashboardUseCase
{
    public function execute(): array
    {
        return [
            'kpi' => $this->getKpis(),
            'distribucion_roles' => $this->getDistribucionRoles(),
            'actividad_reciente' => $this->getActividadReciente(),
        ];
    }

    private function getKpis(): array
    {
        $totalUsuarios = Usuario::count();
        $usuariosActivos = Usuario::where('estado', 'Activo')->count();
        $totalProductos = Producto::count();
        // Commit 5.5 dropped `entidad.estado`. The "Propia" brand-state
        // now lives on `entidad_relacion` (open pivot row with
        // tipo_relacion='propia'). We count distinct entities to avoid
        // double-counting when an entidad has multiple propia rows over
        // time (only the currently-open one counts).
        $totalMarcas = DB::table('entidad_relacion')
            ->where('tipo_relacion', 'propia')
            ->whereNull('effective_to')
            ->distinct()
            ->count('entidad_id');

        return [
            'total_usuarios' => (int) $totalUsuarios,
            'usuarios_activos' => (int) $usuariosActivos,
            'total_productos' => (int) $totalProductos,
            'total_marcas' => (int) $totalMarcas,
        ];
    }

    private function getDistribucionRoles(): array
    {
        return Usuario::select('rol_id', DB::raw('COUNT(*) as total'))
            ->groupBy('rol_id')
            ->with('rol:id,nombre')
            ->get()
            ->map(fn ($item) => [
                'rol' => $item->rol?->nombre ?? 'Sin rol',
                'total' => (int) $item->total,
            ])
            ->toArray();
    }

    private function getActividadReciente(): array
    {
        return ActividadLog::with('usuario:id,nombre,email')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (ActividadLog $log) => [
                'id' => $log->id,
                'tipo' => $log->tipo,
                'descripcion' => $log->descripcion,
                'fecha' => $log->created_at->toDateString(),
                'hora' => $log->created_at->toTimeString(),
                'usuario' => $log->usuario?->nombre ?? $log->usuario?->email ?? '—',
            ])
            ->toArray();
    }
}
