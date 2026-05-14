<?php

namespace App\Application\UseCases\Dashboard;

use App\Models\Oportunidad;
use App\Models\Contacto;
use App\Models\Seguimiento;
use App\Models\DetalleOportunidad;
use Illuminate\Support\Facades\DB;

class GetDashboardUseCase
{
    public function execute(): array
    {
        return [
            'kpi' => $this->getKpis(),
            'oportunidades_por_estado' => $this->getOportunidadesPorEstado(),
            'ventas_4_semanas' => $this->getVentas4Semanas(),
            'actividades_recientes' => $this->getActividadesRecientes(),
        ];
    }

    private function getKpis(): array
    {
        $totalOportunidades = Oportunidad::count();

        $ganadas = Oportunidad::where('estado', 'Ganada')->count();
        $perdidas = Oportunidad::where('estado', 'Perdida')->count();
        $cerradas = $ganadas + $perdidas;
        $tasaConversion = $cerradas > 0
            ? round(($ganadas / $cerradas) * 100, 1)
            : 0.0;

        // Ventas del mes: SUM(detalle_oportunidad.vr_total) WHERE estado=Ganada AND MONTH = current
        $ventasMes = DetalleOportunidad::query()
            ->join('oportunidad', 'detalle_oportunidad.oportunidad_id', '=', 'oportunidad.id')
            ->where('oportunidad.estado', 'Ganada')
            ->whereMonth('detalle_oportunidad.created_at', now()->month)
            ->whereYear('detalle_oportunidad.created_at', now()->year)
            ->sum('detalle_oportunidad.vr_total');

        // Nuevos leads este mes
        $nuevosLeads = Contacto::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        return [
            'total_oportunidades' => (int) $totalOportunidades,
            'tasa_conversion' => (float) $tasaConversion,
            'ventas_mes' => (float) ($ventasMes ?: 0),
            'nuevos_leads_mes' => (int) $nuevosLeads,
        ];
    }

    private function getOportunidadesPorEstado(): array
    {
        return Oportunidad::select('estado', DB::raw('COUNT(*) as total'))
            ->groupBy('estado')
            ->orderBy('estado')
            ->get()
            ->toArray();
    }

    private function getVentas4Semanas(): array
    {
        // Last 4 ISO weeks + current partial week
        $driver = DB::connection()->getDriverName();

        // Build ISO week expression compatible with SQLite (strftime) and MariaDB (DATE_FORMAT)
        if ($driver === 'sqlite') {
            $weekExpr = "strftime('%Y-W%W', detalle_oportunidad.created_at)";
        } else {
            // MariaDB / MySQL ISO week: %x = ISO year, %v = ISO week (01-53)
            $weekExpr = "DATE_FORMAT(detalle_oportunidad.created_at, '%x-W%v')";
        }

        $fourWeeksAgo = now()->subWeeks(4)->startOfWeek();

        return DetalleOportunidad::query()
            ->select(DB::raw("{$weekExpr} as semana"), DB::raw('SUM(detalle_oportunidad.vr_total) as total'))
            ->join('oportunidad', 'detalle_oportunidad.oportunidad_id', '=', 'oportunidad.id')
            ->where('oportunidad.estado', 'Ganada')
            ->where('detalle_oportunidad.created_at', '>=', $fourWeeksAgo)
            ->groupBy('semana')
            ->orderBy('semana')
            ->get()
            ->toArray();
    }

    private function getActividadesRecientes(): array
    {
        return Seguimiento::with(['oportunidad', 'autor'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function (Seguimiento $s) {
                return [
                    'id' => $s->id,
                    'tipo' => $s->tipo,
                    'notas' => $s->notas,
                    'fecha' => $s->fecha,
                    'hora' => $s->hora,
                    'oportunidad_codigo' => $s->oportunidad?->codigo,
                    'autor' => $s->autor?->nombre,
                ];
            })
            ->toArray();
    }
}
