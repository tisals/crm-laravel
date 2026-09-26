<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Entidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
 * Source-of-truth: the `servicios` table has both `entidad_id` (FK)
 * AND a `tier` column populated by the contract-renewal flow. We use
 * the `tier` column directly (no tier-mapping logic), and join
 * `detalle_servicios` to find the `producto` for display name.
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
     * Default quotas per tier. Stored here (not in DB) because they
     * are tightly coupled to the tier name; moving them to DB would
     * require a schema migration every time we tweak a number.
     */
    private const TIER_QUOTAS = [
        'starter'    => ['rpm' => 30,    'storage_mb' => 100,   'features' => ['crm_add', 'crm_query', 'rag_query']],
        'pro'        => ['rpm' => 100,   'storage_mb' => 1024,  'features' => ['crm_add', 'crm_query', 'rag_query', 'kb_upload', 'telegram_command', 'telegram_message']],
        'enterprise' => ['rpm' => 1000,  'storage_mb' => 10240, 'features' => ['crm_add', 'crm_query', 'rag_query', 'kb_upload', 'telegram_command', 'telegram_message', 'webhook_outbound']],
        'internal'   => ['rpm' => 10000, 'storage_mb' => 102400, 'features' => ['crm_add', 'crm_query', 'rag_query', 'kb_upload', 'telegram_command', 'telegram_message', 'webhook_outbound']],
    ];

    /**
     * GET /api/v1/admin/orgs/{tenant_id}/plan
     *
     * `tenant_id` is Mercurio's stable identifier for the org, which
     * matches `entidad.slug` (we added this column 2026-09-25 with a
     * one-shot backfill; operators can override the slug per-entity
     * via the CRM admin UI).
     */
    public function show(string $tenant_id): JsonResponse
    {
        $cache_key = self::CACHE_KEY_PREFIX . $tenant_id;
        $cached = Cache::get($cache_key);
        if ($cached !== null) {
            return $this->successResponse($cached);
        }

        $entidad = Entidad::where('slug', $tenant_id)
            ->orWhere('nombre', $tenant_id)
            ->first();

        if (! $entidad) {
            return $this->errorResponse('ORG_NOT_FOUND', 404, ['tenant_id' => $tenant_id]);
        }

        // Find the active subscription service for this org. The
        // `servicios` table has `entidad_id` as a direct FK (no need
        // to join personas). We pick the most recent active one.
        $servicio = DB::table('servicios as s')
            ->leftJoin('detalle_servicios as ds', 'ds.servicio_id', '=', 's.id')
            ->leftJoin('productos as p', 'p.id', '=', 'ds.producto_id')
            ->where('s.entidad_id', $entidad->id)
            ->where('s.estado', 'activo')
            ->whereNull('s.deleted_at')
            ->orderByDesc('s.fecha_inicio')
            ->select(
                's.id as servicio_id',
                's.tier',
                's.plan_id',
                's.subscription_id',
                's.fecha_fin',
                'p.id as producto_id',
                'p.nombre as producto_nombre'
            )
            ->first();

        if (! $servicio) {
            return $this->errorResponse('NO_ACTIVE_PLAN', 404, [
                'tenant_id' => $tenant_id,
                'entidad_id' => $entidad->id,
            ]);
        }

        // The `tier` column is the authoritative source. Fall back to
        // 'starter' only if the row is missing the column (legacy data
        // pre-2026-09-25).
        $tier = $servicio->tier ?: 'starter';
        if (! array_key_exists($tier, self::TIER_QUOTAS)) {
            $tier = 'starter'; // unknown tier → safe default
        }
        $quotas = self::TIER_QUOTAS[$tier];

        $payload = [
            'org_id'           => $entidad->id,
            'tenant_id'        => $tenant_id,
            'plan_id'          => $servicio->plan_id,
            'subscription_id'  => $servicio->subscription_id,
            'tier'             => $tier,
            'rpm_quota'        => $quotas['rpm'],
            'storage_quota_mb' => $quotas['storage_mb'],
            'active_features'  => $quotas['features'],
            'expires_at'       => $servicio->fecha_fin,
            'servicio_id'      => $servicio->servicio_id,
            'producto_id'      => $servicio->producto_id,
            'producto_nombre'  => $servicio->producto_nombre,
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
    public function invalidate(Request $request): JsonResponse
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
