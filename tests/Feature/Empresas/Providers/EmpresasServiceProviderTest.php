<?php

namespace Tests\Feature\Empresas\Providers;

use App\Empresas\Application\Filters\HabeasDataFilter;
use App\Empresas\Application\Services\EnriquecerEmpresaService;
use App\Empresas\Domain\Ports\EnriquecimientoRepository;
use App\Empresas\Domain\Ports\McpEmpresaClient;
use App\Empresas\Infrastructure\Mcp\McpEmpresaClientRestFake;
use App\Empresas\Infrastructure\Persistence\EloquentEnriquecimientoRepository;
use App\Empresas\Providers\EmpresasServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.10 (RED).
 *
 * The EmpresasServiceProvider wires the Empresas module into the
 * Laravel container. In `testing` env, `McpEmpresaClient` resolves
 * to the RestFake; in production env, to the Http adapter.
 */
class EmpresasServiceProviderTest extends TestCase
{
    #[Test]
    public function provider_class_resolves_from_bootstrap(): void
    {
        // The provider should be in the bootstrapped list.
        $providers = $this->app->getLoadedProviders();
        $this->assertArrayHasKey(EmpresasServiceProvider::class, $providers);
        $this->assertArrayHasKey(\App\Providers\AppServiceProvider::class, $providers);
    }

    #[Test]
    public function mcp_empresa_client_resolves_to_rest_fake_in_testing(): void
    {
        $this->assertInstanceOf(McpEmpresaClientRestFake::class, $this->app->make(McpEmpresaClient::class));
    }

    #[Test]
    public function enriquecimiento_repository_resolves_to_eloquent_impl(): void
    {
        $this->assertInstanceOf(EloquentEnriquecimientoRepository::class, $this->app->make(EnriquecimientoRepository::class));
    }

    #[Test]
    public function habeas_data_filter_resolves_to_concrete(): void
    {
        $f = $this->app->make(HabeasDataFilter::class);
        $this->assertInstanceOf(HabeasDataFilter::class, $f);
    }

    #[Test]
    public function enriquecer_empresa_service_resolves_with_all_dependencies(): void
    {
        $service = $this->app->make(EnriquecerEmpresaService::class);
        $this->assertInstanceOf(EnriquecerEmpresaService::class, $service);
    }

    #[Test]
    public function empresas_config_is_merged_from_default_path(): void
    {
        $this->assertNotNull(config('empresas.mcp_url'));
        $this->assertSame(86400, config('empresas.cache_ttl'));
        $this->assertSame('mask', config('empresas.habeas_data_mode'));
        $this->assertTrue(config('empresas.enable_emission'));
        $this->assertSame(10, config('empresas.mcp_timeout'));
        $this->assertSame(3, config('empresas.mcp_max_retries'));
    }
}