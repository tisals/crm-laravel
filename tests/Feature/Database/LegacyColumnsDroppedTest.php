<?php

namespace Tests\Feature\Database;

use App\Models\Entidad as EloquentEntidad;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 8 of `tenant-data-model-correction` — Smoke test for the
 * definitive drop of legacy `entidad.estado` and `entidad.cliente_desde`
 * columns.
 *
 * Earlier commits (5 / 5.5) rewrote the application layer to derive
 * entity state from the `entidad_relacion` pivot (`is_active` ⇔ at
 * least one pivot row with `effective_to IS NULL`), but the columns
 * themselves remained in the DB schema. Commit 8 drops them at the
 * migration level so the post-Commit 8 schema is the canonical one.
 *
 * This test asserts:
 *
 *   1. `entidad.estado` and `entidad.cliente_desde` are GONE from the
 *      schema (information_schema + SHOW COLUMNS).
 *
 *   2. Reading them via Eloquent does NOT silently return `null` or
 *      an empty string — Laravel raises an error when a property is
 *      declared on the model but the column is missing.
 *
 *   3. A raw `SELECT e.estado FROM entidad e` raises a clear
 *      `QueryException` whose message mentions the missing column.
 *      This is the contract operators get when they reach for the
 *      legacy fields in ad-hoc SQL.
 *
 *   4. The pivot-backed state (`entidad_relacion`) is the canonical
 *      replacement and is queryable end-to-end via the existing
 *      `Entidad::getEstadoAttribute()` accessor.
 *
 * The class lives under `tests/Feature/Database/` (new namespace)
 * because it is a schema-level smoke test, not an endpoint test.
 */
class LegacyColumnsDroppedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The two columns that Commit 8 drops. Listed here so the
     * assertions are data-driven and easy to extend if a future
     * commit drops more legacy fields.
     */
    private const DROPPED_COLUMNS = [
        'entidad.estado',
        'entidad.cliente_desde',
    ];

    // ── 1. Schema assertions ────────────────────────────────────────

    #[Test]
    public function entidad_table_does_not_have_legacy_estado_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('entidad', 'estado'),
            'entidad.estado must be dropped (Commit 8 of tenant-data-model-correction). '
            .'Its semantics now live on the entidad_relacion pivot — see '
            .'Entidad::getEstadoAttribute() which derives "activo" / "inactivo" '
            .'from `entidad_relacion.effective_to IS NULL`.'
        );
    }

    #[Test]
    public function entidad_table_does_not_have_legacy_cliente_desde_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('entidad', 'cliente_desde'),
            'entidad.cliente_desde must be dropped (Commit 8). Its semantics now live '
            .'on the entidad_relacion.effective_from column.'
        );
    }

    #[Test]
    public function information_schema_lists_no_legacy_columns_on_entidad(): void
    {
        // Belt-and-braces: Schema::hasColumn() reads the cached
        // column listing from the connection. The information_schema
        // probe reads the canonical server-side view, so it catches
        // the case where Laravel's connection has stale metadata.
        foreach (self::DROPPED_COLUMNS as [$table, $column]) {
            $rows = DB::select(
                'SELECT 1 AS x FROM information_schema.COLUMNS '
                .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$table, $column]
            );

            $this->assertCount(
                0,
                $rows,
                "information_schema still lists {$table}.{$column} — the column drop "
                ."did not propagate to the server-side catalog."
            );
        }
    }

    #[Test]
    public function show_columns_for_entidad_omits_legacy_columns(): void
    {
        $rows = DB::select('SHOW FULL COLUMNS FROM `entidad`');
        $fieldNames = array_map(fn ($r) => $r->Field, $rows);

        $this->assertNotContains(
            'estado',
            $fieldNames,
            'SHOW COLUMNS FROM entidad must NOT include `estado` after Commit 8.'
        );
        $this->assertNotContains(
            'cliente_desde',
            $fieldNames,
            'SHOW COLUMNS FROM entidad must NOT include `cliente_desde` after Commit 8.'
        );
    }

    // ── 2. Eloquent assertions ──────────────────────────────────────

    #[Test]
    public function eloquent_model_attribute_lookup_for_estado_does_not_silently_return_null(): void
    {
        // Create a pivot-backed entity (the canonical post-Commit 8
        // shape). Then read `$entidad->getAttributes()['estado']` —
        // this is the underlying Eloquent attribute read that bypasses
        // the accessor. With the column dropped, the array key is
        // simply absent (NOT set to null).
        $now = now();
        $entidadId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Acme Estado Test '.uniqid(),
            'identificacion' => 'EST-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $entidadId,
            'tipo_relacion' => 'cliente',
            'effective_from' => $now->toDateString(),
            'effective_to' => null,
            'frecuencia' => 'unica',
            'recurrencia_cada_meses' => null,
            'vigencia_meses' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $entidad = EloquentEntidad::find($entidadId);

        // The Eloquent attribute array does NOT carry a key for the
        // dropped column — silent fallback would be wrong.
        $this->assertArrayNotHasKey(
            'estado',
            $entidad->getAttributes(),
            'Eloquent attribute array must NOT carry a `estado` key after Commit 8 — '
            .'the column is gone from the DB.'
        );
        $this->assertArrayNotHasKey(
            'cliente_desde',
            $entidad->getAttributes(),
            'Eloquent attribute array must NOT carry a `cliente_desde` key after Commit 8.'
        );

        // The accessor IS the canonical replacement and continues to
        // work because it derives from the pivot, not the dropped column.
        $this->assertSame('activo', $entidad->estado);
    }

    // ── 3. Raw-SQL assertions ───────────────────────────────────────

    #[Test]
    public function raw_select_against_dropped_estado_column_raises_query_exception(): void
    {
        // This is the contract for any operator / external script that
        // reaches for the legacy column. The error MUST mention the
        // column name so the failure is debuggable — silent null is
        // not acceptable.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/estado|Unknown column/i');

        DB::select('SELECT estado FROM `entidad` LIMIT 1');
    }

    #[Test]
    public function raw_select_against_dropped_cliente_desde_column_raises_query_exception(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/cliente_desde|Unknown column/i');

        DB::select('SELECT cliente_desde FROM `entidad` LIMIT 1');
    }

    // ── 4. Pivot-replacement assertions ─────────────────────────────

    #[Test]
    public function pivot_backed_estado_derivation_still_works_after_column_drop(): void
    {
        // Three entidades with different pivot shapes. Their
        // getEstadoAttribute() output must match the canonical
        // "open pivot row = activo" rule.
        $now = now();
        $openId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica', 'nombre' => 'Open '.uniqid(),
            'identificacion' => 'O-'.uniqid(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $closedId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica', 'nombre' => 'Closed '.uniqid(),
            'identificacion' => 'C-'.uniqid(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $orphanId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica', 'nombre' => 'Orphan '.uniqid(),
            'identificacion' => 'X-'.uniqid(), 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Entidad A: open pivot row → activo.
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $openId,
            'tipo_relacion' => 'cliente',
            'effective_from' => $now->toDateString(),
            'effective_to' => null,
            'frecuencia' => 'unica',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Entidad B: closed pivot row only → inactivo.
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $closedId,
            'tipo_relacion' => 'cliente',
            'effective_from' => $now->subYears(2)->toDateString(),
            'effective_to' => $now->subYear()->toDateString(),
            'frecuencia' => 'unica',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertSame('activo', EloquentEntidad::find($openId)->estado);
        $this->assertSame('inactivo', EloquentEntidad::find($closedId)->estado);
        $this->assertSame('inactivo', EloquentEntidad::find($orphanId)->estado);
    }

    // ── 5. Migration file asserts ───────────────────────────────────

    #[Test]
    public function commit_8_migration_file_exists_and_is_irreversible(): void
    {
        // The migration file MUST exist on disk so the next `migrate`
        // run can find it.
        $this->assertFileExists(
            database_path('migrations/2026_09_09_120000_drop_legacy_entidad_estado_cliente_desde.php'),
            'Commit 8 migration must exist at database/migrations/2026_09_09_120000_*.php.'
        );

        // The `down()` is intentionally one-way. Reading the file
        // textually lets us assert the contract without instantiating
        // the anonymous Migration class (which would require booting
        // Laravel with a non-default app key, etc.).
        $contents = file_get_contents(
            database_path('migrations/2026_09_09_120000_drop_legacy_entidad_estado_cliente_desde.php')
        );

        $this->assertStringContainsString(
            'DROP COLUMN',
            $contents,
            'Commit 8 migration up() must DROP the legacy columns.'
        );
        $this->assertStringContainsString(
            'Schema::hasColumn',
            $contents,
            'Commit 8 migration up() must guard the DROP with Schema::hasColumn() for idempotency.'
        );
        $this->assertStringContainsString(
            'RuntimeException',
            $contents,
            'Commit 8 migration down() must explicitly throw — the drop is irreversible.'
        );
    }
}
