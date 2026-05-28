<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Entidad extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'entidad';
    protected $fillable = [
        'tipo_persona',
        'tipo_id',
        'identificacion',
        'nombre',
        'nombre_comercial',
        'linea_negocio',
        'direccion',
        'ciudad_cod',
        'dominio',
        'email',
        'telefono',
        'rut',
        'logo',
        'estado',
        'allowed_domains',
        'webhook_url',
        'webhook_secret',
        'webhook_enabled',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'webhook_enabled' => 'boolean',
    ];

    /**
     * Validar si un dominio está permitido para esta entidad.
     */
    public function isDomainAllowed(string $domain): bool
    {
        if (empty($this->allowed_domains)) {
            return false; // Si no hay dominios configurados, denegar todo
        }

        $allowedDomains = array_map(
            fn($d) => trim(strtolower($d)),
            explode(',', $this->allowed_domains)
        );

        $domain = strtolower(trim($domain));

        // Verificar dominio exacto o subdominios
        foreach ($allowedDomains as $allowed) {
            if ($domain === $allowed) {
                return true;
            }
            // Permitir subdominios (ej: api.sailus.com matches sailus.com)
            if (str_ends_with($domain, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si webhooks están habilitados para esta entidad.
     */
    public function hasWebhooksEnabled(): bool
    {
        return $this->webhook_enabled && !empty($this->webhook_url);
    }

    /**
     * Obtener configuración de webhook.
     */
    public function getWebhookConfig(): ?array
    {
        if (!$this->hasWebhooksEnabled()) {
            return null;
        }

        return [
            'url' => $this->webhook_url,
            'secret' => $this->webhook_secret,
        ];
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'entidad_usuario', 'entidad_id', 'usuario_id')
            ->withTimestamps();
    }

    public function contactos()
    {
        return $this->hasMany(Contacto::class, 'entidad_id');
    }
}
