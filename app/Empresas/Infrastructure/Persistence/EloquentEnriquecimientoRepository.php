<?php

namespace App\Empresas\Infrastructure\Persistence;

use App\Empresas\Domain\Entities\EmpresaEnriquecida;
use App\Empresas\Domain\Ports\EnriquecimientoRepository;
use App\Models\EntidadEnriquecimiento;
use Illuminate\Support\Facades\DB;

/**
 * PR3 of `complementar-entidad` — Eloquent implementation of the
 * `EnriquecimientoRepository` port.
 *
 * Operates on the `entidad_enriquecimiento` 1:1 annex table that
 * ships in PR1 (migration `2026_10_02_100000`). The candidates
 * list (for the NeedsSelection state) is stored on a dedicated
 * `candidatos_json` column added by this PR.
 */
class EloquentEnriquecimientoRepository implements EnriquecimientoRepository
{
    public function upsert(int $entidadId, EmpresaEnriquecida $data): void
    {
        EntidadEnriquecimiento::updateOrCreate(
            ['entidad_id' => $entidadId],
            [
                'nit' => $data->nit,
                'ciiu_codigo' => $data->ciiu_codigo,
                'clase_riesgo_ul_num' => $data->clase_riesgo_num,
                'clase_riesgo_ul_desc' => $data->clase_riesgo_desc,
                'sector_economico' => $data->sector_economico,
                'fuente_enriquecimiento' => $data->fuente_origen,
                'enriquecido_at' => $data->enriquecido_at,
                'enriquecimiento_hash' => $data->enriquecimiento_hash,
                'enrichment_status' => 'enriched',
            ],
        );
    }

    public function findByEntidadId(int $entidadId): ?EntidadEnriquecimiento
    {
        /** @var EntidadEnriquecimiento|null $row */
        $row = EntidadEnriquecimiento::with('entidad')
            ->where('entidad_id', $entidadId)
            ->first();

        return $row;
    }

    public function candidatos(int $entidadId): array
    {
        $row = $this->findByEntidadId($entidadId);
        if ($row === null) {
            return [];
        }

        // PR3 stores the candidates list in a dedicated column; fall back to
        // an empty array when the column is not yet populated (back-compat
        // with PR1 rows).
        $candidatesJson = $row->getAttribute('candidatos_json');

        if ($candidatesJson === null || $candidatesJson === '') {
            return [];
        }

        $decoded = json_decode((string) $candidatesJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function needsSelection(int $entidadId): bool
    {
        $row = $this->findByEntidadId($entidadId);

        return $row !== null && $row->enrichment_status === 'needs_selection';
    }

    /**
     * PR3 helper (not part of the port): persist the candidate list
     * emitted by the `EmpresaEnriquecimientoNecesitaSeleccion` event.
     * Lives here so the homonimia branch in the service can stay
     * framework-agnostic.
     */
    public function recordNeedsSelection(int $entidadId, array $candidatos): void
    {
        $row = $this->findByEntidadId($entidadId);

        if ($row === null) {
            EntidadEnriquecimiento::create([
                'entidad_id' => $entidadId,
                'enrichment_status' => 'needs_selection',
                'candidatos_json' => json_encode($candidatos, JSON_UNESCAPED_UNICODE),
            ]);
            return;
        }

        $row->enrichment_status = 'needs_selection';
        $row->candidatos_json = json_encode($candidatos, JSON_UNESCAPED_UNICODE);
        $row->save();
    }
}