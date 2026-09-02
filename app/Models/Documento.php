<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Commit 3 — `documentos` table.
 *
 * `path_storage` is a Mercurio OneDrive URL (D13). The Mercurio-side
 * integration that actually uploads files is owned by a separate
 * service; this model just persists the URL the caller provides.
 */
class Documento extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'documentos';

    protected $fillable = [
        'persona_id',
        'entidad_id',
        'uploaded_by',
        'tipo_documento',
        'nombre_archivo',
        'path_storage',
        'mime_type',
        'size_bytes',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'uploaded_by');
    }
}
