<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Usuario as UsuarioEntity;
use App\Domain\Repositories\UsuarioRepositoryInterface;
use App\Models\Usuario as EloquentUsuario;
use Illuminate\Database\Eloquent\Model;

class EloquentUsuarioRepository extends BaseRepository implements UsuarioRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentUsuario::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return UsuarioEntity::fromArray($model->toArray());
    }

    public function findByEmail(string $email): mixed
    {
        $model = EloquentUsuario::where('email', $email)->first();

        return $model ? $this->mapModelToEntity($model) : null;
    }
}
