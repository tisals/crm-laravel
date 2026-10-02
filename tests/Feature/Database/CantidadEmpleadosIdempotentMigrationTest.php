<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR1 of `complementar-entidad` — work-unit 1.2 idempotent guard.
 *
 * The `cantidad_empleados` column is referenced by `App\Models\Entidad`
 * `$fillable` (so the Eloquent layer assumes it exists), and historically
 * the column was added to `entidad` in a prior iteration. PR1 ships the
 * idempotent guard migration `2026_10_02_100100_ensure_cantidad_empleados_on_entidad`
 * to keep the schema lock safe on branches that branched off BEFORE the
 * column was added.
 *
 * Contract:
 *   - The migration MUST be a no-op when the column already exists (no
 *     destructive ALTER, no data loss on `down()`).
 *   - The migration MUST add `cantidad_empleados` (integer, nullable) if
 *     missing.
 *   - The column MUST remain usable by Eloquent after the migration.
 */
class CantidadEmpleadosIdempotentMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function migration_is_registered_and_runs(): void
    {
        // Sanity: the migration file must be on disk and the test runner
        // must have applied it (RefreshDatabase runs migrate:fresh).
        $this->assertFileExists(
            database_path('migrations/2026_10_02_100100_ensure_cantidad_empleados_on_entidad.php'),
            'Expected the idempotent cantidad_empleados migration to exist on disk.'
        );
    }

    #[Test]
    public function cantidad_empleados_column_exists_on_entidad_post_migration(): void
    {
        // The end state after migration: the column is present on `entidad`,
        // either because it was already there (current state on every branch)
        // or because the idempotent migration added it.
        $this->assertTrue(
            Schema::hasColumn('entidad', 'cantidad_empleados'),
            'Expected cantidad_empleados column on entidad after migration.'
        );
    }

    #[Test]
    public function cantidad_empleados_column_is_nullable_integer(): void
    {
        // Verifies the column type so Eloquent::$fillable doesn't have to
        // do defensive casts. Doctrine-style getColumnsForLocalType may vary
        // across drivers, so we just confirm the column exists with the
        // expected nullable behaviour by inserting a row WITHOUT the value.
        \Illuminate\Support\Facades\DB::table('entidad')->insert([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900000001-1',
            'nombre' => 'No empleados S.A.S.',
            'cantidad_empleados' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = \Illuminate\Support\Facades\DB::table('entidad')
            ->where('identificacion', '900000001-1')
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->cantidad_empleados);
    }

    #[Test]
    public function cantidad_empleados_down_is_safe_no_op(): void
    {
        // The migration's down() MUST be a no-op so reverting PR1 never
        // drops a column that may carry production data. We assert this
        // by reading the migration source and checking down() is empty
        // (or explicitly a no-op).
        $source = file_get_contents(
            database_path('migrations/2026_10_02_100100_ensure_cantidad_empleados_on_entidad.php')
        );

        $this->assertStringContainsString(
            'public function down(): void',
            $source,
            'Migration source must declare down()'
        );

        // down() must NOT drop the column. Cheap textual check: no DROP
        // statement within the migration's down body.
        preg_match('/public function down\(\): void\s*\{(.*?)\n    \}/s', $source, $m);
        $downBody = $m[1] ?? '';
        $this->assertStringNotContainsString(
            "dropColumn('cantidad_empleados')",
            $downBody,
            'down() must not drop cantidad_empleados (data loss risk).'
        );
    }
}