<?php

namespace App\Http\Controllers\API;

use App\Application\Seguimiento\Services\NotificacionRecipientsResolver;
use App\Application\UseCases\Seguimiento\StoreSeguimientoUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Seguimiento;
use App\Notifications\FollowUpNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\CRM\Models\Seguimiento as CanonicalSeguimiento;

class ContactoAccionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private StoreSeguimientoUseCase $storeSeguimientoUseCase,
        private NotificacionRecipientsResolver $recipientsResolver,
    ) {}

    /**
     * POST /api/v1/contactos/{contactoId}/acciones
     *
     * Registra una acción de seguimiento (llamada, correo, reunión, nota)
     * y opcionalmente programa un próximo seguimiento con fecha/hora.
     */
    public function acciones(int $contactoId, Request $request): JsonResponse
    {
        $request->validate([
            'tipo' => 'required|string|in:Llamada,Correo,Reunion,Nota,Otro',
            'notas' => 'required|string',
            'oportunidad_id' => 'nullable|integer|exists:oportunidad,id',
            'entidad_id' => 'nullable|integer|exists:entidad,id',
            'fecha' => 'nullable|date',
            'hora' => 'nullable|date_format:H:i',
            'estado' => 'nullable|string|in:Pendiente,Completado,Cancelado',
        ], [
            'tipo.in' => 'Tipo debe ser Llamada, Correo, Reunion, Nota u Otro.',
            'fecha.date' => 'La fecha debe ser una fecha válida.',
            'hora.date_format' => 'La hora debe tener formato HH:MM.',
        ]);

        $tipo = $request->input('tipo');
        $notas = $request->input('notas');
        $oportunidadId = $request->input('oportunidad_id');
        $entidadId = $request->input('entidad_id');
        $fechaProximo = $request->input('fecha');
        $horaProximo = $request->input('hora');
        $ahora = now()->toDateString();

        return DB::transaction(function () use (
            $contactoId, $tipo, $notas, $oportunidadId, $entidadId,
            $fechaProximo, $horaProximo, $ahora,
        ) {
            $creados = [];

            $actual = $this->storeSeguimientoUseCase->execute([
                'contacto_id' => $contactoId,
                'oportunidad_id' => $oportunidadId,
                'entidad_id' => $entidadId,
                'tipo' => $tipo,
                'notas' => $notas,
                'fecha' => $ahora,
                'hora' => now()->format('H:i'),
                'estado' => 'Completado',
            ]);

            $creados[] = $actual;

            if ($fechaProximo) {
                $proximo = $this->storeSeguimientoUseCase->execute([
                    'contacto_id' => $contactoId,
                    'oportunidad_id' => $oportunidadId,
                    'entidad_id' => $entidadId,
                    'tipo' => $tipo,
                    'notas' => $notas,
                    'fecha' => $fechaProximo,
                    'hora' => $horaProximo,
                    'estado' => 'Pendiente',
                ]);

                $creados[] = $proximo;

                $proximoModel = Seguimiento::findOrFail($proximo->id);
                $this->scheduleFollowUpNotification($proximoModel);
            }

            $mensaje = $fechaProximo
                ? 'Acción registrada y próximo seguimiento programado.'
                : 'Acción registrada exitosamente.';

            return $this->successResponse(
                ['seguimientos' => $creados],
                201,
                $mensaje,
            );
        });
    }

    /**
     * Programa la notificación para un seguimiento pendiente futuro.
     * Si fechahora es en el futuro → ScheduleNotification (Laravel Queue).
     * Si ya pasó → se envía inmediatamente (para entornos sin queue worker).
     */
    private function scheduleFollowUpNotification(Seguimiento $seguimiento): void
    {
        $recipients = $this->recipientsResolver->resolve($seguimiento);

        if ($recipients->isEmpty()) {
            Log::warning("FollowUpNotification for seguimiento {$seguimiento->id}: no recipients found (no comercial mapped, no admins)");
            return;
        }

        Notification::send($recipients, new FollowUpNotification($seguimiento));
    }
}
