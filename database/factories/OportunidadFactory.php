<?php

namespace Database\Factories;

use App\Models\Entidad;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CRM\Models\Oportunidad;

class OportunidadFactory extends Factory
{
    protected $model = Oportunidad::class;

    public function definition(): array
    {
        return [
            'codigo' => fake()->unique()->regexify('COT-[0-9]{6}'),
            'entidad_id' => Entidad::factory(),
            'contacto_id' => null,
            'fecha' => now()->format('Y-m-d'),
            'fuente_canal' => fake()->optional(0.6)->randomElement(['Web', 'Referido', 'Llamada', 'Email', 'Otro']),
            'estado' => 'Borrador',
            // Versioning — defaults: V0 (no version suffix), latest version, active
            'version' => 0,
            'is_latest' => true,
            'parent_id' => null,
            'observaciones' => fake()->optional(0.5)->sentence(),
            'validez_oferta' => fake()->optional(0.7)->numberBetween(15, 60),
            'tiempo_entrega' => fake()->optional(0.6)->randomElement(['15 días', '30 días', '45 días', '60 días']),
            'forma_pago' => fake()->optional(0.6)->randomElement(['Contado', 'Crédito 30 días', 'Crédito 60 días', '50% anticipo']),
            'garantia' => fake()->optional(0.5)->randomElement(['6 meses', '12 meses', '24 meses']),
        ];
    }

    /**
     * Indicate this is a superseded (inactive) version of an opportunity.
     * Useful for testing versioned opportunities.
     */
    public function superseded(int $version = 1, int $parentId = 1): static
    {
        return $this->state(fn (array $attrs) => [
            'version' => $version,
            'is_latest' => false,
            'parent_id' => $parentId,
            'estado' => 'Inactiva',
        ]);
    }
}
