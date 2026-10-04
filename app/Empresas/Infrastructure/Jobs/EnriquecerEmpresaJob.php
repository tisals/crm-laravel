<?php

namespace App\Empresas\Infrastructure\Jobs;

use App\Empresas\Application\Services\EnriquecerEmpresaService;
use App\Empresas\Domain\Events\EmpresaEnriquecimientoFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * PR4 of `complementar-entidad` — entity-empresa-enrichment D-job.
 *
 * Queued boundary between the post-commit dispatch (PR5 hooks) and the
 * application service. Mirrors `DispatchOutboundWebhookJob`:
 *
 *   - Queue:       'webhooks'
 *   - Tries:       3
 *   - Backoff:     [60, 120, 180] seconds (exponential)
 *   - Timeout:     30s (failOnTimeout)
 *
 * The handler re-reads `principalDominio()` from the database at
 * execution time so a stale payload (entity updated AFTER dispatch)
 * is dropped — the handler uses `$dominio` from the constructor as
 * the authoritative lookup key.
 *
 * On terminal failure (after retries exhausted, or fatal error) the
 * job's `failed()` hook emits an `EmpresaEnriquecimientoFailed` event
 * carrying the exception message and attempt count.
 *
 * @see ${SPEC}/specs/entity-empresa-enrichment/spec.md (R-Order §4)
 * @see design.md §10
 */
class EnriquecerEmpresaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $failOnTimeout = 30;

    public function __construct(
        public readonly int $entidadId,
        public readonly string $dominio,
        public readonly bool $forceFresh = false,
    ) {
        $this->queue = 'webhooks';
    }

    /**
     * Backoff schedule per design §10 — minutes between retries.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 120, 180];
    }

    /**
     * Execute the job. The job does NOT inspect the `ServiceResult`
     * itself — listeners (registered in `EmpresasServiceProvider::boot()`)
     * are responsible for downstream side effects (audit, telemetry).
     *
     * Exceptions thrown here trigger Laravel's retry/backoff policy.
     * If the worker exhausts `$tries`, Laravel calls `failed()`.
     */
    public function handle(EnriquecerEmpresaService $service): void
    {
        $service->execute(
            entidadId: $this->entidadId,
            dominio: $this->dominio,
            forceFresh: $this->forceFresh,
        );
    }

    /**
     * Terminal-failure hook (called by the queue worker when the job
     * exhausts its tries). Emits a structured event so listeners can
     * notify admins, increment metrics, etc.
     */
    public function failed(?Throwable $exception): void
    {
        $motivo = $exception !== null
            ? trim($exception->getMessage()) !== ''
                ? $exception->getMessage()
                : $exception::class
            : 'unknown';

        $attempts = method_exists($this, 'attempts')
            ? (int) $this->attempts()
            : 1;

        Event::dispatch(new EmpresaEnriquecimientoFailed(
            entidad_id: $this->entidadId,
            motivo: $motivo,
            attempts: $attempts,
        ));
    }
}