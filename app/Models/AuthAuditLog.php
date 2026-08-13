<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthAuditLog extends Model
{
    protected $table = 'auth_audit_log';

    public $timestamps = false;

    protected $fillable = [
        'event',
        'email',
        'usuario_id',
        'ip',
        'user_agent',
        'request_id',
        'metadata_json',
        'created_at',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'created_at' => 'datetime',
    ];
}
