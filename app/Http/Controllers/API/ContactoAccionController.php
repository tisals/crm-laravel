<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Seguimiento\StoreSeguimientoUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\SeguimientoRequest;
use App\Models\Seguimiento;
use App\Models\Usuario;
use App\Notifications\FollowUpNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;

class ContactoAccionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private StoreSeguimientoUseCase $storeSeguimientoUseCase,
    ) {}

    /**
     * POST /api/v1/contactos/{contactoId}/acciones
     *
     * Registra una acción de seguimiento (llamada, correo, reunión, nota)
     * y opcionalmente programa un próximo seguimiento con fecha/hora.
     *
     * Body:
     *   tipo           string  (Llamada|Correo|Reunion|Otro) — requerida
     *   notas          string  — requerida
     *   oportunidad_id int     — opcional
     *   entidad_id     int     — opcional
     *   fecha          date    — opcional (para crear próximo seguimiento)
     *   hora           time    — opcional
     *   estado         string  — opcional (default: Completado para acción actual,
     *                              Pendiente para próximo seguimiento)
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
        $fechaProximo = $request->input('fecha');       // puede ser null
        $horaProximo = $request->input('hora');         // puede ser null
        $ahora = now()->toDateString();

        // Si hay fecha_proximo → crear dos seguimientos:
        //   1. El actual (completado)
        //   2. El próximo (pendiente, con fecha/hora)
        // Si no hay fecha → crear solo el actual

        return DB::transaction(function () use (
            $contactoId, $tipo, $notas, $oportunidadId, $entidadId,
            $fechaProximo, $horaProximo, $ahora,
        ) {
            $creados = [];

            // ── Seguimiento actual (siempre se crea) ────────────────────
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

            // ── Próximo seguimiento (si se proporcionó fecha) ───────────
            if ($fechaProximo) {
                $proximo = $this->storeSeguimientoUseCase->execute([
                    'contacto_id' => $contactoId,
                    'oportunidad_id' => $oportunidadId,
                    'entidad_id' => $entidadId,
                    'tipo' => $tipo, // lleva el mismo tipo como recordatorio
                    'notas' => $notas,
                    'fecha' => $fechaProximo,
                    'hora' => $horaProximo,
                    'estado' => 'Pendiente',
                ]);

                $creados[] = $proximo;

                // Programar notificación para la fecha/hora del próximo seguimiento
                $this->scheduleFollowUpNotification($proximo);
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
     * Si fechahora es en el futuro → ScheduleNotification ( Laravel Queue )
     * Si ya pasó → se envía inmediatamente (para entornos sin queue worker)
     */
    private function scheduleFollowUpNotification(Seguimiento $seguimiento): void
    {
        $fecha = $seguimiento->fecha;
        $hora = $seguimiento->hora ?? '09:00';
        $scheduledAt = \Carbon\Carbon::parse("{$fecha} {$hora}");

        if ($scheduledAt->isPast()) {
            // Ya venció → notificar de inmediato
            $this->sendToUser($seguimiento);
            return;
        }

        // Programar notificación para el momento exacto
        Notification::send(
            Usuario::admins()->get(),
            new FollowUpNotification($seguimiento),
        );
    }

    /**
     * Envía notificación a todos los admins (puede ajustarse al autor o entidad)
     */
    private function sendToUser(Seguimiento $seguimiento): void
    {
        Notification::send(
            Usuario::admins()->get(),
            new FollowUpNotification($seguimiento),
        );
    }
}
