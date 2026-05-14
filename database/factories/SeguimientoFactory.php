<?php

namespace Database\Factories;

use App\Models\Seguimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeguimientoFactory extends Factory
{
    protected $model = Seguimiento::class;

    public function definition(): array
    {
        return [
            'oportunidad_id' => null,
            'contacto_id' => null,
            'entidad_id' => \App\Models\Entidad::factory(),
            'tipo' => fake()->randomElement(['Llamada', 'Correo', 'Reunion', 'Nota', 'Otro']),
            'fecha' => fake()->date(),
            'hora' => fake()->optional(0.7)->time('H:i:s'),
            'notas' => fake()->optional(0.7)->sentence(),
            'estado' => fake()->randomElement(['Pendiente', 'Completado', 'Cancelado']),
        ];
    }
}
