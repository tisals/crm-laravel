<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\PipelineEtapa;

class PipelineEtapaFactory extends Factory
{
    protected $model = PipelineEtapa::class;

    public function definition(): array
    {
        return [
            'pipeline_id' => Pipeline::factory(),
            'nombre' => fake()->word(),
            'orden' => 1,
            'habilitado' => true,
        ];
    }

    public function forPipeline(Pipeline $pipeline): static
    {
        return $this->state(fn (array $attributes) => [
            'pipeline_id' => $pipeline->id,
        ]);
    }

    public function atOrden(int $orden): static
    {
        return $this->state(fn (array $attributes) => [
            'orden' => $orden,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'habilitado' => false,
        ]);
    }
}
