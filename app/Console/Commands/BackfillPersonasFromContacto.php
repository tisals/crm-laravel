<?php

namespace App\Console\Commands;

use App\Application\UseCases\Persona\BackfillPersonasFromContactoUseCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PR-F (Phase 4): `crm:backfill-personas-from-contacto`.
 *
 * Artisan wrapper around `BackfillPersonasFromContactoUseCase`. Migrates
 * legacy `contacto` rows into the canonical `personas` table; this is the
 * data step that gates PR-G's FK swap on `seguimiento`.
 *
 * Signature (REQ-PCBF-001):
 *   crm:backfill-personas-from-contacto
 *       {--dry-run : Report only, no DB writes}
 *       {--force  : Skip confirmation prompt}
 *       {--limit= : Process at most N contacto rows}
 *
 * Behavior:
 *  - Without `--force`, prompts "Process N rows? [yes/no]" using the
 *    count of `contacto WHERE persona_id IS NULL`. Honors `--dry-run` and
 *    `--limit` for that count.
 *  - With `--dry-run`, prints the report + exits 0 without writing
 *    (REQ-PCBF-001).
 *  - Prints a machine-readable summary table with the 7 documented keys
 *    (REQ-PCBF-006).
 *  - Exit code 1 when `errors > 0` (REQ-PCBF-005).
 *
 * Stable log codes (REQ-PCBF-004 + design §5.4):
 *   backfill.started
 *   backfill.skipped.null_email
 *   backfill.skipped.already_linked
 *   backfill.dedupe.matched
 *   backfill.row.error
 *   backfill.completed
 *
 * Reversibility (design §10):
 *   UPDATE contacto SET persona_id = NULL;
 *   DELETE FROM personas WHERE created_at > '<backfill-start>';
 *   (soft-delete preserves history)
 */
class BackfillPersonasFromContacto extends Command
{
    protected $signature = 'crm:backfill-personas-from-contacto
        {--dry-run : Report only, no DB writes}
        {--force   : Skip confirmation prompt}
        {--limit=  : Process at most N contacto rows}';

    protected $description = 'Backfill contacto rows into the canonical personas table (PR-F, REQ-PCBF-001..007)';

    public function handle(BackfillPersonasFromContactoUseCase $useCase): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limitOpt = $this->option('limit');
        $limit = ($limitOpt === null || $limitOpt === '') ? null : (int) $limitOpt;

        $this->info('═════════════════════════════════════════════════════');
        $this->info(' crm:backfill-personas-from-contacto');
        $this->info('═════════════════════════════════════════════════════');
        $this->newLine();

        $mode = $dryRun ? 'DRY-RUN (no writes)' : 'WRITE';
        $this->info(sprintf('Mode: %s', $mode));
        $this->info(sprintf('Limit: %s', $limit === null ? 'unbounded' : (string) $limit));
        $this->newLine();

        $pendingCount = DB::table('contacto')
            ->whereNull('persona_id')
            ->when($limit !== null && $limit > 0, fn ($q) => $q->limit($limit))
            ->count();

        $this->info(sprintf('Pending contacto rows: %d', $pendingCount));
        $this->newLine();

        // Confirmation gate. Dry-run is always non-destructive so we skip
        // the prompt; otherwise require --force or interactive yes.
        if (! $dryRun && ! $force && $pendingCount > 0) {
            if (! $this->confirm("Process {$pendingCount} rows and write to personas/contacto?", false)) {
                $this->warn('Cancelled by operator.');

                return self::SUCCESS;
            }
        }

        $result = $useCase->execute([
            'dry_run' => $dryRun,
            'limit' => $limit,
        ]);

        $this->printSummary($result);

        $errors = (int) ($result['errors'] ?? 0);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Render the machine-readable summary table (REQ-PCBF-006).
     * The 7 documented keys must always appear, in either dry-run or
     * actual-run mode. We use `$this->table()` so it renders cleanly in
     * TTY and is parseable in CI logs.
     */
    private function printSummary(array $result): void
    {
        $dryRun = (bool) ($result['dry_run'] ?? false);

        $rows = $dryRun
            ? [
                ['dry_run', 'true'],
                ['would_insert', (string) ($result['would_insert'] ?? 0)],
                ['would_skip_null_email', (string) ($result['would_skip_null_email'] ?? 0)],
                ['would_skip_duplicate', (string) ($result['would_skip_duplicate'] ?? 0)],
                ['inserted', '—'],
                ['updated', '—'],
                ['skipped_already_linked', '—'],
                ['skipped_null_email', '—'],
                ['skipped_duplicate', '—'],
                ['errors', '—'],
            ]
            : [
                ['dry_run', 'false'],
                ['inserted', (string) ($result['inserted'] ?? 0)],
                ['updated', (string) ($result['updated'] ?? 0)],
                ['skipped_already_linked', (string) ($result['skipped_already_linked'] ?? 0)],
                ['skipped_null_email', (string) ($result['skipped_null_email'] ?? 0)],
                ['skipped_duplicate', (string) ($result['skipped_duplicate'] ?? 0)],
                ['would_insert', '—'],
                ['would_skip_null_email', '—'],
                ['would_skip_duplicate', '—'],
                ['errors', (string) ($result['errors'] ?? 0)],
            ];

        $this->newLine();
        $this->info('Summary');
        $this->table(['counter', 'value'], $rows);

        $errors = (int) ($result['errors'] ?? 0);
        if ($errors > 0) {
            $this->newLine();
            $this->error("{$errors} row(s) errored — see logs for backfill.row.error entries.");
        }
    }
}
