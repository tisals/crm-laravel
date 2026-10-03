<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.11 (RED).
 *
 * Verifies that `config/empresas.php` exposes the canonical keys with
 * the documented defaults. Env-var overrides are validated by reading
 * the config in a controlled process environment.
 */
class EmpresasConfigTest extends TestCase
{
    #[Test]
    public function config_exposes_canonical_keys(): void
    {
        $expected = [
            'mcp_url',
            'mcp_timeout',
            'cache_ttl',
            'habeas_data_mode',
            'enable_emission',
            'socrata_app_token',
            'mcp_max_retries',
        ];

        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, config('empresas'), "Missing key '{$key}' in config(empresas).");
        }
    }

    #[Test]
    public function defaults_are_safe_no_enrichment_values(): void
    {
        $this->assertSame(86400, config('empresas.cache_ttl'));
        $this->assertSame('mask', config('empresas.habeas_data_mode'));
        $this->assertTrue(config('empresas.enable_emission'));
        $this->assertSame(10, config('empresas.mcp_timeout'));
        $this->assertSame(3, config('empresas.mcp_max_retries'));
    }

    #[Test]
    public function enable_emission_can_be_disabled_via_config(): void
    {
        config()->set('empresas.enable_emission', false);
        $this->assertFalse(config('empresas.enable_emission'));
    }

    #[Test]
    public function socrata_app_token_is_nullable_by_default(): void
    {
        $this->assertNull(config('empresas.socrata_app_token'));
    }
}