<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Oportunidad\ShowOportunidadUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Models\Oportunidad;
use App\Models\DetalleOportunidad;
use App\Models\Seguimiento;
use App\Mail\CotizacionMail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class CotizacionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ShowOportunidadUseCase $showUseCase,
    ) {}

    /**
     * GET /api/v1/oportunidades/{id}/pdf
     * Genera y descarga el PDF de la cotización.
     */
    public function pdf(int $id)
    {
        $oportunidad = Oportunidad::with(['entidad', 'contacto', 'detalles.producto'])
            ->findOrFail($id);

        $data = $this->buildPdfData($oportunidad);

        $pdf = Pdf::loadView('pdf.cotizacion', $data);
        $pdf->setPaper('letter');

        return $pdf->download("cotizacion-{$oportunidad->codigo}.pdf");
    }

    /**
     * POST /api/v1/oportunidades/{id}/aprobar
     * Marca como Aceptada + genera PDF preview.
     */
    public function aprobar(int $id): JsonResponse
    {
        $oportunidad = Oportunidad::with(['entidad', 'detalles'])
            ->findOrFail($id);

        if ($oportunidad->estado !== 'Enviada') {
            return $this->errorResponse('Solo cotizaciones enviadas pueden ser aprobadas.', 422);
        }

        $oportunidad->update(['estado' => 'Aceptada']);

        return $this->successResponse(
            $this->buildPdfData($oportunidad),
            200,
            'Cotización aprobada exitosamente.',
        );
    }

    /**
     * POST /api/v1/oportunidades/{id}/enviar
     * Marca como Enviada y envía email con PDF al contacto asociado.
     */
    public function enviar(int $id): JsonResponse
    {
        $oportunidad = Oportunidad::with(['entidad', 'contacto', 'detalles'])
            ->findOrFail($id);

        // State guard: only Borrador can be sent
        if ($oportunidad->estado !== 'Borrador') {
            return $this->errorResponse(
                'Solo oportunidades en estado Borrador pueden ser enviadas.',
                422
            );
        }

        // Guard: necesita líneas de detalle
        if ($oportunidad->detalles->isEmpty()) {
            return $this->errorResponse(
                'La cotización debe tener al menos una línea de detalle.',
                422
            );
        }

        // Guard: necesita contacto con email
        $contacto = $oportunidad->contacto;
        if (!$contacto || !$contacto->email_contacto) {
            return $this->errorResponse(
                'La oportunidad debe tener un contacto con email.',
                422
            );
        }

        // Marcar como Enviada
        $oportunidad->update(['estado' => 'Enviada']);

        // Enviar email (queueado)
        Mail::to($contacto->email_contacto)
            ->queue(new CotizacionMail($oportunidad));

        // Auto-seguimiento
        $seguimiento = Seguimiento::create([
            'oportunidad_id' => $oportunidad->id,
            'contacto_id' => $contacto->id,
            'entidad_id' => $oportunidad->entidad_id,
            'tipo' => 'Correo',
            'fecha' => now()->toDateString(),
            'hora' => now()->format('H:i'),
            'notas' => "Cotización {$oportunidad->codigo} enviada a {$contacto->email_contacto}",
            'estado' => 'Completado',
            'autor_id' => auth()->id(),
        ]);

        return $this->successResponse([
            'id' => $oportunidad->id,
            'estado' => 'Enviada',
            'seguimiento_id' => $seguimiento->id,
        ], 200, 'Cotización enviada exitosamente.');
    }

    /**
     * GET /api/v1/oportunidades/{id}/cotizacion-data
     * Datos para mostrar la cotización en el frontend.
     */
    public function data(int $id): JsonResponse
    {
        $oportunidad = Oportunidad::with(['entidad', 'contacto', 'detalles.producto'])
            ->findOrFail($id);

        return $this->successResponse($this->buildPdfData($oportunidad));
    }

    private function buildPdfData(Oportunidad $oportunidad): array
    {
        $detalles = $oportunidad->detalles->map(fn ($d) => [
            'name' => $d->producto?->nombre ?? $d->concepto ?? 'Servicio',
            'unidad' => $d->medida ?? 'Und',
            'qty' => number_format((float) $d->cantidad, 2),
            'unit_value' => '$' . number_format((float) $d->vr_unitario, 0),
            'total' => '$' . number_format((float) $d->vr_total, 0),
            'iva' => (float) $d->iva,
        ]);

        $subtotal = $oportunidad->detalles->sum('vr_total');
        $iva = $oportunidad->detalles->sum('iva');
        $total = $subtotal + $iva;

        return [
            'cotizacion_no' => $oportunidad->codigo,
            'opportunity' => [
                'id' => $oportunidad->codigo,
                'version' => '1',
                'sent_at' => $oportunidad->created_at?->format('d/m/Y') ?? now()->format('d/m/Y'),
                'due_date' => $oportunidad->validez_oferta ?? 30,
                'observations' => $oportunidad->observaciones ?? '',
                'aclarations' => $oportunidad->aclaraciones ?? '',
                'payment_conditions' => $oportunidad->forma_pago ?? '',
                'guarantees' => $oportunidad->garantia ?? '',
                'delivery_time' => $oportunidad->tiempo_entrega ?? '',
            ],
            'brand' => [
                'logo' => '',
                'slogan' => 'Soluciones en Seguridad y Salud en el Trabajo',
                'name' => 'deseguridad.net',
                'business_sign' => 'Tecnoinnsoft SAS · NIT 901.234.567-0',
            ],
            'entity' => [
                'name' => $oportunidad->entidad?->nombre ?? '—',
                'city' => $oportunidad->entidad?->dominio ?? '—',
            ],
            'contact' => [
                'user' => [
                    'name' => $oportunidad->contacto?->nombres ?? '—',
                    'email' => $oportunidad->contacto?->email_contacto ?? '—',
                ],
            ],
            'detalle_oportunidad' => $detalles,
            'subtotal' => '$' . number_format($subtotal, 0),
            'iva' => '$' . number_format($iva, 0),
            'total_general' => '$' . number_format($total, 0),
        ];
    }
}
