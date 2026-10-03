<?php

namespace App\Empresas\Application\Services;

use App\Empresas\Domain\Entities\EmpresaEnriquecida;

/**
 * PR3 of `complementar-entidad` — application service result DTO.
 *
 * Plain immutable value object returned by `EnriquecerEmpresaService`.
 * Carries:
 *   - status (enum, ALWAYS set),
 *   - enriched payload (EmpresaEnriquecida|null) — present for Enriched,
 *   - event_id (string|null) — UUIDv4 stamped by the emitted event,
 *   - candidates list (array) — present for NeedsSelection.
 */
final class ServiceResult
{
    public function __construct(
        public readonly ServiceResultStatus $status,
        public readonly ?EmpresaEnriquecida $enriched = null,
        public readonly ?string $event_id = null,
        public readonly array $candidates = [],
    ) {}
}