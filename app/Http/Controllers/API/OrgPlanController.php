<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Entidad;
use App\Models\Producto;
use App\Models\Servicio;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * OrgPlanController — exposes the active billing plan for an org to
 * Mercurio (and any other internal client).
 *
 * Why this exists:
 *   Mercurio needs to know an org's tier (starter/pro/internal/enterprise)
 *   to enforce rate-limits, storage quotas, and feature gating. The
 *   plan data lives in the CRM (this service); Mercurio is the consumer.
 *   Mercurio caches the response for 1h (see services.billing) and
 *   invalidates on contract changes via POST /api/v1/internal/billing/invalidate.
 *
 * Source-of-truth: `servicio` (a person-to-product relationship with
 * `estado = 'activo'`, joined to `producto` with `tipo = 'suscripcion'`).
 * Tier mapping is derived from the product name (slug) — products
 * named `starter-kit`, `pro`, `enterprise`, `internal` map to the
 * four tiers.
 *
 * Auth: Sanctum (`auth:sanctum` middleware). Only the
 * `mercurio-bridge` service-account token may call this.
 */
class OrgPlanController extends Controller
{
    use ApiResponse;

    private const CACHE_TTL = 3600;
    private const CACHE_KEY_PREFIX = 'org:plan:';

    /**
     * Map of product slug → Mercurio tier. Add new tiers here when
     * new plans are created in `producto`.
     */
    private const TIER_MAP = [
        'starter-kit'  => 'starter',
        'starter'      => 'starter',
        'pro'          => 'pro',
        'enterprise'   => 'enterprise',
        'internal'     => 'internal',
    ];

    /**
     * Default quotas per tier. Stored here (not in DB) because they
     * are tightly coupled to the tier name; moving them to DB would
     * require a schema migration every time we tweak a number.
     */
    private const TIER_QUOTAS = [
        'starter'    => ['rpm' => 30,   'storage_mb' => 100,  'features' => ['crm_add', 'crm_query', 'rag_query']],
        'pro'        => ['rpm' => 100,  'storage_mb' => 1024, 'features' => ['crm_add', 'crm_query', 'rag_query', 'kb_upload', 'telegram_command', 'telegram_message']],
        'enterprise' => ['rpm' => 1000, 'storage_mb' => 10240, 'features' => ['crm_add', 'crm_query', 'rag_query', 'kb_upload', 'telegram_command', 'telegram_message', 'webhook_outbound']],
        'internal'   => ['rpm' => 10000, 'storage_mb' => 102400, 'features' => ['crm_add', 'crm_query', 'rag_query', 'kb_upload', 'telegram_command', 'telegram_message', 'webhook_outbound']],
    ];

    public function show(string $tenant_id): JsonResponse
    {
        $cache_key = self::CACHE_KEY_PREFIX . $tenant_id;
        $cached = Cache::get($cache_key);
        if ($cached !== null) {
            return $this->successResponse($cached);
        }

        // Resolve the org. Mercurio's `tenant_id` is the entidad's
        // slug (kebab-case, stable identifier) or display name. We
        // try slug first (faster, unique, locale-stable) and fall
        // back to nombre for backward compat.
        $entidad = Entidad::where('slug', $tenant_id)
            ->orWhere('nombre', $tenant_id)
            ->first();

        if (! $entidad) {
            return $this->errorResponse('ORG_NOT_FOUND', 404, ['tenant_id' => $tenant_id]);
        }

        // Find the active subscription service for this org.
        $servicio = Servicio::whereHas('persona', function ($q) use ($entidad) {
                $q->where('entidad_id', $entidad->id);
            })
            ->where('estado', 'activo')
            ->whereHas('producto', function ($q) {
                $q->where('tipo', 'suscripcion');
            })
            ->with('producto')
            ->orderByDesc('fecha_inicio')
            ->first();

        if (! $servicio) {
            return $this->errorResponse('NO_ACTIVE_PLAN', 404, ['tenant_id' => $tenant_id]);
        }

        $producto = $servicio->producto;
        $tier = self::TIER_MAP[$producto->slug ?? ''] ?? 'starter';
        $quotas = self::TIER_QUOTAS[$tier];

        $payload = [
            'org_id'           => $entidad->id,
            'tenant_id'        => $tenant_id,
            'plan_id'          => $producto->id,
            'plan_name'        => $producto->nombre,
            'plan_slug'        => $producto->slug,
            'tier'             => $tier,
            'rpm_quota'        => $quotas['rpm'],
            'storage_quota_mb' => $quotas['storage_mb'],
            'active_features'  => $quotas['features'],
            'expires_at'       => $servicio->fecha_fin?->toIso8601String(),
            'servicio_id'      => $servicio->id,
        ];

        Cache::put($cache_key, $payload, self::CACHE_TTL);

        return $this->successResponse($payload);
    }

    /**
     * Drop the cached plan for a tenant. Called by the CRM after a
     * contract change (plan upgrade, downgrade, expiry) so Mercurio
     * stops serving stale tier/quota info until the next read
     * refreshes it from this endpoint.
     *
     * Body: `{ "tenant_id": "..." }` (or query string). Idempotent.
     */
    public function invalidate(\Illuminate\Http\Request $request): JsonResponse
    {
        $tenant_id = $request->input('tenant_id') ?? $request->query('tenant_id');
        if (! $tenant_id) {
            return $this->errorResponse('TENANT_ID_REQUIRED', 400);
        }

        $cache_key = self::CACHE_KEY_PREFIX . $tenant_id;
        Cache::forget($cache_key);

        return $this->successResponse([
            'tenant_id' => $tenant_id,
            'invalidated' => true,
        ]);
    }
}
