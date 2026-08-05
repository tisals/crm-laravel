<?php

namespace App\Application\UseCases\Dashboard;

use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads dashboard KPIs from the pre-computed snapshot.
 *
 * Falls back to live aggregation if no snapshot is found. This means the
 * dashboard works even before the first nightly job runs (e.g. fresh
 * deploy). After the first run, the dashboard serves from a single
 * SELECT instead of 70+ aggregation queries.
 */
class GetDashboardSnapshotUseCase
{
    /**
     * How old can a snapshot be before we consider it stale and fall back
     * to live aggregation. Default: 26h (allows for some scheduling slack
     * on the nightly job).
     */
    private const STALENESS_HOURS = 26;

    private const CACHE_TTL = 60; // seconds — short to allow quick refresh

    private const CACHE_PREFIX = 'dashboard:snapshot:';

    public function __construct(
        private GetDashboardUseCase $liveUseCase,
    ) {}

    public function execute(
        ?int $comercialId = null,
        ?string $fechaInicio = null,
        ?string $fechaFin = null,
        ?Usuario $authUser = null
    ): array {
        $effectiveComercialId = $this->resolveComercialId($comercialId, $authUser);
        $year = $fechaInicio ? (int) date('Y', strtotime($fechaInicio)) : (int) date('Y');

        $scope = $effectiveComercialId ? "comercial:{$effectiveComercialId}" : 'global';
        $cacheKey = self::CACHE_PREFIX."{$scope}:{$year}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $this->filterByDate($cached, $fechaInicio, $fechaFin);
        }

        $row = DB::table('dashboard_kpi_snapshot')
            ->where('scope', $scope)
            ->where('year', $year)
            ->first();

        $isFresh = $row && $row->computed_at
            && strtotime($row->computed_at) > (time() - self::STALENESS_HOURS * 3600);

        if ($isFresh) {
            $kpis = json_decode($row->kpis, true);
            Cache::put($cacheKey, $kpis, self::CACHE_TTL);

            return $this->filterByDate($kpis, $fechaInicio, $fechaFin);
        }

        // Fallback: snapshot missing or stale — compute live
        return $this->liveUseCase->execute(
            comercialId: $effectiveComercialId,
            fechaInicio: $fechaInicio,
            fechaFin: $fechaFin,
            authUser: $authUser,
        );
    }

    private function resolveComercialId(?int $comercialId, ?Usuario $authUser): ?int
    {
        if ($authUser && $authUser->rol?->nombre === 'Comercial') {
            return $authUser->id;
        }

        return $comercialId;
    }

    /**
     * Apply date-range narrowing on top of the snapshot.
     *
     * The snapshot stores FULL-YEAR aggregations (12-month series, totals
     * for the whole year). If the caller asks for a sub-year range, we
     * filter in PHP — the snapshot is small enough that this is fast.
     *
     * If the range spans the full year (or no range given), the snapshot
     * is returned as-is.
     */
    private function filterByDate(array $kpis, ?string $fechaInicio, ?string $fechaFin): array
    {
        $yearStart = (int) date('Y').'-01-01';
        $yearEnd = (int) date('Y').'-12-31';

        $isFullYear = (! $fechaInicio || $fechaInicio <= $yearStart)
            && (! $fechaFin || $fechaFin >= $yearEnd);

        if ($isFullYear) {
            return $kpis;
        }

        // For sub-year ranges, mark the snapshot as "filtered" and append
        // a hint so the frontend can show "Datos hasta {fecha}".
        $kpis['_snapshot_filtered'] = true;
        $kpis['_snapshot_filter'] = [
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
        ];

        return $kpis;
    }
}
