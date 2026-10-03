<?php

namespace App\Empresas\Domain\Ports;

use App\Empresas\Domain\Entities\EmpresaEnriquecida;
use App\Models\EntidadEnriquecimiento;

/**
 * PR3 of `complementar-entidad` — entity-empresa-enrichment D-port.
 *
 * Persistence contract for the `entidad_enriquecimiento` 1:1 annex table.
 * The application layer depends ONLY on this interface; the Eloquent
 * implementation lives in `Infrastructure/Persistence/`.
 *
 * Implementations:
 *   - `EloquentEnriquecimientoRepository` — production
 *   - (PR4) fakes for unit tests
 */
interface EnriquecimientoRepository
{
    /**
     * Insert-or-update the annex row for `$entidadId` with the resolved
     * enrichment payload. The 1:1 contract means at most one row per
     * `entidad_id` (UNIQUE index on the schema).
     */
    public function upsert(int $entidadId, EmpresaEnriquecida $data): void;

    /**
     * Read the current annex row for `$entidadId`, or null when none
     * exists yet.
     */
    public function findByEntidadId(int $entidadId): ?EntidadEnriquecimiento;

    /**
     * Return the cached candidates array (from the most recent homonimia
     * event) for `$entidadId`. Empty array when no candidates are pending
     * selection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidatos(int $entidadId): array;

    /**
     * Convenience check: returns true iff the annex row exists AND its
     * `enrichment_status` column equals 'needs_selection'.
     */
    public function needsSelection(int $entidadId): bool;
}