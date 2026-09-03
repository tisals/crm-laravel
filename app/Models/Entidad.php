<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Commit 4 dropped the contact-data columns that Commit 3
     * backfilled to `emails` / `telefonos` / `direcciones` /
     * `presencia_online`. Callers that need an entidad's primary
     * email now go through `$entidad->emails()->where('es_principal', true)->first()`
     * (or filter by `tipo='trabajo'` for the canonical work address).
     *
     * `ciudad_cod` had an FK to `ciudades.cod_municipio`; the FK was
     * dropped before the column. Callers that need the ciudad code
     * read it off `$entidad->direcciones()->first()->ciudad_codigo`.
     *
     * Commit 5.5 dropped `entidad.estado` and `entidad.cliente_desde`
     * — operational/business state now lives on the
     * `entidad_relacion` pivot. `entidad.estado` is DERIVED from the
     * pivot via `getEstadoAttribute()` (see below): "activo" iff
     * the entity has at least one pivot row with `effective_to IS
     * NULL`, otherwise "inactivo".
     */
    protected $fillable = [
        'tipo_persona',
        'tipo_id',
        'identificacion',
        'nombre',
        'nombre_comercial',
        'linea_negocio',
        'cantidad_empleados',
        'rut',
        'logo',
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

    /**
     * Ciudad (municipality) this entidad is registered in. The legacy
     * `entidad.ciudad_cod` FK column was dropped in Commit 4; the
     * ciudad code now lives on the entidad's primary `direcciones`
     * row. Returns the first `Direccion` row that has a non-null
     * `ciudad_codigo`, or null if no addresses carry one yet.
     */
    public function ciudad(): ?Direccion
    {
        return $this->direcciones()
            ->whereNotNull('ciudad_codigo')
            ->orderByDesc('es_principal')
            ->first();
    }

    // ── Commit 3 shared contact relations ──────────────────────────────

    public function telefonos(): HasMany
    {
        return $this->hasMany(Telefono::class, 'entidad_id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class, 'entidad_id');
    }

    public function direcciones(): HasMany
    {
        return $this->hasMany(Direccion::class, 'entidad_id');
    }

    public function presenciaOnline(): HasMany
    {
        return $this->hasMany(PresenciaOnline::class, 'entidad_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class, 'entidad_id');
    }

    /**
     * Commit 5 — pivot of business-state relations (cliente /
     * prospecto / propia / proveedor). Replaces the legacy
     * `entidad.estado` single-column state with a temporal model
     * that captures the entity's relationship history.
     */
    public function relaciones(): HasMany
    {
        return $this->hasMany(EntidadRelacion::class, 'entidad_id');
    }

    // ── Commit 4 accessors: legacy field semantics via the new tables ──

    /**
     * Primary email address (tipo=trabajo, es_principal=true). Backed
     * by the `emails` table now that `entidad.email` was dropped.
     */
    public function getEmailAttribute(): ?string
    {
        return $this->emails()
            ->where('tipo', 'trabajo')
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->value('email');
    }

    /**
     * Primary phone number (tipo=trabajo, es_principal=true). Backed
     * by the `telefonos` table now that `entidad.telefono` was dropped.
     */
    public function getTelefonoAttribute(): ?string
    {
        return $this->telefonos()
            ->where('tipo', 'trabajo')
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->value('numero');
    }

    /**
     * Primary street address (tipo=oficina, es_principal=true). Backed
     * by the `direcciones` table now that `entidad.direccion` was dropped.
     */
    public function getDireccionAttribute(): ?string
    {
        return $this->direcciones()
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->value('direccion_principal');
    }

    /**
     * Primary web presence URL (tipo=web, es_principal=true). Backed
     * by the `presencia_online` table now that `entidad.dominio` was dropped.
     */
    public function getDominioAttribute(): ?string
    {
        return $this->presenciaOnline()
            ->where('tipo', 'web')
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->value('url');
    }

    /**
     * Commit 5.5: derive `estado` from the `entidad_relacion` pivot.
     * The legacy `entidad.estado` column is gone; the operational
     * state is computed from whether the entity has at least one
     * pivot row with `effective_to IS NULL`.
     *
     * "activo" = at least one relation is currently open.
     * "inactivo" = every relation is closed (or there are none).
     *
     * This accessor is cheap enough to call inline in Resources
     * because the pivot table has an index on
     * `(entidad_id, effective_from DESC)`. For list endpoints, the
     * caller can still pre-filter via a sub-query; see
     * `EloquentEntidadRepository` for the optimized path.
     */
    public function getEstadoAttribute(): string
    {
        $hasOpenRelation = $this->relaciones()
            ->whereNull('effective_to')
            ->exists();

        return $hasOpenRelation ? 'activo' : 'inactivo';
    }
}
