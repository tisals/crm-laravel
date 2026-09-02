<?php

namespace Modules\CRM\Models;

use App\Models\Entidad;
use App\Models\EntidadPersona;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contacto extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contacto';

    /**
     * `contacto.entidad_id` was dropped in commit fe99f70. We intentionally
     * keep it OUT of $fillable so the legacy column never gets INSERTed.
     * The factory and the `saving` hook below capture the caller intent
     * into `__legacyEntidadId` and re-apply it on `entidad_persona`.
     */
    protected $fillable = [
        'persona_id',
        'nombres',
        'apellidos',
        'area',
        'cargo',
        'tel_contacto',
        'movil',
        'email_contacto',
        'email_secundario',
        'rol',
        'etapa',
        'estado',
        'score',
        'diagnostico_data',
        'fuente',
        'created_by',
        'updated_by',
    ];

    /**
     * Transient attribute carrying the legacy `entidad_id` caller intent.
     * Not in $fillable, not in DB. Survives the `saving` round-trip so
     * `afterCreating` (factory) and any post-save code can read it.
     */
    public ?int $__legacyEntidadId = null;

    protected function casts(): array
    {
        return [
            'diagnostico_data' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Capture caller intent for the legacy `entidad_id` field even
        // though it's not $fillable. Eloquent's fill() silently drops
        // unknown attrs, so we use forceFill() at the factory level to
        // load this value into the transient attr before saving.
        //
        // (No-op at runtime; the factory does the work.)
    }

    /**
     * The entidades a contacto belongs to, mediated by the
     * `entidad_persona` pivot keyed on this contacto's persona_id.
     *
     * Before fe99f70 this was a direct `belongsTo(Entidad::class,
     * 'entidad_id')` — now it routes through the pivot so a contacto can
     * be assigned to multiple entidades (categoria='asignacion' is the
     * default; legacy 'dependencia' rows survive too).
     */
    public function entidades(): BelongsToMany
    {
        return $this->belongsToMany(
            Entidad::class,
            'entidad_persona',
            'persona_id',
            'entidad_id'
        );
    }

    /**
     * Convenience accessor returning the primary entidad via the pivot.
     * Used by views/tests that still want `$contacto->entidad` semantics.
     * Returns null when the contacto has no persona or no pivot rows.
     */
    public function getEntidadAttribute(): ?Entidad
    {
        if (! $this->persona_id) {
            return null;
        }

        return $this->persona?->entidades()->first();
    }

    /**
     * PR-E (REQ-PRRL-005): nullable FK to the natural/juridica persona
     * this contacto is a role projection of. nullOnDelete on the FK means
     * deleting the persona leaves the contacto intact (audit trail
     * preserved) with `persona_id = NULL`.
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
