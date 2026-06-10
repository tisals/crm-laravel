<?php

namespace Modules\CRM\Models;

use Database\Factories\PipelineEtapaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PipelineEtapa extends Model
{
    use HasFactory;

    protected $table = 'pipeline_etapas';

    protected $fillable = [
        'pipeline_id',
        'nombre',
        'orden',
        'habilitado',
    ];

    protected static function newFactory(): PipelineEtapaFactory
    {
        return PipelineEtapaFactory::new();
    }

    public function pipeline()
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }
}
