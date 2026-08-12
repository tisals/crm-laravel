<?php

namespace Modules\Shared\Models;

use App\Models\App;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'persona_id',
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

    public function entidades(): BelongsToMany
    {
        return $this->belongsToMany(Entidad::class, 'entidad_usuario', 'usuario_id', 'entidad_id')
            ->withTimestamps();
    }

    public function apps(): BelongsToMany
    {
        return $this->belongsToMany(
            App::class,
            'usuario_app',
            'usuario_id',
            'app_id'
        )->withPivot('rol_id')->withTimestamps();
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    /**
     * Convenience: returns true if the user's rol has es_super_admin=true.
     */
    public function isSuperAdmin(): bool
    {
        return $this->rol?->isSuperAdmin() ?? false;
    }
}
