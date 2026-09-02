<?php

namespace Tests\Feature\Migration;

use App\Models\Entidad;
use App\Models\Persona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\CRM\Models\Contacto;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-G: seguimiento FK swap (REQ-SEG-001..005).
 *
 * Replaces `seguimiento.contacto_id` (FK → `contacto.id`) with
 * `seguimiento.persona_id` (FK → `personas.id`). The migration is HIGH-risk
 * because it is partially IRREVERSIBLE: once `contacto_id` is dropped, a
 * rollback can only best-effort recover values via `contacto.persona_id`
 * mapping, which is ambiguous when a persona has multiple contactos (a
 * single persona can be a contacto in several entidades — AD-12 cross-entidad
 * dedupe). The DOWN() picks MIN(id) and documents the lossy case in its
 * docblock.
 *
 * Safety guard — pre-check audit query (REQ-SEG-002):
 *   SELECT COUNT(*) FROM seguimiento s
 *   LEFT JOIN contacto c ON c.id = s.contacto_id
 *   WHERE s.contacto_id IS NOT NULL AND c.persona_id IS NULL
 * If this returns > 0, the migration aborts with a RuntimeException that
 * names the offending count, and the schema is unchanged (the transaction
 * rolls back).
 *
 * Strict TDD: tests 5a.1–5a.5 are RED before the migration ships. GREEN
 * tasks 5a.6 (up()) + 5a.7 (down()) make them pass.
 *
 * Cross-references:
 *   - spec: specs/seguimiento-modificado/spec.md (REQ-SEG-001..005)
 *   - design: design.md AD-1 (sequencing after backfill), AD-10
 *     (atomic transaction with pre-check), §5.3 (sequence diagram),
 *     R-1 (data preservation invariant), R-12 (driver-aware SQL)
 */
class SeguimientoFkSwapTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_NAME = '2026_08_28_000099_swap_seguimiento_contacto_to_persona_id';

    // ── 5a.1 — data preservation: row count + persona_id mapping ─────

    /**
     * REQ-SEG-001: when the migration runs against a fully-backfilled DB,
     * every seguimiento row that had a non-NULL `contacto_id` must end up
     * with the corresponding `persona_id` (copied from `contacto.persona_id`).
     * Row count must be preserved.
     */
    #[Test]
    public function migration_preserves_rows_and_copies_contacto_persona_id_to_seguimiento(): void
    {
        // The swap is already applied by RefreshDatabase. Roll it back so we
        // can craft a pre-PR-G state and re-apply.
        // Commit 1+2 added 4 migrations on top of PR-G
        // (`2026_08_29_000001..000004`), and Commit 2.5 added the
        // pagos_cliente migration. So to reach the pre-PR-G state we
        // roll back 6 steps (PR-G is the 6th from the top).
        $this->artisan('migrate:rollback', ['--step' => 6])->assertExitCode(0);
        $this->assertTrue(
            Schema::hasColumn('seguimiento', 'contacto_id'),
            'precondition: contacto_id must exist after rolling back PR-G'
        );

        // Seed 5 seguimientos → 5 contactos → 5 personas. Each contacto has
        // its persona_id populated (the backfill invariant).
        $seed = $this->seedSeguimientosWithPersonas(5);

        // Apply the swap.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        // Precondition that makes RED loud: persona_id column must exist
        // after the migration. In RED (migration file missing) this fails
        // with a clear message.
        $this->assertTrue(
            Schema::hasColumn('seguimiento', 'persona_id'),
            'PR-G migration must add seguimiento.persona_id column (REQ-SEG-001)'
        );

        // Row count preserved (REQ-SEG-001).
        $this->assertSame(
            5,
            DB::table('seguimiento')->count(),
            'seguimiento row count must be preserved (REQ-SEG-001)'
        );

        // Every seguimiento.persona_id matches the seeded contacto.persona_id.
        foreach ($seed as $row) {
            $reloaded = DB::table('seguimiento')->where('id', $row['seguimiento_id'])->first();
            $this->assertNotNull(
                $reloaded,
                "seguimiento #{$row['seguimiento_id']} must survive the migration"
            );
            $this->assertSame(
                (int) $row['persona_id'],
                (int) $reloaded->persona_id,
                "seguimiento #{$row['seguimiento_id']}.persona_id must equal contacto.persona_id"
            );
        }
    }

    // ── 5a.2 — contacto_id column dropped, persona_id column added ────

    /**
     * REQ-SEG-001 (schema swap): the migration must drop the
     * `seguimiento.contacto_id` column AND its FK constraint, and add
     * `seguimiento.persona_id` as the replacement.
     */
    #[Test]
    public function migration_drops_contacto_id_and_adds_persona_id_column(): void
    {
        // Commit 1+2 added 4 migrations on top of PR-G
        // (`2026_08_29_000001..000004`), and Commit 2.5 added the
        // pagos_cliente migration. So to reach the pre-PR-G state we
        // roll back 6 steps (PR-G is the 6th from the top).
        $this->artisan('migrate:rollback', ['--step' => 6])->assertExitCode(0);
        $this->seedSeguimientosWithPersonas(3);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $this->assertFalse(
            Schema::hasColumn('seguimiento', 'contacto_id'),
            'seguimiento.contacto_id must be dropped after migration (REQ-SEG-001)'
        );
        $this->assertTrue(
            Schema::hasColumn('seguimiento', 'persona_id'),
            'seguimiento.persona_id must exist after migration (REQ-SEG-001)'
        );

        // The FK constraint name must follow the convention
        // `seguimiento_persona_id_foreign` so DOWN() can find it.
        $fks = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE '
            .'WHERE TABLE_SCHEMA = DATABASE() '
            ."AND TABLE_NAME = 'seguimiento' "
            ."AND REFERENCED_TABLE_NAME = 'personas' "
            ."AND REFERENCED_COLUMN_NAME = 'id'"
        );
        $fkNames = array_map(fn ($r) => $r->CONSTRAINT_NAME, $fks);
        $this->assertContains(
            'seguimiento_persona_id_foreign',
            $fkNames,
            'seguimiento must have FK seguimiento_persona_id_foreign → personas.id'
        );

        // The old FK to contacto must be gone.
        $oldFks = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE '
            .'WHERE TABLE_SCHEMA = DATABASE() '
            ."AND TABLE_NAME = 'seguimiento' "
            ."AND REFERENCED_TABLE_NAME = 'contacto'"
        );
        $this->assertEmpty(
            $oldFks,
            'FK to contacto must be dropped after migration (REQ-SEG-001)'
        );
    }

    // ── 5a.3 — pre-check failure aborts the migration safely ──────────

    /**
     * REQ-SEG-002: if any `seguimiento.contacto_id` points to a contacto
     * whose `persona_id` is NULL (backfill incomplete), the migration must
     * throw a RuntimeException with the offending count and leave the
     * schema unchanged.
     */
    #[Test]
    public function migration_throws_when_backfill_incomplete_and_leaves_schema_unchanged(): void
    {
        // Commit 1+2 added 4 migrations on top of PR-G
        // (`2026_08_29_000001..000004`), and Commit 2.5 added the
        // pagos_cliente migration. So to reach the pre-PR-G state we
        // roll back 6 steps (PR-G is the 6th from the top).
        $this->artisan('migrate:rollback', ['--step' => 6])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('seguimiento', 'contacto_id'));

        // Create a contacto WITHOUT persona_id (backfill missing).
        $entidad = $this->createEntidad();
        $contactoSinPersona = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'SinBackfill',
            'apellidos' => 'X',
            'estado' => 'Activo',
            'score' => 0,
            // persona_id intentionally omitted (NULL).
        ]);
        $this->assertNull(
            $contactoSinPersona->persona_id,
            'precondition: contacto must have persona_id NULL to trigger pre-check failure'
        );

        // Create a seguimiento that references this contacto.
        DB::table('seguimiento')->insert([
            'entidad_id' => $entidad->id,
            'contacto_id' => $contactoSinPersona->id,
            'tipo' => 'Nota',
            'fecha' => '2026-08-28',
            'estado' => 'Pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Apply the swap. The pre-check MUST throw a RuntimeException that
        // propagates out of the artisan command — Laravel's Migrator does
        // NOT catch exceptions from migration closures, so `php artisan
        // migrate` exits with the exception, which is the loud failure
        // mode the spec wants (REQ-SEG-002).
        //
        // RED behavior (PR-G missing): no migration runs, no exception
        // thrown, the catch block is not entered, the test fails on the
        // $this->fail() assertion below.
        // GREEN behavior: RuntimeException is thrown with the offending
        // count in the message.
        try {
            $this->artisan('migrate', ['--force' => true])->run();
            $this->fail('Expected RuntimeException with "FK swap unsafe" but migrate completed silently');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                'FK swap unsafe',
                $e->getMessage(),
                'pre-check failure must mention "FK swap unsafe" (REQ-SEG-002)'
            );
            $this->assertStringContainsString(
                '1 seguimiento',
                $e->getMessage(),
                'pre-check failure must include the offending count (REQ-SEG-002)'
            );
        }

        // Schema unchanged (REQ-SEG-002 — transaction rolled back).
        $this->assertTrue(
            Schema::hasColumn('seguimiento', 'contacto_id'),
            'seguimiento.contacto_id must still exist after pre-check failure'
        );
        $this->assertFalse(
            Schema::hasColumn('seguimiento', 'persona_id'),
            'seguimiento.persona_id must NOT be added if pre-check fails'
        );

        // Original row survives with contacto_id intact.
        $row = DB::table('seguimiento')->where('contacto_id', $contactoSinPersona->id)->first();
        $this->assertNotNull(
            $row,
            'the original seguimiento row must survive the pre-check failure untouched'
        );
        $this->assertSame(
            (int) $contactoSinPersona->id,
            (int) $row->contacto_id,
            'seguimiento.contacto_id must be unchanged'
        );
    }

    // ── 5a.4 — rollback re-adds contacto_id and recovers values ───────

    /**
     * REQ-SEG-003: `migrate:rollback --step=1` re-adds `contacto_id`
     * (nullable) and best-effort recovers values via
     * `JOIN contacto ON contacto.persona_id = seguimiento.persona_id`.
     *
     * KNOWN LOSSY CASE (documented in DOWN() docblock): a single persona
     * can be a contacto in multiple entidades (AD-12 cross-entidad dedupe),
     * so the JOIN can match more than one contacto. The DOWN() picks
     * `MIN(id)` (deterministic) and silently sets `contacto_id = NULL`
     * for rows whose persona mapping is ambiguous or whose persona was
     * hard-deleted. This test covers the unambiguous case (1 contacto
     * per persona).
     */
    #[Test]
    public function rollback_re_adds_contacto_id_and_recovers_values_for_unambiguous_case(): void
    {
        // Precondition (loud RED if PR-G is missing): seguimiento must have
        // the persona_id column for the seed INSERT below to work.
        $this->assertTrue(
            Schema::hasColumn('seguimiento', 'persona_id'),
            'precondition: PR-G must be applied (seguimiento.persona_id exists) before testing rollback'
        );

        // Default state: PR-G applied. Insert seguimientos that reference
        // personas, each persona owning exactly one contacto (unambiguous).
        $seed = $this->seedSeguimientosByPersona(3);

        // Roll back PR-G.
        // Commit 1+2 added 4 migrations on top of PR-G
        // (`2026_08_29_000001..000004`), and Commit 2.5 added the
        // pagos_cliente migration. So to reach the pre-PR-G state we
        // roll back 6 steps (PR-G is the 6th from the top).
        $this->artisan('migrate:rollback', ['--step' => 6])->assertExitCode(0);

        // contacto_id column is back; persona_id is gone.
        $this->assertTrue(
            Schema::hasColumn('seguimiento', 'contacto_id'),
            'seguimiento.contacto_id must be re-added after rollback (REQ-SEG-003)'
        );
        $this->assertFalse(
            Schema::hasColumn('seguimiento', 'persona_id'),
            'seguimiento.persona_id must be dropped after rollback (REQ-SEG-003)'
        );

        // FK to contacto is back.
        $oldFks = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE '
            .'WHERE TABLE_SCHEMA = DATABASE() '
            ."AND TABLE_NAME = 'seguimiento' "
            ."AND REFERENCED_TABLE_NAME = 'contacto'"
        );
        $oldFkNames = array_map(fn ($r) => $r->CONSTRAINT_NAME, $oldFks);
        $this->assertContains(
            'seguimiento_contacto_id_foreign',
            $oldFkNames,
            'FK to contacto must be re-added after rollback (REQ-SEG-003)'
        );

        // For the unambiguous case (1 contacto per persona), the JOIN
        // recovery produces the original contacto_id. The lossy case
        // (multiple contactos sharing a persona, persona hard-deleted) is
        // documented in the migration's DOWN() docblock and is not covered
        // here — covered by design.md §5.3 Key Learning #4.
        foreach ($seed as $row) {
            $reloaded = DB::table('seguimiento')->where('id', $row['seguimiento_id'])->first();
            $this->assertNotNull($reloaded, "seguimiento #{$row['seguimiento_id']} must survive rollback");
            $this->assertSame(
                (int) $row['contacto_id'],
                (int) $reloaded->contacto_id,
                "seguimiento #{$row['seguimiento_id']}.contacto_id must be recovered via persona JOIN (unambiguous case)"
            );
        }
    }

    // ── 5a.5 — SQLite compatibility guard: data-preservation invariant

    /**
     * REQ-SEG-005 + R-12: the migration must assert the data-preservation
     * INVARIANT, not the literal FK syntax. FK constraint names and column
     * ordering differ between MariaDB and SQLite, but the invariant —
     * "every row that had non-NULL contacto_id pre-migration has non-NULL
     * persona_id post-migration" — is portable.
     *
     * Tests run on MariaDB per phpunit.xml, so we don't need to switch
     * drivers; we just assert the invariant directly via a raw query.
     * Reference: design.md §5.3 "SQLite :memory: test compatibility" and
     * design.md Risk #R-12 (driver-aware SQL).
     */
    #[Test]
    public function migration_asserts_data_preservation_invariant_r12(): void
    {
        // Commit 1+2 added 4 migrations on top of PR-G
        // (`2026_08_29_000001..000004`), and Commit 2.5 added the
        // pagos_cliente migration. So to reach the pre-PR-G state we
        // roll back 6 steps (PR-G is the 6th from the top).
        $this->artisan('migrate:rollback', ['--step' => 6])->assertExitCode(0);

        // Precondition (loud RED if PR-G is missing): the migration must
        // re-add persona_id. We assert after migrate() below.
        $this->assertTrue(
            Schema::hasColumn('seguimiento', 'contacto_id'),
            'precondition after rollback: contacto_id must be back (otherwise PR-G was never applied)'
        );

        // Mix: 3 rows with non-NULL contacto_id (backfilled), 2 rows with
        // NULL contacto_id (unattached historical data). The invariant
        // guarantees exactly 3 rows end up with non-NULL persona_id.
        $entidad = $this->createEntidad();
        $contactos = [];
        for ($i = 0; $i < 3; $i++) {
            $persona = $this->createPersona();
            $contactos[] = Contacto::create([
                'entidad_id' => $entidad->id,
                'nombres' => "WithBackfill{$i}",
                'apellidos' => "P{$i}",
                'persona_id' => $persona->id,
                'estado' => 'Activo',
                'score' => 0,
            ]);
        }

        $now = now();
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = [
                'entidad_id' => $entidad->id,
                'contacto_id' => $contactos[$i]->id,
                'tipo' => 'Nota',
                'fecha' => '2026-08-28',
                'estado' => 'Pendiente',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        // 2 historical rows with NULL contacto_id (no persona linkage).
        for ($i = 0; $i < 2; $i++) {
            $rows[] = [
                'entidad_id' => $entidad->id,
                'contacto_id' => null,
                'tipo' => 'Nota',
                'fecha' => '2026-08-28',
                'estado' => 'Pendiente',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('seguimiento')->insert($rows);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        // Loud RED if PR-G is missing: persona_id column must exist after migrate.
        $this->assertTrue(
            Schema::hasColumn('seguimiento', 'persona_id'),
            'precondition after migrate: PR-G must add seguimiento.persona_id (R-12 invariant requires the column to exist)'
        );

        // R-12 invariant: count of rows with non-NULL persona_id equals
        // the count of pre-migration rows with non-NULL contacto_id (3).
        $rowsWithPersonaId = DB::table('seguimiento')->whereNotNull('persona_id')->count();
        $this->assertSame(
            3,
            $rowsWithPersonaId,
            'R-12 data-preservation invariant: every row with non-NULL contacto_id pre-migration must have non-NULL persona_id post-migration'
        );

        // Total row count is the invariant's outer bound.
        $this->assertSame(
            5,
            DB::table('seguimiento')->count(),
            'R-12: total row count must be preserved'
        );

        // The 2 historical NULL-contacto rows stay NULL on persona_id.
        $nullPersonaRows = DB::table('seguimiento')
            ->whereNull('persona_id')
            ->count();
        $this->assertSame(
            2,
            $nullPersonaRows,
            'R-12: rows with NULL contacto_id pre-migration stay NULL on persona_id post-migration'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function createEntidad(array $overrides = []): Entidad
    {
        return Entidad::create(array_merge([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Test Entity',
            'identificacion' => 'TEST-'.uniqid(),
            'estado' => 'Activo',
        ], $overrides));
    }

    private function createPersona(array $overrides = []): Persona
    {
        return Persona::create(array_merge([
            'nombres' => 'Persona',
            'apellidos' => 'Test',
            'email_principal' => 'persona-'.uniqid().'@example.test',
        ], $overrides));
    }

    /**
     * Pre-PR-G seed: insert N seguimientos + N contactos (each contacto has
     * a populated persona_id, the backfill invariant) linked together.
     * Used to assert the UP() data-copy step.
     *
     * @return array<int, array{seguimiento_id: int, contacto_id: int, persona_id: int}>
     */
    private function seedSeguimientosWithPersonas(int $n): array
    {
        $entidad = $this->createEntidad();
        $seed = [];
        $now = now();

        for ($i = 0; $i < $n; $i++) {
            $persona = $this->createPersona();
            $contacto = Contacto::create([
                'entidad_id' => $entidad->id,
                'nombres' => "Seed{$i}",
                'apellidos' => "P{$i}",
                'persona_id' => $persona->id,
                'estado' => 'Activo',
                'score' => 0,
            ]);

            $segId = DB::table('seguimiento')->insertGetId([
                'entidad_id' => $entidad->id,
                'contacto_id' => $contacto->id,
                'tipo' => 'Nota',
                'fecha' => '2026-08-28',
                'estado' => 'Pendiente',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $seed[] = [
                'seguimiento_id' => $segId,
                'contacto_id' => $contacto->id,
                'persona_id' => $persona->id,
            ];
        }

        return $seed;
    }

    /**
     * Post-PR-G seed: insert N seguimientos with persona_id, each linked
     * to a contacto via the persona. Used to test DOWN() rollback recovery.
     * Each persona has exactly one contacto (unambiguous JOIN match).
     *
     * @return array<int, array{seguimiento_id: int, contacto_id: int, persona_id: int}>
     */
    private function seedSeguimientosByPersona(int $n): array
    {
        $entidad = $this->createEntidad();
        $seed = [];
        $now = now();

        for ($i = 0; $i < $n; $i++) {
            $persona = $this->createPersona();
            $contacto = Contacto::create([
                'entidad_id' => $entidad->id,
                'nombres' => "Post{$i}",
                'apellidos' => "P{$i}",
                'persona_id' => $persona->id,
                'estado' => 'Activo',
                'score' => 0,
            ]);

            $segId = DB::table('seguimiento')->insertGetId([
                'entidad_id' => $entidad->id,
                'persona_id' => $persona->id,
                'tipo' => 'Nota',
                'fecha' => '2026-08-28',
                'estado' => 'Pendiente',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $seed[] = [
                'seguimiento_id' => $segId,
                'contacto_id' => $contacto->id,
                'persona_id' => $persona->id,
            ];
        }

        return $seed;
    }
}
