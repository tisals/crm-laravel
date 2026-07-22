<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use App\Domain\Repositories\OportunidadRepositoryInterface;
use App\Models\Oportunidad;
use Illuminate\Support\Facades\DB;

class CrearVersionOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $oportunidadRepository,
        private DetalleOportunidadRepositoryInterface $detalleRepository,
    ) {}

    /**
     * Convention used here (the CSV / legacy convention, since 2834+ rows
     * already follow it in production):
     *
     *   - Code suffix is space-separated and lowercase:  "GC-... v2"
     *   - The base row (no suffix) has version=0
     *   - The latest version has parent_id = NULL and is_latest = 1
     *   - Every older version in the same family has parent_id = <latest_id>,
     *     is_latest = 0, estado = 'Inactiva'
     *
     * The previous interactive endpoint used the opposite convention
     * (hyphen suffix, parent on the newest, version starting at 1). That
     * contradiction is what made `delete latest version` hide the whole
     * family and broke the history view in the frontend.
     */
    public function execute(int $id): mixed
    {
        return DB::transaction(function () use ($id) {
            // 1. Find source model (with details)
            $sourceModel = Oportunidad::with('detalles')->find($id);
            if (! $sourceModel) {
                return null;
            }

            // 2. Family is identified by the base codigo (strip any " vN" suffix)
            $baseCodigo = self::stripVersionSuffix($sourceModel->codigo);

            // 3. Resolve next version number = max(version) in the family + 1
            $maxVersion = (int) Oportunidad::where(function ($q) use ($baseCodigo) {
                $q->where('codigo', $baseCodigo)
                    ->orWhere('codigo', 'like', $baseCodigo.' v%');
            })->max('version');
            $newVersionNumber = $maxVersion + 1;
            $newCodigo = $baseCodigo.' v'.$newVersionNumber;

            // 4. Mark EVERY other version in the family (including the source
            //    row) as not-latest + Inactiva. The new row created in step 5
            //    will take over as the latest.
            Oportunidad::where(function ($q) use ($baseCodigo) {
                $q->where('codigo', $baseCodigo)
                    ->orWhere('codigo', 'like', $baseCodigo.' v%');
            })->update(['is_latest' => 0, 'estado' => 'Inactiva']);

            // 5. Create the new latest version (parent_id NULL because SHE is the latest)
            $newOportunidad = Oportunidad::create([
                'codigo' => $newCodigo,
                'entidad_id' => $sourceModel->entidad_id,
                'contacto_id' => $sourceModel->contacto_id,
                'pipeline_id' => $sourceModel->pipeline_id,
                'pipeline_etapa_id' => $sourceModel->pipeline_etapa_id,
                'parent_id' => null,
                'version' => $newVersionNumber,
                'is_latest' => true,
                'fecha' => date('Y-m-d'),
                'estado' => 'Activa',
                'fuente_canal' => $sourceModel->fuente_canal,
                'observaciones' => $sourceModel->observaciones,
                'aclaraciones' => $sourceModel->aclaraciones,
                'validez_oferta' => $sourceModel->validez_oferta,
                'tiempo_entrega' => $sourceModel->tiempo_entrega,
                'forma_pago' => $sourceModel->forma_pago,
                'garantia' => $sourceModel->garantia,
                'linea_negocio' => $sourceModel->linea_negocio,
                'created_by' => auth()->id() ?? $sourceModel->created_by,
            ]);

            // 6. Rewire parent_id of every other family row to point at the new latest
            Oportunidad::where(function ($q) use ($baseCodigo) {
                $q->where('codigo', $baseCodigo)
                    ->orWhere('codigo', 'like', $baseCodigo.' v%');
            })->where('id', '!=', $newOportunidad->id)
                ->update(['parent_id' => $newOportunidad->id]);

            // 7. Clone detalles
            foreach ($sourceModel->detalles as $detalle) {
                $newOportunidad->detalles()->create([
                    'producto_id' => $detalle->producto_id,
                    'concepto' => $detalle->concepto,
                    'medida' => $detalle->medida,
                    'cantidad' => $detalle->cantidad,
                    'vr_unitario' => $detalle->vr_unitario,
                    'iva' => $detalle->iva,
                    'vr_total' => $detalle->vr_total,
                    'descripcion' => $detalle->descripcion,
                ]);
            }

            return $newOportunidad;
        });
    }

    /**
     * Strip any " vN" / "-VN" suffix and return the base codigo.
     * Centralised here so Destroy and any future call site stay aligned.
     */
    public static function stripVersionSuffix(string $codigo): string
    {
        return trim(preg_replace('/[\s\-]+[vV]\d+$/', '', $codigo) ?? $codigo);
    }
}
