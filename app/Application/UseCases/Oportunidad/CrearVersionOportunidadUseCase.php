<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use App\Domain\Repositories\OportunidadRepositoryInterface;
use Illuminate\Support\Facades\DB;

class CrearVersionOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $oportunidadRepository,
        private DetalleOportunidadRepositoryInterface $detalleRepository,
    ) {}

    public function execute(int $id): mixed
    {
        return DB::transaction(function () use ($id) {
            // 1. Find source model (with details)
            $sourceModel = \App\Models\Oportunidad::with('detalles')->find($id);
            if (!$sourceModel) {
                return null;
            }

            // 2. Mark previous latest version as not latest and inactive
            \App\Models\Oportunidad::where('codigo', $sourceModel->codigo)
                ->orWhere('parent_id', $sourceModel->parent_id ?? $sourceModel->id)
                ->update(['is_latest' => false, 'estado' => 'Inactiva']);

            // 3. Resolve base code and new code
            $baseCodigo = preg_replace('/-V\d+$/i', '', $sourceModel->codigo);
            $newVersionNumber = $sourceModel->version + 1;
            $newCodigo = $baseCodigo . "-V" . $newVersionNumber;

            // 4. Create new opportunity version
            $newOportunidad = \App\Models\Oportunidad::create([
                'codigo' => $newCodigo,
                'entidad_id' => $sourceModel->entidad_id,
                'contacto_id' => $sourceModel->contacto_id,
                'pipeline_id' => $sourceModel->pipeline_id,
                'pipeline_etapa_id' => $sourceModel->pipeline_etapa_id,
                'parent_id' => $sourceModel->parent_id ?? $sourceModel->id,
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

            // 5. Clone details
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
}
