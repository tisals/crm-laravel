<?php

namespace Modules\Shared\Models;

use App\Models\Entidad;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
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

    /**
     * Auto-create a backing persona row when the caller doesn't supply
     * `persona_id` (per migration 000003 the column is NOT NULL).
     *
     * Per `tenant-data-model-correction` (commit fe99f70): "un usuario
     * siempre sera antes un colaborador de una entidad, o propia o de
     * cliente o de Proveedor." — every auth-credential row represents a
     * natural person, so we backfill the persona from `usuarios.email`.
     *
     * Skipped when:
     *   - `persona_id` is already set (caller-supplied)
     *   - the model is being updated (not created)
     *   - `email` is missing (test fixture with no email)
     *   - we're inside a DB::transaction that will backfill externally
     *     (the bulk-import seeders prefer explicit IDs)
     */
    protected static function booted(): void
    {
        static::creating(function (Usuario $usuario) {
            if ($usuario->persona_id !== null) {
                return;
            }

            if (empty($usuario->email)) {
                return;
            }

            // Match an existing persona first (idempotent, mirrors the
            // backfill in migration 000003).
            $personaId = DB::table('personas')
                ->where('email_principal', $usuario->email)
                ->value('id');

            if (! $personaId) {
                $personaId = DB::table('personas')->insertGetId([
                    'nombres' => $usuario->nombre ?: $usuario->email,
                    'email_principal' => $usuario->email,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $usuario->persona_id = $personaId;
        });
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

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
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
