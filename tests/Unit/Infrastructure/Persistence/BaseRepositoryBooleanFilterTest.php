<?php

namespace Tests\Unit\Infrastructure\Persistence;

use App\Infrastructure\Persistence\BaseRepository;
use App\Models\App as EloquentApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BaseRepositoryBooleanFilterTest extends TestCase
{
    use RefreshDatabase;
    #[Test]
    public function it_casts_string_true_to_boolean_for_filter(): void
    {
        // Two apps: one active, one inactive
        EloquentApp::create([
            'slug' => 'test-app-active',
            'nombre' => 'Test App Active',
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => true,
            'descripcion' => null,
        ]);
        EloquentApp::create([
            'slug' => 'test-app-inactive',
            'nombre' => 'Test App Inactive',
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => false,
            'descripcion' => null,
        ]);

        // Filter with STRING "true" (what the frontend sends via axios)
        $repo = new class extends BaseRepository
        {
            protected function getModelClass(): string
            {
                return EloquentApp::class;
            }

            protected function mapModelToEntity(Model $model): array
            {
                return $model->toArray();
            }
        };

        $result = $repo->paginate(50, null, ['activo' => 'true']);

        // Only the active one should come back
        $slugs = collect($result->items())->pluck('slug')->toArray();
        $this->assertContains('test-app-active', $slugs);
        $this->assertNotContains('test-app-inactive', $slugs);
    }

    #[Test]
    public function it_casts_string_false_to_boolean_for_filter(): void
    {
        EloquentApp::create([
            'slug' => 'test-app-inactive-2',
            'nombre' => 'Test App Inactive 2',
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => false,
            'descripcion' => null,
        ]);

        $repo = new class extends BaseRepository
        {
            protected function getModelClass(): string
            {
                return EloquentApp::class;
            }

            protected function mapModelToEntity(Model $model): array
            {
                return $model->toArray();
            }
        };

        $result = $repo->paginate(50, null, ['activo' => 'false']);

        $slugs = collect($result->items())->pluck('slug')->toArray();
        $this->assertContains('test-app-inactive-2', $slugs);
    }

    #[Test]
    public function it_casts_numeric_one_to_boolean_true(): void
    {
        EloquentApp::create([
            'slug' => 'test-app-num-active',
            'nombre' => 'Test App Numeric Active',
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => true,
            'descripcion' => null,
        ]);
        EloquentApp::create([
            'slug' => 'test-app-num-inactive',
            'nombre' => 'Test App Numeric Inactive',
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => false,
            'descripcion' => null,
        ]);

        $repo = new class extends BaseRepository
        {
            protected function getModelClass(): string
            {
                return EloquentApp::class;
            }

            protected function mapModelToEntity(Model $model): array
            {
                return $model->toArray();
            }
        };

        $result = $repo->paginate(50, null, ['activo' => '1']);

        $slugs = collect($result->items())->pluck('slug')->toArray();
        $this->assertContains('test-app-num-active', $slugs);
        $this->assertNotContains('test-app-num-inactive', $slugs);
    }

    #[Test]
    public function it_passes_through_non_boolean_string_filters(): void
    {
        EloquentApp::create([
            'slug' => 'test-app-internal-only',
            'nombre' => 'Test Internal',
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => true,
            'descripcion' => null,
        ]);
        EloquentApp::create([
            'slug' => 'test-app-external-only',
            'nombre' => 'Test External',
            'tipo' => 'external',
            'auth_type' => 'sanctum',
            'activo' => true,
            'descripcion' => null,
        ]);

        $repo = new class extends BaseRepository
        {
            protected function getModelClass(): string
            {
                return EloquentApp::class;
            }

            protected function mapModelToEntity(Model $model): array
            {
                return $model->toArray();
            }
        };

        $result = $repo->paginate(50, null, ['tipo' => 'external']);

        $slugs = collect($result->items())->pluck('slug')->toArray();
        $this->assertContains('test-app-external-only', $slugs);
        $this->assertNotContains('test-app-internal-only', $slugs);
    }
}