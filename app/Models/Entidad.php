<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\CRM\Models\Oportunidad;

class Entidad extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'entidad';

    /**
     * Documented accepted values for `entidad.estado` (REQ-ENT-001, REQ-ENT-003).
     *
     * The column itself is VARCHAR(50) — there is no DB-level ENUM. These
     * values are the application-level contract. `'Cliente'` is the
     * canonical SaaS-tenant state (added by crm-laravel-iter4-persona
     * PR-C, seeded by `2026_08_28_000005_create_entidad_estado_audit_and_seed_cliente.php`).
     */
    public const ESTADOS_ACEPTADOS = [
        'Activo',     // operational
        'Inactivo',   // paused
        'Cancelado',  // cancelled (soft-delete semantic)
        'Cliente',    // SaaS tenant — entity with at least one contracted active app
        'prospecto',  // legacy lowercased; sales pipeline
        'cliente',    // legacy lowercased
    ];

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
        'cantidad_empleados',
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
            fn ($d) => trim(strtolower($d)),
            explode(',', $this->allowed_domains)
        );

        $domain = strtolower(trim($domain));

        // Verificar dominio exacto o subdominios
        foreach ($allowedDomains as $allowed) {
            if ($domain === $allowed) {
                return true;
            }
            // Permitir subdominios (ej: api.sailus.com matches sailus.com)
            if (str_ends_with($domain, '.'.$allowed)) {
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
        return $this->webhook_enabled && ! empty($this->webhook_url);
    }

    /**
     * Obtener configuración de webhook.
     */
    public function getWebhookConfig(): ?array
    {
        if (! $this->hasWebhooksEnabled()) {
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

    public function oportunidades()
    {
        return $this->hasMany(Oportunidad::class, 'entidad_id');
    }

    public function ciudad()
    {
        return $this->belongsTo(Ciudad::class, 'ciudad_cod', 'cod_municipio');
    }
}
