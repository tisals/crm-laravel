<?php

namespace App\Empresas\Application\Support;

/**
 * PR3 of `complementar-entidad` — entity-empresa-enrichment D-cache.
 *
 * Centralised cache-key derivation for the enrichment pipeline so
 * downstream layers cannot drift on key shape. The shape is:
 *
 *     empresa_enrichment:dominio:{sha256(strtolower(trim($dominio)))}
 *
 * The helper is intentionally a stateless static class — pure function
 * over its input — so it can be reused by the application service,
 * the job dispatcher, and any future invalidation logic without
 * pulling in Laravel facades (Dependency Inversion).
 *
 * @see design.md §6 (cache contract)
 */
final class CacheKeyDeriver
{
    private const NAMESPACE = 'empresa_enrichment:dominio:';

    /**
     * Compute the canonical cache key for a domain lookup.
     */
    public static function derive(string $dominio): string
    {
        $normalised = strtolower(trim($dominio));

        return self::NAMESPACE . hash('sha256', $normalised);
    }
}