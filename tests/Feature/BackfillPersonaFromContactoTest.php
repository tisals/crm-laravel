<?php

namespace Tests\Feature;

use App\Models\Entidad;
use App\Models\Persona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\CRM\Models\Contacto;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-F (Phase 4): backfill use-case behavior (REQ-PCBF-001..006).
 *
 * Tasks covered:
 *  - 4.3 — happy path: N distinct-email contactos → N personas + each contacto linked
 *  - 4.4 — idempotency: second run is a no-op (skipped_already_linked)
 *  - 4.5 — cross-entidad dedupe: 5 contactos with one email across 3 entidades
 *          → exactly 1 persona, 5 contactos linked (AD-12)
 *  - 4.6 — NULL-email contactos skipped with `backfill.skipped.null_email` warning
 *  - 4.7 — per-row error isolation: 1 bad row logs error, others succeed,
 *          command exits 1
 *  - 4.8 — summary output is machine-readable (table format with the 7
 *          documented keys)
 *
 * The command is invoked through `Artisan::call` (the public command
 * surface). Use cases are exercised through their public command wrapper.
 */
class BackfillPersonaFromContactoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No DB guards on test — crm_testing has full FK enforcement.
    }

    // ── 4.3 — happy path ───────────────────────────────────────────────

    #[Test]
    public function happy_path_three_distinct_emails_yield_three_personas_and_linked_contactos(): void
    {
        $entidad = $this->makeEntidad();
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Ana', 'apellidos' => 'A',
            'email_contacto' => 'ana@example.test', 'estado' => 'Activo', 'score' => 0,
        ]);
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Beto', 'apellidos' => 'B',
            'email_contacto' => 'beto@example.test', 'estado' => 'Activo', 'score' => 0,
        ]);
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Cira', 'apellidos' => 'C',
            'email_contacto' => 'cira@example.test', 'estado' => 'Activo', 'score' => 0,
        ]);

        $exit = Artisan::call('crm:backfill-personas-from-contacto', ['--force' => true]);

        $this->assertSame(0, $exit, 'happy path must exit 0');
        $this->assertSame(3, DB::table('personas')->count(), '3 personas must be inserted');
        $this->assertSame(
            0,
            DB::table('personas')->whereNull('deleted_at')->count() - DB::table('personas')->count(),
            'soft-delete MUST NOT fire on freshly inserted personas'
        );

        // Each contacto has persona_id set + matching persona fields.
        foreach (['ana', 'beto', 'cira'] as $slug) {
            $contacto = Contacto::where('email_contacto', "{$slug}@example.test")->firstOrFail();
            $this->assertNotNull(
                $contacto->persona_id,
                "contacto '{$slug}' must be linked to a persona"
            );
            $persona = Persona::find($contacto->persona_id);
            $this->assertNotNull($persona, "persona for '{$slug}' must exist");
            $this->assertSame(
                ucfirst($slug),
                $persona->nombres,
                "persona.nombres for '{$slug}' must equal contacto.nombres"
            );
            // Commit 4 dropped `personas.email_principal`; the email
            // now lives in the shared `emails` table. We pick the
            // primary email (es_principal=true) for the assertion.
            $primaryEmail = $persona->emails()
                ->where('es_principal', true)
                ->value('email');
            $this->assertSame(
                "{$slug}@example.test",
                $primaryEmail,
                "persona primary email for '{$slug}' must equal contacto.email_contacto"
            );
        }

        // REQ-PCBF-001 (legacy): each persona defaulted to tipo_persona=Natural.
        // Commit 7a2d33c dropped `personas.tipo_persona` ENUM; the new
        // personas table is type-agnostic and the Natural/Juridica split
        // lives on `entidad.tipo_persona`. Backfilled personas are simply
        // inserted without a tipo_persona column; we verify the
        // equivalent invariant — all 3 personas exist and are linked —
        // instead of checking the dropped column.
        $this->assertSame(
            3,
            DB::table('personas')->count(),
            'all 3 personas must exist after the backfill'
        );
    }

    // ── 4.4 — idempotency ──────────────────────────────────────────────

    #[Test]
    public function second_run_is_a_no_op_skipped_already_linked(): void
    {
        $entidad = $this->makeEntidad();
        foreach ([['D', 'a'], ['E', 'b'], ['F', 'c']] as [$name, $letter]) {
            Contacto::create([
                'entidad_id' => $entidad->id,
                'nombres' => $name, 'apellidos' => $letter,
                'email_contacto' => strtolower($name).'@example.test',
                'estado' => 'Activo', 'score' => 0,
            ]);
        }

        // First run.
        Artisan::call('crm:backfill-personas-from-contacto', ['--force' => true]);
        $this->assertSame(3, DB::table('personas')->count(), 'first run creates 3 personas');

        // Second run — same state.
        $exit = Artisan::call('crm:backfill-personas-from-contacto', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, 'idempotent run must exit 0');
        $this->assertSame(3, DB::table('personas')->count(), 'second run MUST NOT create more personas (REQ-PCBF-002)');
        $this->assertStringContainsString(
            'skipped_already_linked',
            $output,
            'summary must include skipped_already_linked key (REQ-PCBF-006)'
        );
        $this->assertStringContainsString(
            'inserted',
            $output,
            'summary must include inserted key (REQ-PCBF-006)'
        );

        // No contacto has duplicates either.
        $linkedCount = DB::table('contacto')->whereNotNull('persona_id')->count();
        $this->assertSame(3, $linkedCount, '3 contactos remain linked after re-run');
    }

    // ── 4.5 — cross-entidad dedupe (AD-12) ─────────────────────────────

    #[Test]
    public function five_contactos_sharing_one_email_across_three_entidades_share_one_persona(): void
    {
        // 5 entidades, 1 contacto per entidad, same email "shared@example.test".
        // (Contacto has UNIQUE(entidad_id, email_contacto) so the same email
        // can exist across entidades but only once per entidad.)
        $names = [
            ['Ana', 'A'],
            ['Beto', 'B'],
            ['Cira', 'C'],
            ['Dario', 'D'],
            ['Eli', 'E'],
        ];
        foreach ($names as [$name, $letter]) {
            $entidad = $this->makeEntidad("Ent-{$name}");
            Contacto::create([
                'entidad_id' => $entidad->id,
                'nombres' => $name, 'apellidos' => $letter,
                'email_contacto' => 'shared@example.test',
                'estado' => 'Activo', 'score' => 0,
            ]);
        }

        $exit = Artisan::call('crm:backfill-personas-from-contacto', ['--force' => true]);

        $this->assertSame(0, $exit, 'cross-entidad dedupe must exit 0');
        $this->assertSame(1, DB::table('personas')->count(), '1 persona must be created for the shared email (AD-12)');
        $this->assertSame(
            5,
            DB::table('contacto')->whereNotNull('persona_id')->count(),
            'all 5 contactos must link to the single shared persona (REQ-PCBF-003 / AD-12)'
        );

        // Every contacto points at the same persona id.
        $personaIds = DB::table('contacto')->whereNotNull('persona_id')->pluck('persona_id')->unique()->values()->all();
        $this->assertCount(1, $personaIds, 'all 5 contactos must reference the same persona id');
    }

    // ── 4.6 — NULL-email contactos are skipped + logged ────────────────

    #[Test]
    public function contactos_with_null_email_are_skipped_with_warning(): void
    {
        $entidad = $this->makeEntidad();
        // 2 valid emails + 2 NULL emails.
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Fede', 'apellidos' => 'F',
            'email_contacto' => 'fede@example.test', 'estado' => 'Activo', 'score' => 0,
        ]);
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Gala', 'apellidos' => 'G',
            'email_contacto' => 'gala@example.test', 'estado' => 'Activo', 'score' => 0,
        ]);
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Hugo', 'apellidos' => 'H',
            'email_contacto' => null, 'estado' => 'Activo', 'score' => 0,
        ]);
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Iris', 'apellidos' => 'I',
            'email_contacto' => null, 'estado' => 'Activo', 'score' => 0,
        ]);

        $exit = Artisan::call('crm:backfill-personas-from-contacto', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, 'NULL-email rows are skipped, not an error (REQ-PCBF-004)');
        $this->assertSame(2, DB::table('personas')->count(), 'only the 2 non-null-email contactos spawn personas');
        $this->assertSame(
            2,
            DB::table('contacto')->whereNotNull('persona_id')->count(),
            'only the 2 non-null-email contactos are linked'
        );

        // The 2 NULL-email contactos stay unlinked.
        $nullEmailContactos = DB::table('contacto')->whereNull('email_contacto')->get();
        foreach ($nullEmailContactos as $c) {
            $this->assertNull(
                $c->persona_id,
                "contacto id={$c->id} (NULL email) must NOT be linked (REQ-PCBF-004)"
            );
        }

        // Summary counts NULL-email skips.
        $this->assertStringContainsString(
            'skipped_null_email',
            $output,
            'summary must include skipped_null_email key (REQ-PCBF-006)'
        );

        // REQ-PCBF-004: stable log code `backfill.skipped.null_email` MUST be
        // emitted for each NULL-email row. We re-run with Log::spy() active
        // so the warning call is captured by Mockery and we can assert it.
        Log::spy();

        $entidad2 = $this->makeEntidad();
        Contacto::create([
            'entidad_id' => $entidad2->id,
            'nombres' => 'Spy', 'apellidos' => 'Me',
            'email_contacto' => null, 'estado' => 'Activo', 'score' => 0,
        ]);

        Artisan::call('crm:backfill-personas-from-contacto', ['--force' => true]);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                return is_string($message)
                    && str_contains($message, 'backfill.skipped.null_email');
            })
            ->atLeast()->once();
    }

    // ── 4.7 — per-row error isolation ──────────────────────────────────

    #[Test]
    public function one_bad_row_logs_error_others_succeed_command_exits_one(): void
    {
        $entidad = $this->makeEntidad();
        // 3 healthy contactos.
        foreach ([['J', 'a'], ['K', 'b'], ['L', 'c']] as [$name, $letter]) {
            Contacto::create([
                'entidad_id' => $entidad->id,
                'nombres' => $name, 'apellidos' => $letter,
                'email_contacto' => strtolower($name).'@example.test',
                'estado' => 'Activo', 'score' => 0,
            ]);
        }
        // 1 contacto whose `nombres` is longer than 100 chars (the
        // `personas.nombres` VARCHAR(100) cap). When the backfill
        // inserts a new persona row, the long `nombres` triggers a
        // SQL truncation error — the natural "bad row" trigger that
        // exercises per-row isolation. (We can't use a long email
        // anymore because Commit 4 raised the `emails.email` cap to
        // 255; the legacy 150-char cap used to be the trigger but
        // that column is gone.)
        $longNombres = str_repeat('X', 150);
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => $longNombres, 'apellidos' => 'Row',
            'email_contacto' => 'bad@example.test',
            'estado' => 'Activo', 'score' => 0,
        ]);

        $exit = Artisan::call('crm:backfill-personas-from-contacto', ['--force' => true]);
        $output = Artisan::output();

        // Per-row error isolation: command exits 1 because errors > 0,
        // but the 3 healthy contactos are still linked (REQ-PCBF-005).
        $this->assertSame(1, $exit, 'command exits 1 when any row errors (REQ-PCBF-005)');
        $this->assertSame(3, DB::table('personas')->count(), '3 personas created for the 3 healthy contactos');
        $this->assertSame(
            3,
            DB::table('contacto')->whereNotNull('persona_id')->count(),
            '3 healthy contactos must be linked even though one row errored'
        );

        $this->assertStringContainsString('errors', $output, 'summary must include errors key (REQ-PCBF-006)');
        $this->assertStringContainsString('inserted', $output, 'summary must include inserted key (REQ-PCBF-006)');
    }

    // ── 4.8 — machine-readable summary ─────────────────────────────────

    #[Test]
    public function summary_output_is_machine_readable_with_seven_documented_keys(): void
    {
        $entidad = $this->makeEntidad();
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'M', 'apellidos' => 'X',
            'email_contacto' => 'mx@example.test',
            'estado' => 'Activo', 'score' => 0,
        ]);
        Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'N', 'apellidos' => 'Y',
            'email_contacto' => null, // skipped_null_email
            'estado' => 'Activo', 'score' => 0,
        ]);

        Artisan::call('crm:backfill-personas-from-contacto', ['--force' => true]);
        $output = Artisan::output();

        // REQ-PCBF-006 mandates exactly these 7 keys in the summary table.
        $required = [
            'inserted',
            'updated',
            'skipped_already_linked',
            'skipped_null_email',
            'skipped_duplicate',
            'errors',
            'dry_run',
        ];
        foreach ($required as $key) {
            $this->assertStringContainsString(
                $key,
                $output,
                "summary must include key '{$key}' (REQ-PCBF-006)"
            );
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function makeEntidad(string $name = 'Test'): Entidad
    {
        return Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => $name,
            'identificacion' => 'ENT-'.uniqid(),
            'estado' => 'Activo',
        ]);
    }
}
