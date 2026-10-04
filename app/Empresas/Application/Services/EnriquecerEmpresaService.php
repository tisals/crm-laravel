<?php

namespace App\Empresas\Application\Services;

use App\Empresas\Application\Filters\HabeasDataFilter;
use App\Empresas\Application\Support\CacheKeyDeriver;
use App\Empresas\Domain\Entities\EmpresaCandidato;
use App\Empresas\Domain\Entities\EmpresaEnriquecida;
use App\Empresas\Domain\Events\EmpresaEnriquecida as EmpresaEnriquecidaEvent;
use App\Empresas\Domain\Events\EmpresaEnriquecimientoNecesitaSeleccion;
use App\Empresas\Domain\Ports\EnriquecimientoRepository;
use App\Empresas\Domain\Ports\McpEmpresaClient;
use App\Empresas\Infrastructure\Decreto\Decreto768Lookup;
use App\Empresas\Infrastructure\Mcp\Exceptions\McpServerUnavailable;
use App\Empresas\Infrastructure\Persistence\EloquentEnriquecimientoRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PR4 of `complementar-entidad` — application orchestrator (full impl).
 *
 * Flow (per design §4 / spec `entity-empresa-enrichment` R-Order):
 *   1. Kill-switch guard  (`empresas.enable_emission`)
 *   2. Cache stampede     (`Cache::lock(key.'.lock', 10)->block(5, …)`)
 *   3. Cache lookup        (`Cache::get` w/ key from CacheKeyDeriver)
 *   4. Inflight flag       (`Cache::add(key.'.inflight', true, 30)`)
 *   5. MCP first-step      (`buscarPorDominio`)
 *      - 0 candidates  → ServiceResult::Failed
 *      - 1 candidate   → MCP second-step `consultarEnriquecida`
 *      - >1 candidates → emit NeedsSelection, persist candidates
 *                       via repository::recordNeedsSelection
 *   6. Habeas filter       (`HabeasDataFilter::apply`)
 *   7. Decreto lookup      (`Decreto768Lookup::lookup`)
 *   8. Persist annex       (`EnriquecimientoRepository::upsert`)
 *   9. Cache payload       (TTL from config)
 *  10. Clear inflight flag
 *  11. Emit event          (`event(new EmpresaEnriquecida(...))`)
 *
 * @see ${SPEC}/specs/entity-empresa-enrichment/spec.md
 * @see design.md §4 / §6 / §9
 *
 * PR4 adds: cache stampede lock, inflight flag, Decreto inference
 * fallback, candidates_json persistence on homonimia, listeners
 * for the EmpresaEnriquecida + EmpresaEnriquecimientoFailed events.
 */
class EnriquecerEmpresaService
{
    public function __construct(
        private readonly McpEmpresaClient $mcp,
        private readonly EnriquecimientoRepository $repository,
        private readonly HabeasDataFilter $habeasFilter,
        private readonly Decreto768Lookup $decreto,
        private readonly CacheKeyDeriver $cacheKeys,
        private readonly LoggerInterface $logger,
        private readonly int $cacheTtl = 86400,
    ) {}

    /**
     * Execute the enrichment pipeline for one entity.
     *
     * @return ServiceResult ALWAYS non-null — callers branch on `status`.
     */
    public function execute(int $entidadId, string $dominio, bool $forceFresh = false): ServiceResult
    {
        // 1. Kill-switch
        if (! (bool) config('empresas.enable_emission', true)) {
            return new ServiceResult(ServiceResultStatus::SkippedKillSwitch);
        }

        $cacheKey = $this->cacheKeys::derive($dominio);
        $lockKey = $cacheKey . '.lock';
        $inflightKey = $cacheKey . '.inflight';

        // 2. Cache hit short-circuit (unless caller forced a refresh)
        if (! $forceFresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                try {
                    $enriched = EmpresaEnriquecida::fromArray($cached);
                    $this->repository->upsert($entidadId, $enriched);
                    $event = new EmpresaEnriquecidaEvent(
                        $entidadId,
                        $enriched->fuente_origen,
                        $enriched->enriquecido_at,
                        $enriched->enriquecimiento_hash,
                    );
                    Event::dispatch($event);

                    $this->logDecision('cache_hit', $enriched->fuente_origen, 1, $cacheKey, $entidadId, $event->event_id);

                    return new ServiceResult(ServiceResultStatus::Enriched, $enriched, $event->event_id);
                } catch (Throwable $e) {
                    // Stale / corrupt cache entry → fall through to MCP.
                    $this->logger->warning('empresa_enrichment.cache_decode_failed', [
                        'entidad_id' => $entidadId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 3. Cache stampede guard — only the first concurrent caller
        // holds the lock and runs the MCP lookup; subsequent callers
        // wait up to 5s for the leader to populate the cache.
        try {
            $result = Cache::lock($lockKey, 10)->block(5, function () use (
                $entidadId, $dominio, $cacheKey, $inflightKey, $forceFresh
            ) {
                // 3a. Re-check the cache under the lock (another worker
                // may have populated it while we waited for the lock).
                if (! $forceFresh) {
                    $cached = Cache::get($cacheKey);
                    if (is_array($cached)) {
                        try {
                            return $this->buildEnrichedFromCache($entidadId, $cached, $cacheKey);
                        } catch (Throwable $e) {
                            $this->logger->warning('empresa_enrichment.cache_decode_failed', [
                                'entidad_id' => $entidadId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                // 3b. Inflight flag — downstream consumers (EntidadResource
                // accessor) can poll this to surface 'pending' status
                // while the MCP lookup is running.
                Cache::add($inflightKey, true, 30);

                try {
                    return $this->runFreshLookup($entidadId, $dominio, $cacheKey);
                } finally {
                    Cache::forget($inflightKey);
                }
            });
        } catch (LockTimeoutException $e) {
            $this->logger->error('empresa_enrichment.lock_timeout', [
                'entidad_id' => $entidadId,
                'dominio' => $dominio,
                'lock_key' => $lockKey,
            ]);

            return new ServiceResult(ServiceResultStatus::Failed);
        }

        return $result;
    }

    /**
     * Run the MCP + Habeas + Decreto + persist pipeline (cache miss path).
     */
    private function runFreshLookup(int $entidadId, string $dominio, string $cacheKey): ServiceResult
    {
        // 4. MCP first-step
        try {
            $candidatosRaw = $this->mcp->buscarPorDominio($dominio);
        } catch (Throwable $e) {
            $this->logger->error('empresa_enrichment.mcp_failure', [
                'stage' => 'buscarPorDominio',
                'entidad_id' => $entidadId,
                'dominio' => $dominio,
                'error' => $e->getMessage(),
            ]);
            return new ServiceResult(ServiceResultStatus::Failed);
        }

        $candidatos = [];
        foreach ((array) $candidatosRaw as $raw) {
            try {
                $candidatos[] = EmpresaCandidato::fromArray((array) $raw);
            } catch (Throwable) {
                // Skip malformed candidates.
            }
        }

        if (count($candidatos) === 0) {
            $this->logger->warning('empresa_enrichment.no_candidates', [
                'entidad_id' => $entidadId,
                'dominio' => $dominio,
            ]);
            return new ServiceResult(ServiceResultStatus::Failed);
        }

        // 4b. Homonimia branch (>1 candidates → no MCP second-step,
        // persist candidates via repository, emit NeedsSelection event).
        if (count($candidatos) > 1) {
            $candidatesPayload = array_map(
                static fn (EmpresaCandidato $c) => [
                    'razon_social' => $c->razon_social,
                    'nit' => $c->nit,
                    'camara_comercio' => $c->camara_comercio,
                    'ciudad' => $c->ciudad,
                    'fuente_origen' => $c->fuente_origen,
                ],
                $candidatos,
            );

            // Persist the candidates via the repository so the GET
            // /api/v1/entidad/{id}/enriquecimiento/candidatos endpoint
            // (PR5) can serve them back without re-running MCP.
            if ($this->repository instanceof EloquentEnriquecimientoRepository) {
                $this->repository->recordNeedsSelection($entidadId, $candidatesPayload);
            }

            $event = new EmpresaEnriquecimientoNecesitaSeleccion(
                $entidadId,
                count($candidatos),
                $candidatesPayload,
            );
            Event::dispatch($event);
            $this->logDecision('homonimia', 'mcp', count($candidatos), $cacheKey, $entidadId, $event->event_id);

            return new ServiceResult(
                ServiceResultStatus::NeedsSelection,
                null,
                $event->event_id,
                $candidatesPayload,
            );
        }

        // 5. MCP second-step (single candidate)
        $candidate = $candidatos[0];
        try {
            $enrichedRaw = $this->mcp->consultarEnriquecida($candidate->razon_social);
        } catch (McpServerUnavailable $e) {
            $this->logger->error('empresa_enrichment.mcp_failure', [
                'stage' => 'consultarEnriquecida',
                'entidad_id' => $entidadId,
                'dominio' => $dominio,
                'error' => $e->getMessage(),
            ]);
            return new ServiceResult(ServiceResultStatus::Failed);
        } catch (Throwable $e) {
            $this->logger->error('empresa_enrichment.mcp_failure', [
                'stage' => 'consultarEnriquecida',
                'entidad_id' => $entidadId,
                'dominio' => $dominio,
                'error' => $e->getMessage(),
            ]);
            return new ServiceResult(ServiceResultStatus::Failed);
        }

        try {
            $enriched = EmpresaEnriquecida::fromArray((array) $enrichedRaw);
        } catch (Throwable $e) {
            $this->logger->error('empresa_enrichment.payload_decode_failed', [
                'entidad_id' => $entidadId,
                'dominio' => $dominio,
                'error' => $e->getMessage(),
            ]);
            return new ServiceResult(ServiceResultStatus::Failed);
        }

        // 6. Habeas filter — the operating mode is read from config
        // (re-read each call so test-time overrides take effect).
        $tipoPersona = $this->detectTipoPersona($entidadId);
        $filtered = $this->habeasFilter->apply($enriched, $tipoPersona, null, $entidadId);

        if ($filtered === null) {
            $this->logger->info('empresa_enrichment.habeas_excluded', [
                'entidad_id' => $entidadId,
                'modo' => config('empresas.habeas_data_mode', 'mask'),
            ]);
            return new ServiceResult(ServiceResultStatus::SkippedHabeas);
        }
        $enriched = $filtered;

        // 7. Decreto lookup — overrides the MCP class_riesgo + sector
        // when the matrix has an exact match for the 4-digit CIIU.
        // Falls back to inference when matrix is populated but no
        // exact match exists.
        $decreto = Decreto768Lookup::lookup($enriched->ciiu_codigo);
        if ($decreto !== null) {
            $enriched = $this->applyDecretoOverride($enriched, $decreto);
        }

        // 8. Persist + cache + emit
        $this->repository->upsert($entidadId, $enriched);
        Cache::put($cacheKey, $this->payloadForCache($enriched), $this->cacheTtl);

        $event = new EmpresaEnriquecidaEvent(
            $entidadId,
            $enriched->fuente_origen,
            $enriched->enriquecido_at,
            $enriched->enriquecimiento_hash,
        );
        Event::dispatch($event);

        $this->logDecision('hit', $enriched->fuente_origen, 1, $cacheKey, $entidadId, $event->event_id);

        return new ServiceResult(ServiceResultStatus::Enriched, $enriched, $event->event_id);
    }

    /**
     * Cache-hit helper used inside the lock body when another worker
     * already populated the cache while we waited.
     */
    private function buildEnrichedFromCache(int $entidadId, array $cached, string $cacheKey): ServiceResult
    {
        $enriched = EmpresaEnriquecida::fromArray($cached);
        $this->repository->upsert($entidadId, $enriched);
        $event = new EmpresaEnriquecidaEvent(
            $entidadId,
            $enriched->fuente_origen,
            $enriched->enriquecido_at,
            $enriched->enriquecimiento_hash,
        );
        Event::dispatch($event);

        $this->logDecision('cache_hit', $enriched->fuente_origen, 1, $cacheKey, $entidadId, $event->event_id);

        return new ServiceResult(ServiceResultStatus::Enriched, $enriched, $event->event_id);
    }

    /**
     * Resolve the entidad's tipo_persona via direct Eloquent lookup.
     * PR3 ships this inline (no separate Entidad port) because the
     * Empresas module is self-contained.
     */
    private function detectTipoPersona(int $entidadId): string
    {
        try {
            $entidad = \App\Models\Entidad::withTrashed()->find($entidadId);
            if ($entidad !== null) {
                return (string) ($entidad->tipo_persona ?? 'Juridica');
            }
        } catch (Throwable) {
            // ignore — fall through to default
        }

        return 'Juridica';
    }

    private function applyDecretoOverride(EmpresaEnriquecida $data, array $decreto): EmpresaEnriquecida
    {
        // The Decreto matrix is authoritative for clase_riesgo + sector;
        // the MCP-provided values are fallback only when the matrix has no row.
        return new EmpresaEnriquecida(
            razon_social: $data->razon_social,
            nit: $data->nit,
            numero_empleados: $data->numero_empleados,
            ciiu_codigo: $data->ciiu_codigo,
            ciiu_descripcion: $data->ciiu_descripcion,
            clase_riesgo_num: (int) ($decreto['clase_riesgo_ul_num'] ?? $data->clase_riesgo_num),
            clase_riesgo_desc: (string) ($decreto['clase_riesgo_ul_desc'] ?? $data->clase_riesgo_desc),
            sector_economico: (string) ($decreto['sector_economico'] ?? $data->sector_economico),
            fuente_origen: $data->fuente_origen,
            enriquecido_at: $data->enriquecido_at,
            enriquecimiento_hash: $data->enriquecimiento_hash,
        );
    }

    private function payloadForCache(EmpresaEnriquecida $data): array
    {
        return [
            'razon_social' => $data->razon_social,
            'nit' => $data->nit,
            'numero_empleados' => $data->numero_empleados,
            'ciiu_codigo' => $data->ciiu_codigo,
            'ciiu_descripcion' => $data->ciiu_descripcion,
            'clase_riesgo_num' => $data->clase_riesgo_num,
            'clase_riesgo_desc' => $data->clase_riesgo_desc,
            'sector_economico' => $data->sector_economico,
            'fuente_origen' => $data->fuente_origen,
            'enriquecido_at' => $data->enriquecido_at,
            'enriquecimiento_hash' => $data->enriquecimiento_hash,
        ];
    }

    private function logDecision(
        string $decision,
        string $fuente,
        int $candidatesCount,
        string $cacheKey,
        int $entidadId,
        string $eventId,
    ): void {
        $this->logger->info('empresa.enrichment.decision', [
            'decision' => $decision,
            'fuente' => $fuente,
            'candidates_count' => $candidatesCount,
            'cache_key' => $cacheKey,
            'entidad_id' => $entidadId,
            'event_id' => $eventId,
            'domain' => $cacheKey, // mirror spec field name
        ]);
    }
}