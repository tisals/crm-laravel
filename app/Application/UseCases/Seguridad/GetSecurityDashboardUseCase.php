<?php

namespace App\Application\UseCases\Seguridad;

use App\Models\Usuario;
use App\Models\Producto;
use App\Models\Entidad;
use App\Models\ActividadLog;
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
        $totalMarcas = Entidad::where('estado', 'Propia')->count();

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
