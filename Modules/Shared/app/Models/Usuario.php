<?php

namespace Modules\Shared\Models;

use App\Models\Entidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $table = 'usuarios';

    protected $fillable = [
        'nombre',
        'email',
        'password_hash',
        'rol_id',
        'estado',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /** Scope: solo usuarios con rol de super_admin (rol_id=1) */
    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('rol_id', 1)->where('estado', 'Activo');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    /**
     * Entidades this user has access to (via the new `entidad_persona`
     * pivot joined through `usuarios.persona_id` FK added in migration 000003).
     *
     * Per commit fe99f70: the pivot is now keyed on `persona_id`, NOT
     * `usuario_id`. The relation is therefore hasManyThrough the
     * intermediate `entidad_persona` model.
     */
    public function entidades(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            Entidad::class,
            \App\Models\EntidadPersona::class,
            'persona_id',  // FK on entidad_persona -> personas.id (== usuarios.persona_id)
            'entidad_id',  // FK on entidad -> entidad_persona.entidad_id
            'persona_id',  // local key on usuarios
            'entidad_id'   // local key on entidad_persona
        );
    }
}
