<?php

namespace Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineEtapa extends Model
{
    protected $table = 'pipeline_etapas';
    protected $fillable = [
        'pipeline_id',
        'nombre',
        'orden',
        'habilitado',
    ];

    public function pipeline()
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }
}
