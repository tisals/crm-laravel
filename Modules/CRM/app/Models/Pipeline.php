<?php

namespace Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class Pipeline extends Model
{
    protected $table = 'pipelines';
    protected $fillable = [
        'nombre',
        'codigo',
        'habilitado',
    ];

    public function etapas()
    {
        return $this->hasMany(PipelineEtapa::class, 'pipeline_id')->orderBy('orden');
    }
}
