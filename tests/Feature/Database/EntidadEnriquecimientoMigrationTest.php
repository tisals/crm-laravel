<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR1 of `complementar-entidad` — entity_empresa_enrichment + decreto_768-risk-matrix.
 *
 * Work-units 1.1 + 1.2:
 *   - entidad_enriquecimiento annex table (10 columns, FK + UNIQUE)
 *   - cantidad_empleados idempotent column on entidad
 *
 * Both tests run after `RefreshDatabase` migrates the full schema, so they
 * assert the END STATE of the migration suite (not the intermediate state
 * during migration).
 */
class EntidadEnriquecimientoMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function entidad_enriquecimiento_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasTable('entidad_enriquecimiento'),
            'Expected the entidad_enriquecimiento annex table to exist after migration.'
        );

        $expected = [
            'id',
            'entidad_id',
            'nit',
            'ciiu_codigo',
            'clase_riesgo_ul_num',
            'clase_riesgo_ul_desc',
            'sector_economico',
            'fuente_enriquecimiento',
            'enriquecido_at',
            'enriquecimiento_hash',
            'enrichment_status',
            'created_at',
            'updated_at',
        ];

        foreach ($expected as $column) {
            $this->assertTrue(
                Schema::hasColumn('entidad_enriquecimiento', $column),
                "Expected column '{$column}' in entidad_enriquecimiento"
            );
        }
    }

    #[Test]
    public function entidad_enriquecimiento_has_unique_constraint_on_entidad_id(): void
    {
        // The 1:1 annex contract is enforced by a UNIQUE constraint on entidad_id.
        // Schema::getIndexes on MariaDB returns both indexes and constraints in
        // the same collection; a UNIQUE index entry satisfies the constraint.
        $indexes = collect(Schema::getIndexes('entidad_enriquecimiento'));

        $hasUniqueOnEntidadId = $indexes->contains(function (array $i) {
            return ($i['unique'] ?? false) === true
                && in_array('entidad_id', $i['columns'] ?? [], true);
        });

        $this->assertTrue(
            $hasUniqueOnEntidadId,
            'Expected UNIQUE index on entidad_id for the 1:1 annex contract.'
        );
    }

    #[Test]
    public function entidad_enriquecimiento_has_fk_index_on_entidad_id(): void
    {
        // FK columns must have an index so ON DELETE CASCADE works in MariaDB.
        $indexes = collect(Schema::getIndexes('entidad_enriquecimiento'));

        $hasFkIndex = $indexes->contains(function (array $i) {
            // Include FK index name.
            return in_array('entidad_id', $i['columns'] ?? [], true);
        });

        $this->assertTrue(
            $hasFkIndex,
            'Expected an index on entidad_id (FK index for the annex table).'
        );
    }

    #[Test]
    public function cantidad_empleados_column_is_present_on_entidad(): void
    {
        // The idempotent migration guard must leave cantidad_empleados
        // on the entidad table (whether it was already there or freshly added).
        $this->assertTrue(
            Schema::hasColumn('entidad', 'cantidad_empleados'),
            'Expected cantidad_empleados column on entidad table.'
        );
    }
}