<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-(user, app) scoped permission.
 *
 * Adds app-level overrides on top of the existing `permisos(rol_id, vista)`
 * global model. A row grants `vista` to `usuario` for `app`. The
 * `MultiAppRbacService` resolves effective permissions as the UNION of
 * (rol permission) and (any matching row here).
 *
 * `vista = '*'` is intentionally NOT stored here — super-admin wildcard
 * is checked in `MultiAppRbacService` directly to keep this table
 * scoped to actual grants.
 *
 * @property int $id
 * @property int $usuario_id
 * @property int $app_id
 * @property string $vista
 * @property int|null $created_by
 */
class UsuarioAppPermiso extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'usuario_app_permisos';

    protected $fillable = [
        'usuario_id',
        'app_id',
        'vista',
        'created_by',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }
}
