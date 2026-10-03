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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PR3 of `complementar-entidad` — application orchestrator (skeleton).
 *
 * Flow (per design §4 / spec `entity-empresa-enrichment` R-Order):
 *   1. Kill-switch guard  (`empresas.enable_emission`)
 *   2. Cache lookup        (`Cache::get` w/ key from CacheKeyDeriver)
 *   3. MCP first-step      (`buscarPorDominio`)
 *      - 0 candidates  → emit Failed
 *      - 1 candidate   → MCP second-step `consultarEnriquecida`
 *      - >1 candidates → emit NeedsSelection, skip persistence
 *   4. Habeas filter       (`HabeasDataFilter::apply`)
 *   5. Decreto lookup      (`Decreto768Lookup::lookup`)
 *   6. Persist annex       (`EnriquecimientoRepository::upsert`)
 *   7. Emit event          (`event(new EmpresaEnriquecida(...))`)
 *
 * Note: PR3 ships the flow skeleton only. Real HTTP transport and
 * queue wiring land in PR4; controllers in PR5.
 *
 * PR3 ships the FULL SKELETON so PR4 can focus on the queue job +
 * the Habeas mode extensions; PR5 wires the controllers.
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
        $decision = 'miss';

        // 1. Kill-switch
        if (! (bool) config('empresas.enable_emission', true)) {
            return new ServiceResult(ServiceResultStatus::SkippedKillSwitch);
        }

        $cacheKey = $this->cacheKeys::derive($dominio);

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

        // 3. MCP first-step
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

        // 3b. Homonimia branch (>1 candidates → no persistence)
        if (count($candidatos) > 1) {
            $event = new EmpresaEnriquecimientoNecesitaSeleccion(
                $entidadId,
                count($candidatos),
                array_map(
                    static fn (EmpresaCandidato $c) => [
                        'razon_social' => $c->razon_social,
                        'nit' => $c->nit,
                        'fuente_origen' => $c->fuente_origen,
                    ],
                    $candidatos,
                ),
            );
            Event::dispatch($event);
            $this->logDecision('homonimia', 'mcp', count($candidatos), $cacheKey, $entidadId, $event->event_id);

            return new ServiceResult(
                ServiceResultStatus::NeedsSelection,
                null,
                $event->event_id,
                array_map(
                    static fn (EmpresaCandidato $c) => [
                        'razon_social' => $c->razon_social,
                        'nit' => $c->nit,
                        'fuente_origen' => $c->fuente_origen,
                    ],
                    $candidatos,
                ),
            );
        }

        // 4. MCP second-step (single candidate)
        $candidate = $candidatos[0];
        try {
            $enrichedRaw = $this->mcp->consultarEnriquecida($candidate->razon_social);
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

        // 5. Habeas filter — lookup tipo_persona via the repository if
        // the entity row is reachable. For PR3 we keep the persona
        // classification opt-in (PR5 wires the Entidad lookup).
        $tipoPersona = $this->detectTipoPersona($entidadId);
        $filtered = $this->habeasFilter->apply($enriched, $tipoPersona);

        if ($filtered === null) {
            $this->logger->info('empresa_enrichment.habeas_excluded', [
                'entidad_id' => $entidadId,
                'modo' => config('empresas.habeas_data_mode', 'mask'),
            ]);
            return new ServiceResult(ServiceResultStatus::SkippedHabeas);
        }
        $enriched = $filtered;

        // 6. Decreto lookup — overrides the MCP class_riesgo + sector
        // when the matrix has an exact match for the 4-digit CIIU.
        $decreto = Decreto768Lookup::lookup($enriched->ciiu_codigo);
        if ($decreto !== null) {
            $enriched = $this->applyDecretoOverride($enriched, $decreto);
        }

        // 7. Persist + emit
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
     * Resolve the entidad's tipo_persona via direct Eloquent lookup.
     * PR3 ships this inline (no separate Entidad port) because the
     * Empresas module is self-contained; PR5 will swap it for a
     * proper Entidad port if more callers need it.
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