<?php

namespace App\Console\Commands;

use App\Application\UseCases\Oportunidad\OportunidadCsvImportUseCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VersionOpportunities extends Command
{
    protected $signature = 'crm:version-opportunities
                           {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Apply opportunity versioning (postProcessVersions) to existing data';

    public function handle(): int
    {
        $uc = new OportunidadCsvImportUseCase;

        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        $this->info('Processing opportunity versions...');
        $result = $uc->postProcessVersions();

        $this->line('');
        $this->info("Done! {$result['updated_activa']} marked as active, {$result['updated_inactiva']} marked as inactive.");
        $this->line("Total groups processed: {$result['groups']}");

        return self::SUCCESS;
    }

    private function dryRun(): int
    {
        $total = DB::table('oportunidad')->count();

        // Count rows where is_latest or estado already mark a superseded version
        $alreadyVersioned = DB::table('oportunidad')
            ->where('is_latest', 0)
            ->orWhere('estado', 'Inactiva')
            ->count();

        // Estimate multi-version groups via SQL (codigo matching "base vN" pattern)
        $versionedCodigos = DB::table('oportunidad')
            ->where('codigo', 'REGEXP', ' v[0-9]+$')
            ->count();

        $this->line("Total oportunidades: {$total}");
        $this->line("  Ya versionadas (Inactiva/is_latest=0): {$alreadyVersioned}");
        $this->line("  Códigos con sufijo vN: {$versionedCodigos}");
        $this->line('');
        $this->comment('Run without --dry-run to apply postProcessVersions on all rows.');
        $this->line('  Safe to run multiple times — idempotent.');

        return self::SUCCESS;
    }
}
