<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Support\Facades\Artisan;
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
    #[Test]
    public function command_is_registered_with_full_signature(): void
    {
        /** @var ArtisanApplication $artisan */
        $artisan = $this->app->make(ArtisanApplication::class);

        $this->assertArrayHasKey(
            'crm:backfill-personas-from-contacto',
            $artisan->all(),
            'crm:backfill-personas-from-contacto must be registered (REQ-PCBF-001)'
        );

        $command = $artisan->all()['crm:backfill-personas-from-contacto'];
        $definition = $command->getDefinition();

        // All three flags from REQ-PCBF-001 must be declared.
        $this->assertTrue(
            $definition->hasOption('dry-run'),
            'crm:backfill-personas-from-contacto must accept --dry-run (REQ-PCBF-001)'
        );
        $this->assertTrue(
            $definition->hasOption('force'),
            'crm:backfill-personas-from-contacto must accept --force (REQ-PCBF-001)'
        );
        $this->assertTrue(
            $definition->hasOption('limit'),
            'crm:backfill-personas-from-contacto must accept --limit=N (REQ-PCBF-001)'
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
        $this->assertStringContainsString('--limit=', $output, 'help must show --limit=');
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
            \Illuminate\Support\Facades\DB::table('personas')->count(),
            'dry-run MUST NOT write to personas table (REQ-PCBF-001)'
        );
        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('contacto')->whereNotNull('persona_id')->count(),
            'dry-run MUST NOT write to contacto.persona_id (REQ-PCBF-001)'
        );
    }
}