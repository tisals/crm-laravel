<?php

namespace Database\Factories;

use App\Models\Entidad;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Commit 5.5 of `tenant-data-model-correction` dropped `entidad.estado`
 * and `entidad.cliente_desde`. The factory now uses the shared
 * `entidad_relacion` pivot instead.
 *
 * The factory:
 *   1. Inserts the `entidad` row WITHOUT `estado` / `direccion` /
 *      `ciudad_cod` / `email` / `telefono` / `dominio` /
 *      `red_social_url` (all dropped by Commit 4 and 5.5).
 *   2. After the row exists, inserts an `entidad_relacion` pivot row
 *      so `getEstadoAttribute()` returns 'activo' (the derived value
 *      callers expect from the legacy `'Activo'` default).
 *
 * This matches the production migration `000003_backfill_*` flow
 * (one pivot row per entidad, derived from `estado`).
 */
class EntidadFactory extends Factory
{
    protected $model = Entidad::class;

    public function definition(): array
    {
        return [
            'tipo_persona' => fake()->randomElement(['Natural', 'Juridica']),
            'tipo_id' => fake()->randomElement(['NIT', 'CC', 'CE']),
            'identificacion' => fake()->unique()->numerify('##########'),
            'nombre' => fake()->company(),
            'nombre_comercial' => fake()->optional(0.6)->company(),
        ];
    }

    /**
     * After the entidad row is created, drop a `cliente` pivot row so
     * `getEstadoAttribute()` returns 'activo'. Tests that need an
     * 'inactivo' entidad should call `inactivo()` instead.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Entidad $entidad) {
            DB::table('entidad_relacion')->insertOrIgnore([
                'entidad_id' => $entidad->id,
                'tipo_relacion' => 'cliente',
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'frecuencia' => 'unica',
                'recurrencia_cada_meses' => null,
                'vigencia_meses' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * State: leave the pivot open but mark the relacion `inactivo` via
     * setting `effective_to` in the past. `getEstadoAttribute()`
     * returns 'inactivo'.
     */
    public function inactivo(): static
    {
        return $this->afterCreating(function (Entidad $entidad) {
            DB::table('entidad_relacion')->insert([
                'entidad_id' => $entidad->id,
                'tipo_relacion' => 'prospecto',
                'effective_from' => now()->subYears(2)->toDateString(),
                'effective_to' => now()->subYear()->toDateString(),
                'frecuencia' => 'unica',
                'recurrencia_cada_meses' => null,
                'vigencia_meses' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
