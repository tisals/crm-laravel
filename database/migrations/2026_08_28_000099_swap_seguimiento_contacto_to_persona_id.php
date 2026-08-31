<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR-G (Phase 5a): swap `seguimiento.contacto_id` → `seguimiento.persona_id`.
 *
 * REPLACES the FK on `seguimiento`:
 *   - DROP `seguimiento.contacto_id` (FK → `contacto.id`)
 *   - ADD  `seguimiento.persona_id` (FK → `personas.id`, nullOnDelete)
 *
 * Hard sequencing (AD-1, AD-10):
 *   This migration MUST run AFTER `crm:backfill-personas-from-contacto`
 *   populates `contacto.persona_id` for 100% of contactos. The pre-check
 *   audit query below aborts the migration with a RuntimeException if any
 *   `seguimiento.contacto_id` references a contacto whose `persona_id` is
 *   NULL.
 *
 * Hard gate (must return 0 on the target DB BEFORE running this migration):
 *   SELECT COUNT(*) FROM seguimiento s
 *   LEFT JOIN contacto c ON c.id = s.contacto_id
 *   WHERE s.contacto_id IS NOT NULL AND c.persona_id IS NULL;
 *
 * Sequential four-phase plan (design.md §5.3):
 *   Phase A — pre-check (SELECT, throws if unsafe)
 *   Phase B — add persona_id column (nullable, no FK yet) so UPDATE can target it
 *   Phase C — data copy (UPDATE seguimiento SET persona_id = contacto.persona_id)
 *   Phase D — schema swap (DROP FK + column + index on contacto_id, ADD FK on persona_id)
 *
 * NOTE on atomicity: design.md AD-10 calls for `DB::transaction`, but
 * MariaDB/MySQL implicitly commit any active transaction when a DDL
 * statement runs. Wrapping DDL inside `DB::transaction` therefore breaks
 * Laravel's transaction counter (PDOException "There is no active
 * transaction" on commit). The safety model here is the pre-check
 * contract: a pre-check failure aborts BEFORE any DML or DDL runs, so
 * Phases B/C/D never start; if Phase C fails, DDL never runs; if Phase D
 * fails mid-way, the prior DDL steps are committed but the migration's
 * pre-check contract has already been satisfied. The pre-check is the
 * contract; the transaction wrapper is not.
 *
 * Driver-aware SQL (R-12):
 *   - MariaDB / MySQL: `UPDATE seguimiento s JOIN contacto c ON ... SET ...`
 *   - SQLite:          `UPDATE seguimiento SET persona_id = (SELECT ...)` —
 *                       SQLite supports UPDATE with subquery but not multi-
 *                       table UPDATE JOIN.
 *
 * Tests: tests/Feature/Migration/SeguimientoFkSwapTest.php (5a.1–5a.5).
 */
return new class extends Migration
{
    /**
     * Sentinel value for the pre-check failure. The same number is used
     * in the error message so the operator can grep the log.
     */
    private const PRECHECK_FAIL_MSG = 'FK swap unsafe: %d seguimiento rows have contacto_id with NULL persona_id. Run `php artisan crm:backfill-personas-from-contacto` first, then re-run migrate.';

    public function up(): void
    {
        // Idempotent: if the swap has already been applied (e.g., this
        // migration ran successfully in a previous deploy), skip.
        if (Schema::hasColumn('seguimiento', 'persona_id') && ! Schema::hasColumn('seguimiento', 'contacto_id')) {
            return;
        }

        // ── Phase A — pre-check audit query ────────────────────────────
        // AD-10: the pre-check IS the contract. If it returns > 0, abort
        // loudly. The RuntimeException aborts BEFORE Phases B/C/D run;
        // the schema is unchanged.
        $unsafeRows = DB::select('SELECT COUNT(*) AS cnt FROM seguimiento s LEFT JOIN contacto c ON c.id = s.contacto_id WHERE s.contacto_id IS NOT NULL AND c.persona_id IS NULL');

        $unsafeCount = (int) ($unsafeRows[0]->cnt ?? 0);
        if ($unsafeCount > 0) {
            throw new RuntimeException(sprintf(self::PRECHECK_FAIL_MSG, $unsafeCount));
        }

        // ── Phase B — add persona_id column (nullable, no FK yet) ─────
        // We need the destination column to exist BEFORE the UPDATE
        // runs. Adding it empty (no FK) means we can populate it safely;
        // the FK is added in Phase D after the data is in place.
        Schema::table('seguimiento', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')->nullable()->after('oportunidad_id');
        });

        // ── Phase C — data copy (atomic UPDATE) ───────────────────────
        // R-12: driver-aware UPDATE syntax.
        if (DB::getDriverName() === 'sqlite') {
            // SQLite: no multi-table UPDATE JOIN. Use a correlated
            // subquery instead. Same semantics — copy persona_id from
            // contacto into seguimiento for every row where contacto_id
            // IS NOT NULL.
            DB::statement('UPDATE seguimiento SET persona_id = ( SELECT c.persona_id FROM contacto c WHERE c.id = seguimiento.contacto_id ) WHERE contacto_id IS NOT NULL');
        } else {
            // MariaDB / MySQL: multi-table UPDATE JOIN is the canonical
            // and most readable form.
            DB::statement('UPDATE seguimiento s JOIN contacto c ON c.id = s.contacto_id SET s.persona_id = c.persona_id WHERE s.contacto_id IS NOT NULL');
        }

        // ── Phase D — schema swap (contacto_id out, persona_id FK in) ─
        // Each Schema::table call is its own DDL batch (Laravel pattern
        // for SQLite compatibility — SQLite cannot combine column drop
        // and FK creation in a single statement).
        Schema::table('seguimiento', function (Blueprint $table) {
            // Drop the FK first so the index it auto-created can be
            // removed without a "constraint needed" error.
            $table->dropForeign('seguimiento_contacto_id_foreign');
        });

        Schema::table('seguimiento', function (Blueprint $table) {
            // Drop the auto-created index (same name as the FK in
            // MariaDB; explicit drop is belt-and-suspenders for SQLite).
            $table->dropIndex('seguimiento_contacto_id_foreign');
        });

        Schema::table('seguimiento', function (Blueprint $table) {
            $table->dropColumn('contacto_id');
        });

        Schema::table('seguimiento', function (Blueprint $table) {
            $table->foreign('persona_id', 'seguimiento_persona_id_foreign')->references('id')->on('personas')->nullOnDelete();
        });
    }

    /**
     * Reverse the swap.
     *
     * KNOWN LOSSY CASE (design.md §5.3 Key Learning #4):
     *   A persona can be associated with multiple contactos (cross-entidad
     *   dedupe, AD-12). When rolling back, the recovery JOIN
     *   `JOIN contacto ON contacto.persona_id = seguimiento.persona_id`
     *   can match multiple contactos per persona. DOWN() picks the
     *   deterministic `MIN(id)` from a per-persona dedupe subquery.
     *
     *   Rows whose persona has been hard-deleted, or whose JOIN is
     *   ambiguous (multiple contactos), end up with `contacto_id = NULL`
     *   after rollback. This is the documented best-effort limitation —
     *   never use rollback to ship code; use it only for emergency
     *   recovery during the deploy window.
     *
     * The lossy case is asserted by the test 5a.4 comment (unambiguous
     * case verified) and documented here for operators reading the
     * migration history.
     */
    public function down(): void
    {
        // Idempotent: if the swap has been rolled back already, skip.
        if (Schema::hasColumn('seguimiento', 'contacto_id') && ! Schema::hasColumn('seguimiento', 'persona_id')) {
            return;
        }

        // ── Phase 1 — drop the persona_id FK (keep column for now) ────
        // We must keep the persona_id column through Phase 3 (the UPDATE)
        // because Phase 3 references it for the JOIN. The column is
        // dropped in Phase 4 after the recovery is done.
        Schema::table('seguimiento', function (Blueprint $table) {
            $table->dropForeign('seguimiento_persona_id_foreign');
        });

        // ── Phase 2 — re-add contacto_id (nullable, no FK yet) ───────
        Schema::table('seguimiento', function (Blueprint $table) {
            $table->unsignedBigInteger('contacto_id')->nullable()->after('oportunidad_id');
        });

        // ── Phase 3 — best-effort value recovery via persona JOIN ─────
        // Documented LOSSY CASE: MIN(id) per persona. Rows where the
        // persona has been hard-deleted, or where multiple contactos
        // share the same persona, end up with contacto_id = NULL.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('UPDATE seguimiento SET contacto_id = ( SELECT MIN(c.id) FROM contacto c WHERE c.persona_id = seguimiento.persona_id ) WHERE persona_id IS NOT NULL');
        } else {
            DB::statement('UPDATE seguimiento s JOIN ( SELECT persona_id, MIN(id) AS id FROM contacto WHERE persona_id IS NOT NULL GROUP BY persona_id ) c ON c.persona_id = s.persona_id SET s.contacto_id = c.id WHERE s.persona_id IS NOT NULL');
        }

        // ── Phase 4 — drop persona_id column (FK already dropped) ─────
        Schema::table('seguimiento', function (Blueprint $table) {
            $table->dropColumn('persona_id');
        });

        // ── Phase 5 — re-add the FK on contacto_id ────────────────────
        Schema::table('seguimiento', function (Blueprint $table) {
            $table->foreign('contacto_id', 'seguimiento_contacto_id_foreign')->references('id')->on('contacto')->nullOnDelete();
        });
    }
};
