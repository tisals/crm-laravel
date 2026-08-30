<?php

namespace Tests\Unit\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-F (Phase 4): backfill command registration + dry-run smoke (REQ-PCBF-001).
 *
 * Tasks covered:
 *  - 4.1 — `crm:backfill-personas-from-contacto` is registered and accepts
 *    `--dry-run`, `--force`, `--limit=N`.
 *  - 4.2 — dry-run writes nothing; output reports the expected counters.
 *
 * Strict TDD: these tests are RED before `BackfillPersonasFromContacto`
 * command class exists in `app/Console/Commands/`. After GREEN, the
 * command is registered with the documented signature and respects the
 * dry-run flag.
 *
 * Why Unit/Console instead of Feature/Console? The command is wired
 * against the full DB but only validates registration + flag parsing +
 * dry-run read semantics — no auth, no HTTP. Unit is the right layer.
 */
class BackfillPersonasFromContactoCommandTest extends TestCase
{
    // The dry-run smoke queries `contacto` to report a pending count.
    // RefreshDatabase sets up the full schema in `crm_testing` so the
    // command runs against a real schema (mirrors production).
    use RefreshDatabase;

    #[Test]
    public function command_is_registered_with_full_signature(): void
    {
        // REQ-PCBF-001: command must be registered. We probe it via --help
        // (the documented reflection mechanism) — exit 0 = command exists.
        $exit = Artisan::call('crm:backfill-personas-from-contacto', ['--help' => true]);
        $output = Artisan::output();

        $this->assertSame(
            0,
            $exit,
            'crm:backfill-personas-from-contacto must be registered (CommandNotFoundException otherwise)'
        );
        $this->assertStringContainsString(
            'crm:backfill-personas-from-contacto',
            $output,
            'help output must echo the command name (REQ-PCBF-001)'
        );
    }

    #[Test]
    public function help_lists_documented_flags(): void
    {
        $exit = Artisan::call('crm:backfill-personas-from-contacto', ['--help' => true]);

        $output = Artisan::output();

        $this->assertSame(0, $exit, '--help must exit 0');
        $this->assertStringContainsString('--dry-run', $output, 'help must show --dry-run');
        $this->assertStringContainsString('--force', $output, 'help must show --force');
        $this->assertStringContainsString('--limit', $output, 'help must show --limit');
    }

    #[Test]
    public function dry_run_writes_nothing_and_reports_would_insert_counters(): void
    {
        // No seed data — DB starts empty. This is the smoke that dry-run
        // never throws on empty input and reports 0 across the board.
        $exit = Artisan::call('crm:backfill-personas-from-contacto', [
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exit, 'dry-run on empty DB must exit 0');

        // REQ-PCBF-006: machine-readable summary table MUST print all 7 keys.
        $expectedKeys = [
            'inserted',
            'updated',
            'skipped_already_linked',
            'skipped_null_email',
            'skipped_duplicate',
            'errors',
            'dry_run',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertStringContainsString(
                $key,
                $output,
                "summary output must include '{$key}' (REQ-PCBF-006)"
            );
        }

        // REQ-PCBF-001: dry-run reports the `would_*` family on empty DB.
        $this->assertStringContainsString('would_insert', $output, 'dry-run output must include would_insert');
        $this->assertStringContainsString('would_skip_null_email', $output, 'dry-run output must include would_skip_null_email');
        $this->assertStringContainsString('would_skip_duplicate', $output, 'dry-run output must include would_skip_duplicate');

        // No personas created.
        $this->assertSame(
            0,
            DB::table('personas')->count(),
            'dry-run MUST NOT write to personas table (REQ-PCBF-001)'
        );
        $this->assertSame(
            0,
            DB::table('contacto')->whereNotNull('persona_id')->count(),
            'dry-run MUST NOT write to contacto.persona_id (REQ-PCBF-001)'
        );
    }
}
