<?php

namespace Modules\CRM\Models;

use App\Models\Entidad;
use App\Models\Persona;
use Database\Factories\SeguimientoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Shared\Models\Usuario;

/**
 * Canonical Eloquent model for `seguimiento`.
 *
 * Use this class directly in new code. The legacy alias `App\Models\Seguimiento`
 * extends this one for backwards compatibility during the modular-migration.
 */
class Seguimiento extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'seguimiento';

    protected $fillable = [
        'oportunidad_id',
        // PR-H (Phase 5b - REQ-SEG-004): the FK swap replaces
        // `seguimiento.contacto_id` (FK -> contacto.id, dropped by PR-G)
        // with `seguimiento.persona_id` (FK -> personas.id, added by PR-G).
        // The column was dropped in 2026_08_28_000099 migration; the
        // code layer now carries `persona_id` everywhere.
        'persona_id',
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
        // PR-B (Phase 1b): bot-fact columns - REQ-ISCF-002. Stamped by
        // Mercury / Hermes bots.
        'bot_fact_type',
        'bot_confidence',
        'bot_source_profile',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_fin' => 'datetime',
        'hora' => 'string',
        'bot_confidence' => 'decimal:3',
    ];

    /**
     * Campos planos derivados de relaciones para el frontend.
     * El API serializa estos como flat fields (entidad_nombre, etc.)
     * en vez de objetos anidados (entidad: {nombre}).
     */
    protected $appends = [
        'entidad_nombre',
        // PR-H: keep `contacto_nombre` as the JSON key for backward
        // compatibility with the frontend dashboard, but the accessor
        // now resolves from `persona` (the post-PR-G canonical identity
        // axis) instead of the (gone) `contacto` relation.
        'contacto_nombre',
        'oportunidad_codigo',
        'autor_nombre',
    ];

    protected static function newFactory(): SeguimientoFactory
    {
        return SeguimientoFactory::new();
    }

    // ━━━ Accesors (appends) ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function getEntidadNombreAttribute(): ?string
    {
        return $this->entidad?->nombre;
    }

    /**
     * PR-H: derive the human name from the persona (post-PR-G canonical
     * identity axis). The accessor name is kept as `contacto_nombre` for
     * backward compatibility with the frontend, but it now resolves via
     * `persona.nombres + persona.apellidos` instead of the (gone)
     * `contacto.nombres + contacto.apellidos`.
     */
    public function getContactoNombreAttribute(): ?string
    {
        $p = $this->persona;
        if (! $p) {
            return null;
        }

        return trim("{$p->nombres} {$p->apellidos}");
    }

    public function getOportunidadCodigoAttribute(): ?string
    {
        return $this->oportunidad?->codigo;
    }

    public function getAutorNombreAttribute(): ?string
    {
        $a = $this->autor;
        if (! $a) {
            return null;
        }

        return $a->nombre;
    }

    // ━━━ Relationships ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(Oportunidad::class, 'oportunidad_id');
    }

    /**
     * PR-H: replace `contacto()` (BelongsTo -> Contacto on contacto_id,
     * dropped) with `persona()` (BelongsTo -> Persona on persona_id,
     * nullOnDelete per the PR-G migration).
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autor_id');
    }
}
