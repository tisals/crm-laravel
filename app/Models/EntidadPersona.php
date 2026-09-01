<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `entidad_persona` pivot table.
 *
 * Per `tenant-data-model-correction` (commit fe99f70): the pivot was renamed
 * from `entidad_usuario` to `entidad_persona` because the FK now points at
 * `personas.id` (the natural-person identity axis), not at `usuarios.id`
 * (the auth-credential row). The `usuarios` table now has a NOT NULL
 * `persona_id` FK, so the link user ↔ entidad still resolves transitively
 * (user → usuarios.persona_id → entidad_persona.persona_id).
 *
 * Schema (after migration 000004):
 *   - composite PK: (persona_id, entidad_id)
 *   - `categoria` ENUM('dependencia','asignacion','delegacion') default 'dependencia'
 *
 * REQ-PRRL-005 / spec change `tenant-data-model-fixes` (commit 2.5):
 * mechanical cleanup after the schema rename. The category filter is
 * intentionally NOT exposed in this model yet (per spec "Out of scope").
 */
class EntidadPersona extends Model
{
    use HasFactory;

    protected $table = 'entidad_persona';

    /**
     * Composite PK — no auto-incrementing surrogate key.
     */
    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'persona_id',
        'entidad_id',
        'categoria',
    ];

    protected $casts = [
        'persona_id' => 'int',
        'entidad_id' => 'int',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }
}
