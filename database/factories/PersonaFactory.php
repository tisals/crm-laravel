<?php

namespace Database\Factories;

use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for App\Models\Persona.
 *
 * Commit 4 dropped the contact-data columns (`email_principal`,
 * `telefono_principal`, etc.) that this factory historically set on
 * the persona row. The persona now only carries identity fields;
 * emails / telefonos / direcciones live in the shared
 * `emails` / `telefonos` / `direcciones` tables (Commit 3).
 *
 * Callers that need the persona's email should either:
 *   - Read `$persona->emails()->where('es_principal', true)->value('email')`
 *   - Or insert an `emails` row directly after `create()` (the
 *     `ContactoFactory::afterCreating` hook does this for `Contacto`).
 */
class PersonaFactory extends Factory
{
    protected $model = Persona::class;

    public function definition(): array
    {
        return [
            'identificacion_tipo' => fake()->randomElement(['CC', 'CE', 'NIT', 'PAS']),
            'identificacion_numero' => fake()->unique()->numerify('##########'),
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            // `entidad_id` is the legacy 1:1 FK; callers that need the
            // canonical multi-tenant binding use `entidad_persona`
            // (the `entidades()` relation on the model).
            'entidad_id' => null,
        ];
    }
}
