<?php

namespace Tests;

use Illuminate\Support\Facades\DB;

/**
 * Trait for tests that need an `entidad` with an active `entidad_relacion`
 * pivot row.
 *
 * Commit 5.5 of `tenant-data-model-correction` dropped `entidad.estado`
 * and `entidad.cliente_desde`. The business state now lives on the
 * `entidad_relacion` pivot, and `entidad.estado` is DERIVED from the
 * pivot via `Entidad::getEstadoAttribute()`. The accessor returns
 * 'activo' iff the entity has at least one pivot row with
 * `effective_to IS NULL`.
 *
 * Tests that previously created entities with `'estado' => 'Activo'`
 * and then asserted `$entidad->estado === 'Activo'` (or compared
 * JSON responses that include `estado`) now need to ensure the
 * pivot row exists. This trait provides a single helper so the
 * tests don't each open a raw DB connection just to insert a pivot.
 *
 * Usage:
 *
 *   class FooTest extends TestCase {
 *       use CreatesEntidadForTesting;
 *
 *       #[Test]
 *       public function it_works(): void {
 *           $entidad = $this->makeEntidad('cliente', now()->subDays(5));
 *           $this->assertSame('activo', $entidad->estado);
 *       }
 *   }
 */
trait CreatesEntidadForTesting
{
    /**
     * Create an entidad with an active pivot row of the given
     * `tipo_relacion`. Defaults to 'cliente'.
     *
     * @param  string  $tipo_relacion  'cliente' | 'prospecto' | 'propia' | 'proveedor'
     * @param  \Carbon\Carbon|string|null  $effectiveFrom
     * @param  \Carbon\Carbon|string|null  $effectiveTo
     */
    protected function makeEntidad(
        string $tipoRelacion = 'cliente',
        $effectiveFrom = null,
        $effectiveTo = null,
    ): \App\Models\Entidad {
        $now = now();
        $entidadId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Test Entidad '.uniqid(),
            'identificacion' => 'TEST-'.uniqid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('entidad_relacion')->insert([
            'entidad_id' => $entidadId,
            'tipo_relacion' => $tipoRelacion,
            'effective_from' => $effectiveFrom ?? $now->toDateString(),
            'effective_to' => $effectiveTo === null ? null : (is_string($effectiveTo) ? $effectiveTo : $effectiveTo->toDateString()),
            'frecuencia' => 'unica',
            'recurrencia_cada_meses' => null,
            'vigencia_meses' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return \App\Models\Entidad::find($entidadId);
    }

    /**
     * Same as `makeEntidad` but the pivot has `effective_to` already
     * in the past — the entity ends up in the 'inactivo' state.
     */
    protected function makeInactiveEntidad(string $tipoRelacion = 'cliente'): \App\Models\Entidad
    {
        return $this->makeEntidad(
            $tipoRelacion,
            now()->subYears(2),
            now()->subYear(),
        );
    }
}
