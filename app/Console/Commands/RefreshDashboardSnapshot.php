<?php

namespace App\Console\Commands;

use App\Application\UseCases\Dashboard\RefreshDashboardSnapshotUseCase;
use Illuminate\Console\Command;

class RefreshDashboardSnapshot extends Command
{
    protected $signature = 'crm:refresh-dashboard-snapshot
        {--year= : Year to refresh (defaults to current year)}';

    protected $description = 'Refresh the dashboard KPI snapshot (CQRS-Lite read model)';

    public function handle(RefreshDashboardSnapshotUseCase $useCase): int
    {
        $year = $this->option('year') ? (int) $this->option('year') : null;

        $this->info("Refreshing dashboard snapshot (year: ".($year ?? 'current').")...");

        $result = $useCase->execute($year);

        $this->info("✓ Refreshed {$result['refreshed']} scope(s) in {$result['duration_ms']}ms");
        $this->line('Scopes: '.implode(', ', $result['scopes']));

        return self::SUCCESS;
    }
}
