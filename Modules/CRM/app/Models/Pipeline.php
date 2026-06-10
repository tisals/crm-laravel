<?php

namespace Modules\CRM\Models;

use Database\Factories\PipelineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pipeline extends Model
{
    use HasFactory;

    protected $table = 'pipelines';

    protected $fillable = [
        'nombre',
        'codigo',
        'habilitado',
    ];

    protected static function newFactory(): PipelineFactory
    {
        return PipelineFactory::new();
    }

    public function etapas()
    {
        return $this->hasMany(PipelineEtapa::class, 'pipeline_id')->orderBy('orden');
    }
}
