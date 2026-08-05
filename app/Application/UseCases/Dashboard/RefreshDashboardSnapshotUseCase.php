<?php

namespace App\Application\UseCases\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the dashboard_kpi_snapshot table.
 *
 * Computes the full-year aggregations for the current year and stores
 * them as JSON keyed by (scope, year). The dashboard endpoint reads from
 * this table instead of running 70+ live aggregations.
 *
 * Strategy:
 * - 1 'global' snapshot per year (no filter)
 * - 1 snapshot per comercial per year (filtered to that comercial's entidades)
 *
 * Idempotent: uses upsert (ON DUPLICATE KEY UPDATE) on (scope, year).
 */
class RefreshDashboardSnapshotUseCase
{
    public function __construct(
        private GetDashboardUseCase $dashboardUseCase,
    ) {}

    /**
     * @return array{refreshed: int, scopes: array<string>, duration_ms: int}
     */
    public function execute(?int $year = null): array
    {
        $start = microtime(true);
        $year = $year ?? (int) date('Y');
        $now = now();
        $refreshed = 0;
        $scopes = [];

        // 1) Global scope
        $kpis = $this->dashboardUseCase->execute(
            comercialId: null,
            fechaInicio: "$year-01-01",
            fechaFin: "$year-12-31",
            authUser: null,
        );
        $this->upsert('global', $year, $kpis, $now);
        $refreshed++;
        $scopes[] = 'global';

        // 2) Per-comercial scopes — only for users with rol 'Comercial'
        $comercialIds = DB::table('usuarios')
            ->join('roles', 'usuarios.rol_id', '=', 'roles.id')
            ->where('roles.nombre', 'Comercial')
            ->whereNull('usuarios.deleted_at')
            ->pluck('usuarios.id')
            ->toArray();

        foreach ($comercialIds as $comercialId) {
            $kpis = $this->dashboardUseCase->execute(
                comercialId: (int) $comercialId,
                fechaInicio: "$year-01-01",
                fechaFin: "$year-12-31",
                authUser: null,
            );
            $this->upsert("comercial:{$comercialId}", $year, $kpis, $now);
            $refreshed++;
            $scopes[] = "comercial:{$comercialId}";
        }

        $duration = (int) ((microtime(true) - $start) * 1000);

        Log::info('dashboard.snapshot.refreshed', [
            'year' => $year,
            'scopes' => count($scopes),
            'duration_ms' => $duration,
        ]);

        return [
            'refreshed' => $refreshed,
            'scopes' => $scopes,
            'duration_ms' => $duration,
        ];
    }

    private function upsert(string $scope, int $year, array $kpis, \DateTimeInterface $computedAt): void
    {
        DB::table('dashboard_kpi_snapshot')->updateOrInsert(
            ['scope' => $scope, 'year' => $year],
            [
                'kpis' => json_encode($kpis),
                'computed_at' => $computedAt,
                'created_at' => $computedAt,
                'updated_at' => $computedAt,
            ]
        );
    }
}
