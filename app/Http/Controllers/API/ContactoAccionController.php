<?php

namespace App\Http\Controllers\API;

use App\Application\Seguimiento\Services\NotificacionRecipientsResolver;
use App\Application\UseCases\Seguimiento\StoreSeguimientoUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Seguimiento;
use App\Notifications\FollowUpNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\CRM\Models\Contacto;

class ContactoAccionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private StoreSeguimientoUseCase $storeSeguimientoUseCase,
        private NotificacionRecipientsResolver $recipientsResolver,
    ) {}

    /**
     * POST /api/v1/contacto/{contactoId}/acciones
     *
     * Registra una acción de seguimiento (llamada, correo, reunión, nota)
     * y opcionalmente programa un próximo seguimiento con fecha/hora.
     *
     * PR-H (Phase 5b - REQ-SEG-004): the seguimiento is now stamped
     * with `persona_id` (resolved from `contacto.persona_id` per the
     * PR-E additive migration + PR-F backfill). The legacy
     * `contacto_id` column on `seguimiento` was dropped by PR-G.
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

        // PR-H: resolve persona_id from the contacto row. The contacto
        // must have persona_id populated (backfill invariant, verified
        // by PR-F). If it does not, we fall back to leaving persona_id
        // NULL on the seguimiento (the FK is nullable + nullOnDelete).
        $contacto = Contacto::find($contactoId);
        if (! $contacto) {
            return $this->errorResponse('Contacto no encontrado.', 404);
        }
        $personaId = $contacto->persona_id !== null ? (int) $contacto->persona_id : null;

        $tipo = $request->input('tipo');
        $notas = $request->input('notas');
        $oportunidadId = $request->input('oportunidad_id');
        $entidadId = $request->input('entidad_id');
        $fechaProximo = $request->input('fecha');
        $horaProximo = $request->input('hora');
        $ahora = now()->toDateString();

        return DB::transaction(function () use (
            $personaId, $tipo, $notas, $oportunidadId, $entidadId,
            $fechaProximo, $horaProximo, $ahora,
        ) {
            $creados = [];

            $actual = $this->storeSeguimientoUseCase->execute([
                // PR-H: stamp persona_id (post-PR-G canonical FK)
                // instead of the legacy contacto_id.
                'persona_id' => $personaId,
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
                    'persona_id' => $personaId,
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
