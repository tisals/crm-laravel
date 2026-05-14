<?php

namespace Tests\Unit\Infrastructure\Persistence;

use App\Infrastructure\Persistence\BaseRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BaseRepositoryTest extends TestCase
{
    #[Test]
    public function it_has_paginate_method(): void
    {
        $repo = new class extends BaseRepository {
            protected function getModelClass(): string
            {
                return \App\Models\Rol::class;
            }

            protected function mapModelToEntity($model): array
            {
                return $model->toArray();
            }
        };

        $this->assertTrue(method_exists($repo, 'paginate'));
    }

    #[Test]
    public function it_has_find_by_id_method(): void
    {
        $repo = new class extends BaseRepository {
            protected function getModelClass(): string
            {
                return \App\Models\Rol::class;
            }

            protected function mapModelToEntity($model): array
            {
                return $model->toArray();
            }
        };

        $this->assertTrue(method_exists($repo, 'findById'));
    }

    #[Test]
    public function it_has_create_method(): void
    {
        $repo = new class extends BaseRepository {
            protected function getModelClass(): string
            {
                return \App\Models\Rol::class;
            }

            protected function mapModelToEntity($model): array
            {
                return $model->toArray();
            }
        };

        $this->assertTrue(method_exists($repo, 'create'));
    }

    #[Test]
    public function it_has_update_method(): void
    {
        $repo = new class extends BaseRepository {
            protected function getModelClass(): string
            {
                return \App\Models\Rol::class;
            }

            protected function mapModelToEntity($model): array
            {
                return $model->toArray();
            }
        };

        $this->assertTrue(method_exists($repo, 'update'));
    }

    #[Test]
    public function it_has_delete_method(): void
    {
        $repo = new class extends BaseRepository {
            protected function getModelClass(): string
            {
                return \App\Models\Rol::class;
            }

            protected function mapModelToEntity($model): array
            {
                return $model->toArray();
            }
        };

        $this->assertTrue(method_exists($repo, 'delete'));
    }
}
