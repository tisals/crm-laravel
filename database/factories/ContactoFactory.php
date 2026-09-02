<?php

namespace Database\Factories;

use App\Models\Contacto;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactoFactory extends Factory
{
    protected $model = Contacto::class;

    public function definition(): array
    {
        return [
            // Per commit fe99f70: `contacto.entidad_id` was dropped. The
            // factory seeds the legacy field as `null` (now a no-op
            // since the column doesn't exist); the entidad binding lives
            // on `entidad_persona` keyed on the contacto's persona_id.
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'email_contacto' => fake()->unique()->safeEmail(),
            'estado' => 'Activo',
        ];
    }
}
