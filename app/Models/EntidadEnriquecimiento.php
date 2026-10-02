<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PR1 of `complementar-entidad` — entity-empresa-enrichment D1.
 *
 * 1:1 annex table holding enrichment data populated by the Python
 * FastMCP server (PR2) and consumed by the Laravel enrichment
 * pipeline (PR3–PR5). The annex enforces the contract:
 * "one enrichment row per entidad, ever".
 *
 * Lifecycle (`enrichment_status`):
 *   - pending         — just created, no enrichment run yet
 *   - enriched        — MCP + Decreto resolved successfully
 *   - needs_selection — homonimia (>1 candidates), awaiting user pick
 *   - failed          — last attempt exhausted retries
 *   - skipped         — Habeas Data exclude mode returned null
 *
 * @property int    $id
 * @property int    $entidad_id
 * @property string|null $nit
 * @property string|null $ciiu_codigo
 * @property int|null    $clase_riesgo_ul_num
 * @property string|null $clase_riesgo_ul_desc
 * @property string|null $sector_economico
 * @property string|null $fuente_enriquecimiento
 * @property \Illuminate\Support\Carbon|null $enriquecido_at
 * @property string|null $enriquecimiento_hash
 * @property string $enrichment_status
 */
class EntidadEnriquecimiento extends Model
{
    protected $table = 'entidad_enriquecimiento';

    protected $fillable = [
        'entidad_id',
        'nit',
        'ciiu_codigo',
        'clase_riesgo_ul_num',
        'clase_riesgo_ul_desc',
        'sector_economico',
        'fuente_enriquecimiento',
        'enriquecido_at',
        'enriquecimiento_hash',
        'enrichment_status',
    ];

    protected $casts = [
        'enriquecido_at' => 'datetime',
        'clase_riesgo_ul_num' => 'integer',
    ];

    /**
     * Inverse relation to the owning `entidad` row.
     */
    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }
}