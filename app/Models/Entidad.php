<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Users (auth credentials) linked to this entidad via the
     * `entidad_persona` pivot and `usuarios.persona_id` FK.
     *
     * Per `tenant-data-model-correction` (commit fe99f70): the pivot
     * `entidad_usuario` was renamed to `entidad_persona` and its FK
     * retargeted from `usuarios.id` to `personas.id`. Since `usuarios`
     * has a NOT NULL `persona_id` FK back to `personas`, we can resolve
     * user ↔ entidad in two hops:
     *
     *   entidad (this) ──► entidad_persona ──► usuarios
     *                       (entidad_id)        (persona_id)
     *
     * The composite PK on `entidad_persona` (persona_id, entidad_id)
     * keeps the relation deduplicated per (persona, entidad) pair, and
     * `usuarios.persona_id` may not be unique (one persona can have
     * multiple auth rows), so a single user can still appear once per
     * their matching persona.
     */
    public function usuarios()
    {
        return $this->hasManyThrough(
            Usuario::class,
            EntidadPersona::class,
            'entidad_id',   // FK on entidad_persona -> entidad.id
            'persona_id',   // FK on usuarios -> entidad_persona.persona_id
            'id',           // local key on entidad
            'persona_id'    // local key on entidad_persona
        );
    }

    /**
     * Contactos linked to this entidad via the persona pivot:
     *
     *   entidad (this) ──► personas ──► contacto
     *                       (entidad_id)   (persona_id)
     *
     * Per `tenant-data-model-correction` (commit fe99f70): `contacto.entidad_id`
     * was dropped because the contacto ↔ entidad relationship now goes through
     * the persona identity. Contactos without a `persona_id` are not
     * associated with any entidad in the new model (they were orphans under
     * the old direct FK too — see migration 000002's drop rationale).
     */
    public function contactos()
    {
        return $this->hasManyThrough(
            Contacto::class,
            Persona::class,
            'entidad_id',   // FK on personas -> entidad.id
            'persona_id',   // FK on contacto -> persona.id
            'id',           // local key on entidad
            'id'            // local key on persona
        );
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
