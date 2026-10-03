<?php

namespace Tests\Unit\Empresas\Application\Support;

use App\Empresas\Application\Support\CacheKeyDeriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.4 (RED).
 *
 * The cache key must:
 *   - be deterministic across PHP processes (same input → same key),
 *   - normalize case so users typing 'TecnoINN.com' don't miss the
 *     cached entry for 'tecnoinn.com',
 *   - trim leading/trailing whitespace (some clients send ' host.com '),
 *   - yield a 64-char hex string (SHA-256),
 *   - be namespaced under `empresa_enrichment:dominio:` so a future
 *     `Cache::tags()` call can flush it cleanly.
 */
class CacheKeyDeriverTest extends TestCase
{
    #[Test]
    public function derive_returns_namespaced_sha256_key(): void
    {
        $key = CacheKeyDeriver::derive('tecnoinnsoft.com');

        $this->assertStringStartsWith('empresa_enrichment:dominio:', $key);
        $suffix = substr($key, strlen('empresa_enrichment:dominio:'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $suffix);
    }

    #[Test]
    public function derive_is_case_insensitive(): void
    {
        $this->assertSame(
            CacheKeyDeriver::derive('TecnoINNsoft.com'),
            CacheKeyDeriver::derive('tecnoinnsoft.com'),
        );
        $this->assertSame(
            CacheKeyDeriver::derive('TECNOINNSOFT.COM'),
            CacheKeyDeriver::derive('tecnoinnsoft.com'),
        );
    }

    #[Test]
    public function derive_trims_surrounding_whitespace(): void
    {
        $this->assertSame(
            CacheKeyDeriver::derive('  tecnoinnsoft.com  '),
            CacheKeyDeriver::derive('tecnoinnsoft.com'),
        );
        $this->assertSame(
            CacheKeyDeriver::derive("\ttecnoinn.com\n"),
            CacheKeyDeriver::derive('tecnoinn.com'),
        );
    }

    #[Test]
    public function derive_is_deterministic_across_repeated_calls(): void
    {
        $first = CacheKeyDeriver::derive('minerva.example.com');
        $second = CacheKeyDeriver::derive('minerva.example.com');
        $third = CacheKeyDeriver::derive('minerva.example.com');

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }

    #[Test]
    public function derive_produces_distinct_keys_for_distinct_inputs(): void
    {
        $this->assertNotSame(
            CacheKeyDeriver::derive('a.com'),
            CacheKeyDeriver::derive('b.com')
        );
    }

    #[Test]
    public function derive_handles_empty_string_deterministically(): void
    {
        // Empty input still produces a valid (non-throwing) SHA-256 hash —
        // the application layer is responsible for not calling derive()
        // with empty strings, but the helper itself must not explode.
        $a = CacheKeyDeriver::derive('');
        $b = CacheKeyDeriver::derive('');

        $this->assertStringStartsWith('empresa_enrichment:dominio:', $a);
        $this->assertSame($a, $b);
    }

    #[Test]
    public function derive_matches_a_manually_computed_sha256(): void
    {
        $dominio = 'TecnoINNsoft.com';
        $expected = 'empresa_enrichment:dominio:' . hash('sha256', strtolower(trim($dominio)));

        $this->assertSame($expected, CacheKeyDeriver::derive($dominio));
    }
}