<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * CQRS-Lite read model. One row per user, holding the pre-computed
 * identity bundle that `GET /me/identity` serves in a single SELECT.
 *
 * Refreshed by `crm:refresh-user-identity-snapshot` (scheduled 03:30 BOG)
 * and on-demand after any permission mutation. `is_stale` flips to true
 * when a mutation has invalidated the bundle; the next read recomputes
 * and resets the flag.
 *
 * NO SoftDeletes: this is a cache-like table. If the user is removed,
 * the cascade in the `usuarios` migration will clean this row up
 * via FK — no need for soft semantics here.
 *
 * @property int $user_id
 * @property array $payload
 * @property string $scope_label
 * @property \Illuminate\Support\Carbon|null $computed_at
 * @property bool $is_stale
 */
class UserIdentitySnapshot extends Model
{
    use HasFactory;

    protected $table = 'user_identity_snapshot';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'payload',
        'scope_label',
        'computed_at',
        'is_stale',
    ];

    protected $casts = [
        'payload' => 'array',
        'computed_at' => 'datetime',
        'is_stale' => 'boolean',
    ];

    /**
     * Accessor for the decoded payload. Equivalent to `$model->payload`
     * since the cast handles it, but this is the explicit contract used
     * by callers expecting an array regardless of cast setup.
     */
    public function getPayloadDecodedAttribute(): array
    {
        if (is_array($this->payload)) {
            return $this->payload;
        }

        return json_decode((string) $this->payload, true) ?? [];
    }
}
