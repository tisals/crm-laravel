<?php

namespace App\Empresas\Providers;

use App\Empresas\Application\Filters\HabeasDataFilter;
use App\Empresas\Application\Listeners\EmpresaEnriquecidaLogger;
use App\Empresas\Application\Listeners\EmpresaEnriquecimientoFailedLogger;
use App\Empresas\Application\Services\EnriquecerEmpresaService;
use App\Empresas\Application\Support\CacheKeyDeriver;
use App\Empresas\Domain\Events\EmpresaEnriquecida;
use App\Empresas\Domain\Events\EmpresaEnriquecimientoFailed;
use App\Empresas\Domain\Ports\EnriquecimientoRepository;
use App\Empresas\Domain\Ports\McpEmpresaClient;
use App\Empresas\Infrastructure\Mcp\McpEmpresaClientHttp;
use App\Empresas\Infrastructure\Mcp\McpEmpresaClientRestFake;
use App\Empresas\Infrastructure\Persistence\EloquentEnriquecimientoRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * PR4 of `complementar-entidad` — EmpresasServiceProvider.
 *
 * Wires the `app/Empresas/` 4-layer module into the Laravel
 * service container. The container resolution follows the spec:
 *   - production → `McpEmpresaClientHttp` (real HTTP/SSE adapter)
 *   - testing    → `McpEmpresaClientRestFake` (in-memory test adapter)
 *
 * PR3 shipped only the bindings (no listeners, no routes).
 * PR4 wires the observability listeners in `boot()`; PR5 wires the
 * controllers + routes.
 */
class EmpresasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../../config/empresas.php',
            'empresas',
        );

        // Persistence port → Eloquent impl.
        $this->app->bind(EnriquecimientoRepository::class, EloquentEnriquecimientoRepository::class);

        // MCP port → RestFake in testing, Http in all other envs.
        // Use singleton() in testing so per-test fixture state survives
        // the multiple container resolutions performed inside one test.
        if ($this->app->environment('testing')) {
            $this->app->singleton(McpEmpresaClient::class, function ($app) {
                return new McpEmpresaClientRestFake();
            });
        } else {
            $this->app->bind(McpEmpresaClient::class, function ($app) {
                return new McpEmpresaClientHttp(
                    http: $app->make(\Illuminate\Http\Client\Factory::class),
                    url: config('empresas.mcp_url'),
                    timeout: (int) config('empresas.mcp_timeout', 10),
                    maxRetries: (int) config('empresas.mcp_max_retries', 3),
                );
            });
        }

        // Habeas filter — bound to the default 'mask' mode. The PR4
        // extension added audit logging via the 'empresas' channel;
        // when the container is bootstrapped the filter pulls the
        // logger from Log::channel('empresas') automatically.
        $this->app->bind(HabeasDataFilter::class, function ($app) {
            return new HabeasDataFilter(
                defaultMode: (string) config('empresas.habeas_data_mode', 'mask'),
            );
        });

        // Application service — orchestrates the pipeline.
        $this->app->bind(EnriquecerEmpresaService::class, function ($app) {
            try {
                $logger = $app->make(LoggerInterface::class);
            } catch (BindingResolutionException) {
                $logger = Log::channel();
            }

            return new EnriquecerEmpresaService(
                mcp: $app->make(McpEmpresaClient::class),
                repository: $app->make(EnriquecimientoRepository::class),
                habeasFilter: $app->make(HabeasDataFilter::class),
                decreto: new \App\Empresas\Infrastructure\Decreto\Decreto768Lookup(),
                cacheKeys: new CacheKeyDeriver(),
                logger: $logger,
                cacheTtl: (int) config('empresas.cache_ttl', 86400),
            );
        });
    }

    public function boot(): void
    {
        // PR4 — Observability listeners for the enrichment pipeline.
        // Registered here so feature tests can assert via Event::fake()
        // and so production emits structured logs out-of-the-box.
        Event::listen(EmpresaEnriquecida::class, [EmpresaEnriquecidaLogger::class, 'handle']);
        Event::listen(EmpresaEnriquecimientoFailed::class, [EmpresaEnriquecimientoFailedLogger::class, 'handle']);
    }
}