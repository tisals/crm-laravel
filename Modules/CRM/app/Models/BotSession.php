<?php

namespace Modules\CRM\Models;

use App\Models\Entidad;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PR-B (Phase 1b): canonical Eloquent model for `bot_sessions`.
 *
 * A BotSession represents one Mercury setter / SST support / marketing
 * bot interaction (REQ-ISCF-001). The `session_key` is the bot-generated
 * UUID used to look up the row from a webhook callback; the FK columns
 * stay nullable because a session may start before the persona or
 * oportunidad is known.
 *
 * `metadata` is a free-form JSON payload ÔÇö per-bot extensions live there
 * without forcing future schema churn.
 */
class BotSession extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bot_sessions';

    protected $fillable = [
        'session_key',
        'entidad_id',
        'brand_slug',
        'profile_slug',
        'persona_id',
        'oportunidad_id',
        'estado',
        'started_at',
        'ended_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(Oportunidad::class, 'oportunidad_id');
    }
}
