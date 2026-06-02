<?php

namespace App\Application\UseCases\Dashboard;

use App\Models\Oportunidad;
use App\Models\Contacto;
use App\Models\Seguimiento;
use App\Models\DetalleOportunidad;
use App\Models\Entidad;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class GetDashboardUseCase
{
    /**
     * Execute the dashboard metrics query.
     *
     * @param  int|null     $comercialId  Filter ventas by commercial's assigned entities (super_admin only).
     * @param  string|null  $fechaInicio  Optional start date for ALL sections.
     * @param  string|null  $fechaFin     Optional end date for ALL sections.
     * @param  Usuario|null $authUser     Authenticated user — role determines auto-filtering.
     * @return array
     */
    public function execute(
        ?int $comercialId = null,
        ?string $fechaInicio = null,
        ?string $fechaFin = null,
        ?Usuario $authUser = null
    ): array {
        // Ventas role auto-filters to their own entities; super_admin can pass ?comercial_id
        $effectiveComercialId = $this->resolveComercialId($comercialId, $authUser);

        return [
            'prospectos'           => $this->getProspectosData($fechaInicio, $fechaFin),
            'ventas'               => $this->getVentasData($effectiveComercialId, $fechaInicio, $fechaFin),
            'chart'                => $this->getChartData($effectiveComercialId, $fechaInicio, $fechaFin),
            'actividades_recientes' => $this->getActividadesRecientes(),
        ];
    }

    // -----------------------------------------------------------------------
    //  Role / commercial resolution
    // -----------------------------------------------------------------------

    /**
     * Determine the effective comercial ID based on role and explicit parameter.
     *
     * - Ventas role: always auto-filtered to their own entities (ignores ?comercial_id).
     * - Super admin (Admin) or others: uses ?comercial_id if provided.
     */
    private function resolveComercialId(?int $comercialId, ?Usuario $authUser): ?int
    {
        if ($authUser && $authUser->rol?->nombre === 'Ventas') {
            return $authUser->id;
        }

        return $comercialId;
    }

    // -----------------------------------------------------------------------
    //  Section: prospectos
    // -----------------------------------------------------------------------

    private function getProspectosData(?string $fechaInicio, ?string $fechaFin): array
    {
        $year = $this->resolveYear($fechaInicio);

        // --- Nuevos leads del mes ---
        $leadsQuery = Contacto::query();
        if ($fechaInicio && $fechaFin) {
            $leadsQuery->whereBetween('created_at', [$fechaInicio, $fechaFin]);
        } elseif ($fechaInicio) {
            $leadsQuery->whereDate('created_at', '>=', $fechaInicio);
        } elseif ($fechaFin) {
            $leadsQuery->whereDate('created_at', '<=', $fechaFin);
        } else {
            $leadsQuery->whereMonth('created_at', now()->month)
                       ->whereYear('created_at', now()->year);
        }
        $nuevosLeadsMes = (int) $leadsQuery->count();

        // --- Tasa de conversión ---
        $oppQuery = Oportunidad::query();
        $this->applyDateFilter($oppQuery, $fechaInicio, $fechaFin, 'oportunidad.fecha');

        $totalOpp  = (int) (clone $oppQuery)->count();
        $ganadas   = (int) (clone $oppQuery)->where('estado', 'Ganada')->count();
        $tasaConversion = $totalOpp > 0 ? round(($ganadas / $totalOpp) * 100, 1) : 0.0;

        // --- Oportunidades creadas por mes (cantidad) ---
        $oportunidadesPorMes = [];
        for ($m = 1; $m <= 12; $m++) {
            $q = Oportunidad::query()
                ->whereMonth('fecha', $m)
                ->whereYear('fecha', $year);
            $this->applyDateFilter($q, $fechaInicio, $fechaFin, 'oportunidad.fecha');
            $oportunidadesPorMes[$this->mesNombre($m)] = (int) $q->count();
        }

        // --- Oportunidades monto por mes (suma vr_total de todas las oportunidades creadas en el mes) ---
        $oportunidadesMontoPorMes = [];
        for ($m = 1; $m <= 12; $m++) {
            $q = DetalleOportunidad::query()
                ->join('oportunidad', 'detalle_oportunidad.oportunidad_id', '=', 'oportunidad.id')
                ->whereMonth('oportunidad.fecha', $m)
                ->whereYear('oportunidad.fecha', $year);
            $this->applyDateFilter($q, $fechaInicio, $fechaFin, 'oportunidad.fecha');
            $oportunidadesMontoPorMes[$this->mesNombre($m)] = (float) ($q->sum('detalle_oportunidad.vr_total') ?: 0);
        }

        // --- Entidades por mes (creadas en el mes) ---
        $entidadesPorMes = [];
        for ($m = 1; $m <= 12; $m++) {
            $q = Entidad::query()
                ->whereMonth('created_at', $m)
                ->whereYear('created_at', $year);
            $this->applyDateFilter($q, $fechaInicio, $fechaFin, 'entidad.created_at');
            $entidadesPorMes[$this->mesNombre($m)] = (int) $q->count();
        }

        // --- Entidades convertidas por mes (tuvieron oportunidad Ganada) ---
        $entidadesConvertidasMes = [];
        for ($m = 1; $m <= 12; $m++) {
            $q = Oportunidad::query()
                ->where('estado', 'Ganada')
                ->whereMonth('fecha', $m)
                ->whereYear('fecha', $year);
            $this->applyDateFilter($q, $fechaInicio, $fechaFin, 'oportunidad.fecha');
            $entidadesConvertidasMes[$this->mesNombre($m)] = (int) (clone $q)
                ->distinct()
                ->count('entidad_id');
        }

        // --- Oportunidades por estado ---
        $oppEstadoQuery = Oportunidad::query();
        $this->applyDateFilter($oppEstadoQuery, $fechaInicio, $fechaFin, 'oportunidad.fecha');
        $oportunidadesPorEstado = (clone $oppEstadoQuery)
            ->select('estado', DB::raw('COUNT(*) as total'))
            ->groupBy('estado')
            ->orderBy('estado')
            ->get()
            ->toArray();

        return [
            'nuevos_leads_mes'          => $nuevosLeadsMes,
            'tasa_conversion'           => $tasaConversion,
            'entidades_por_mes'         => $entidadesPorMes,
            'entidades_convertidas_mes'  => $entidadesConvertidasMes,
            'oportunidades_por_mes'       => $oportunidadesPorMes,
            'oportunidades_monto_por_mes' => $oportunidadesMontoPorMes,
            'oportunidades_por_estado'    => $oportunidadesPorEstado,
        ];
    }

    // -----------------------------------------------------------------------
    //  Section: ventas
    // -----------------------------------------------------------------------

    private function getVentasData(?int $comercialId, ?string $fechaInicio, ?string $fechaFin): array
    {
        $year = $this->resolveYear($fechaInicio);

        // --- Base query: won opportunity details ---
        $ventasQuery = $this->baseVentasQuery($comercialId, $fechaInicio, $fechaFin);

        // --- Ventas promedio mensual: total anual / 12 ---
        $allWon = (clone $ventasQuery)
            ->select('detalle_oportunidad.vr_total', 'oportunidad.fecha')
            ->get();
        $totalRevenue = (float) $allWon->sum('vr_total');
        $ventasMes = $totalRevenue > 0
            ? round($totalRevenue / 12, 2)
            : 0.0;

        // --- Ventas por mes (12 months) ---
        $ventasPorMes = [];
        for ($m = 1; $m <= 12; $m++) {
            $q = $this->baseVentasQuery($comercialId, $fechaInicio, $fechaFin)
                ->whereMonth('oportunidad.fecha', $m)
                ->whereYear('oportunidad.fecha', $year);
            $ventasPorMes[$this->mesNombre($m)] = (float) ($q->sum('detalle_oportunidad.vr_total') ?: 0);
        }

        // --- LTV: facturación mensual promedio por cliente ---
        // Por cada cliente: total ingresos / meses con ventas → promedio entre clientes
        $clientesLtv = (clone $ventasQuery)
            ->select('oportunidad.entidad_id', 'detalle_oportunidad.vr_total', 'oportunidad.fecha')
            ->get()
            ->groupBy('entidad_id')
            ->map(function ($items) {
                $total = (float) $items->sum('vr_total');
                $months = $items->pluck('fecha')
                    ->map(fn ($d) => date('Y-m', strtotime((string) $d)))
                    ->unique()
                    ->count();

                return $months > 0 ? $total / $months : 0;
            });
        $ltv = $clientesLtv->count() > 0
            ? round($clientesLtv->avg(), 2)
            : 0.0;

        // --- Funnel: oportunidades por estado con total y monto ---
        $funnelQuery = Oportunidad::query()
            ->leftJoin('detalle_oportunidad', 'oportunidad.id', '=', 'detalle_oportunidad.oportunidad_id');
        $this->applyCommercialFilter($funnelQuery, $comercialId);
        $this->applyDateFilter($funnelQuery, $fechaInicio, $fechaFin, 'oportunidad.fecha');
        $funnel = (clone $funnelQuery)
            ->select(
                'oportunidad.estado',
                DB::raw('COUNT(DISTINCT oportunidad.id) as total'),
                DB::raw('COALESCE(SUM(detalle_oportunidad.vr_total), 0) as monto')
            )
            ->groupBy('oportunidad.estado')
            ->orderBy('oportunidad.estado')
            ->get()
            ->toArray();

        return [
            'ventas_mes'     => $ventasMes,
            'ventas_por_mes' => $ventasPorMes,
            'ltv'            => $ltv,
            'funnel'         => $funnel,
        ];
    }

    /**
     * Reusable base query for ventas (won details + commercial + date filter).
     * Does NOT include month/year restriction — callers add that if needed.
     */
    private function baseVentasQuery(?int $comercialId, ?string $fechaInicio, ?string $fechaFin)
    {
        $query = DetalleOportunidad::query()
            ->join('oportunidad', 'detalle_oportunidad.oportunidad_id', '=', 'oportunidad.id')
            ->where('oportunidad.estado', 'Ganada');

        $this->applyCommercialFilter($query, $comercialId);
        $this->applyDateFilter($query, $fechaInicio, $fechaFin, 'oportunidad.fecha');

        return $query;
    }

    // -----------------------------------------------------------------------
    //  Section: chart (12-month data for the graph)
    // -----------------------------------------------------------------------

    private function getChartData(?int $comercialId, ?string $fechaInicio, ?string $fechaFin): array
    {
        $year  = $this->resolveYear($fechaInicio);
        $meses = $this->getMonthLabels();

        $entidadesConvertidas = [];
        $ventas               = [];

        for ($m = 1; $m <= 12; $m++) {
            // Bars: distinct entities with won opportunities this month
            $eq = Oportunidad::query()
                ->where('estado', 'Ganada')
                ->whereMonth('fecha', $m)
                ->whereYear('fecha', $year);
            $this->applyDateFilter($eq, $fechaInicio, $fechaFin, 'oportunidad.fecha');
            $this->applyCommercialFilter($eq, $comercialId);
            $entidadesConvertidas[] = (int) (clone $eq)->distinct()->count('entidad_id');

            // Line: sales this month
            $vq = $this->baseVentasQuery($comercialId, $fechaInicio, $fechaFin)
                ->whereMonth('oportunidad.fecha', $m)
                ->whereYear('oportunidad.fecha', $year);
            $ventas[] = (float) ($vq->sum('detalle_oportunidad.vr_total') ?: 0);
        }

        return [
            'meses'                 => $meses,
            'entidades_convertidas' => $entidadesConvertidas,
            'ventas'                => $ventas,
        ];
    }

    // -----------------------------------------------------------------------
    //  Section: actividades recientes
    // -----------------------------------------------------------------------

    private function getActividadesRecientes(): array
    {
        return Seguimiento::with(['oportunidad', 'autor'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function (Seguimiento $s) {
                return [
                    'id'                 => $s->id,
                    'tipo'               => $s->tipo,
                    'notas'              => $s->notas,
                    'fecha'              => $s->fecha,
                    'hora'               => $s->hora,
                    'oportunidad_codigo' => $s->oportunidad?->codigo,
                    'autor'              => $s->autor?->nombre,
                ];
            })
            ->toArray();
    }

    // -----------------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve the calendar year: from fechaInicio if provided, else current year.
     */
    private function resolveYear(?string $fechaInicio): int
    {
        return $fechaInicio ? (int) date('Y', strtotime($fechaInicio)) : (int) now()->year;
    }

    /**
     * Apply the commercial filter (entidad_usuario) to a query.
     * Adds: WHERE entidad_id IN (SELECT entidad_id FROM entidad_usuario WHERE usuario_id = ?)
     */
    private function applyCommercialFilter($query, ?int $comercialId): void
    {
        if ($comercialId === null) {
            return;
        }

        $query->whereIn('oportunidad.entidad_id', function ($q) use ($comercialId) {
            $q->select('entidad_id')
              ->from('entidad_usuario')
              ->where('usuario_id', $comercialId);
        });
    }

    /**
     * Apply optional date range filter to a query.
     * Uses whereDate for single-sided and whereBetween for full range.
     */
    private function applyDateFilter($query, ?string $fechaInicio, ?string $fechaFin, string $dateField = 'oportunidad.fecha'): void
    {
        if ($fechaInicio && $fechaFin) {
            $query->whereBetween($dateField, [$fechaInicio, $fechaFin]);
        } elseif ($fechaInicio) {
            $query->whereDate($dateField, '>=', $fechaInicio);
        } elseif ($fechaFin) {
            $query->whereDate($dateField, '<=', $fechaFin);
        }
    }

    private function getMonthLabels(): array
    {
        return ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    }

    private function mesNombre(int $num): string
    {
        $nombres = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

        return $nombres[$num - 1] ?? '?';
    }
}
