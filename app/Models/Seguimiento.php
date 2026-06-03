<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Seguimiento extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'seguimiento';
    protected $fillable = [
        'oportunidad_id',
        'contacto_id',
        'entidad_id',
        'tipo',
        'fecha',
        'hora',
        'fecha_fin',
        'notas',
        'autor_id',
        'estado',
        'created_by',
        'updated_by',
    ];

    protected $appends = [
        'autor_nombre',
        'contacto_nombre',
        'entidad_nombre',
        'oportunidad_codigo',
    ];

    public function getAutorNombreAttribute()
    {
        return $this->autor ? $this->autor->nombre : null;
    }

    public function getContactoNombreAttribute()
    {
        return $this->contacto ? $this->contacto->nombre : null;
    }

    public function getEntidadNombreAttribute()
    {
        return $this->entidad ? $this->entidad->nombre : null;
    }

    public function getOportunidadCodigoAttribute()
    {
        return $this->oportunidad ? $this->oportunidad->codigo : null;
    }

    public function oportunidad()
    {
        return $this->belongsTo(Oportunidad::class, 'oportunidad_id');
    }

    public function contacto()
    {
        return $this->belongsTo(Contacto::class, 'contacto_id');
    }

    public function entidad()
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function autor()
    {
        return $this->belongsTo(Usuario::class, 'autor_id');
    }
}
