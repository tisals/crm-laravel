<?php

namespace Database\Factories;

use App\Models\Entidad;
use App\Models\Seguimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeguimientoFactory extends Factory
{
    protected $model = Seguimiento::class;

    public function definition(): array
    {
        return [
            'oportunidad_id' => null,
            // PR-H (Phase 5b - REQ-SEG-004): emit `persona_id` instead
            // of the (gone) `contacto_id`. The factory never assigned
            // a value here (it was `null` by default), so this is a
            // name swap, not a behaviour change.
            'persona_id' => null,
            'entidad_id' => Entidad::factory(),
            'tipo' => fake()->randomElement(['Llamada', 'Correo', 'Reunion', 'Nota', 'Otro']),
            'fecha' => fake()->date(),
            'hora' => fake()->optional(0.7)->time('H:i:s'),
            'notas' => fake()->optional(0.7)->sentence(),
            'estado' => fake()->randomElement(['Pendiente', 'Completado', 'Cancelado']),
        ];
    }
}
