<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Etiqueta as EtiquetaEntity;
use App\Domain\Repositories\EtiquetaRepositoryInterface;
use App\Models\Etiqueta as EloquentEtiqueta;
use Illuminate\Database\Eloquent\Model;

class EloquentEtiquetaRepository extends BaseRepository implements EtiquetaRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentEtiqueta::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return EtiquetaEntity::fromArray($model->toArray());
    }
}
