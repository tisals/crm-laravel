<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Seguimiento\IndexSeguimientoUseCase;
use App\Application\UseCases\Seguimiento\ShowSeguimientoUseCase;
use App\Application\UseCases\Seguimiento\StoreSeguimientoUseCase;
use App\Application\UseCases\Seguimiento\UpdateSeguimientoUseCase;
use App\Application\UseCases\Seguimiento\DestroySeguimientoUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\SeguimientoRequest;
use App\Models\Seguimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class SeguimientoController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexSeguimientoUseCase $indexUseCase,
        private ShowSeguimientoUseCase $showUseCase,
        private StoreSeguimientoUseCase $storeUseCase,
        private UpdateSeguimientoUseCase $updateUseCase,
        private DestroySeguimientoUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $filters = $request->only([
            'oportunidad_id', 'contacto_id', 'entidad_id',
            'tipo', 'estado', 'fecha_desde', 'fecha_hasta',
        ]);

        $result = $this->indexUseCase->execute($perPage, null, $filters);

        return $this->successResponse($result);
    }

    public function store(SeguimientoRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Seguimiento creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Seguimiento no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(SeguimientoRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Seguimiento no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Seguimiento actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Seguimiento no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Seguimiento eliminado exitosamente.');
    }

    /**
     * GET /api/v1/seguimientos/{id}/ics
     * Exporta UN seguimiento pendiente como archivo .ics para Outlook/Google Calendar.
     */
    public function exportIcs(int $id): Response
    {
        $seguimiento = Seguimiento::with(['contacto', 'oportunidad', 'autor'])
            ->find($id);

        if (!$seguimiento) {
            return response()->json(['success' => false, 'error' => 'No encontrado.'], 404);
        }

        $ics = $this->buildIcsContent($seguimiento);

        $filename = sprintf(
            'seguimiento-%s-%s.ics',
            $seguimiento->id,
            now()->format('Ymd')
        );

        return Response::make($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * GET /api/v1/seguimientos/calendar.ics
     * Exporta TODOS los seguimientos Pendientes como un archivo .ics mensual.
     * Params: mes (YYYY-MM), contacto_id (opcional)
     */
    public function exportCalendarIcs(Request $request): Response
    {
        $mes = $request->input('mes', now()->format('Y-m'));
        [$year, $month] = explode('-', $mes);
        $startOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();

        $query = Seguimiento::with(['contacto', 'oportunidad', 'autor'])
            ->where('estado', 'Pendiente')
            ->whereBetween('fecha', [$startOfMonth->toDateString(), $endOfMonth->toDateString()]);

        if ($request->filled('contacto_id')) {
            $query->where('contacto_id', $request->input('contacto_id'));
        }

        $seguimientos = $query->orderBy('fecha')->orderBy('hora')->get();

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//CRM Tecnoinnsoft//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:CRM Tecnoinnsoft - Seguimientos',
        ];

        foreach ($seguimientos as $seg) {
            $lines[] = $this->buildIcsBlock($seg);
        }

        $lines[] = 'END:VCALENDAR';

        $ics = implode("\r\n", $lines);
        $filename = "seguimientos-{$mes}.ics";

        return Response::make($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function buildIcsContent(Seguimiento $seg): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//CRM Tecnoinnsoft//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:CRM Tecnoinnsoft',
        ];

        $lines[] = $this->buildIcsBlock($seg);
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    private function buildIcsBlock(Seguimiento $seg): string
    {
        $uid = "seguimiento-{$seg->id}@tecnoinnsoft.dev";
        $dtStart = $this->formatIcsDate($seg->fecha, $seg->hora);

        // Duration: 30 min default if only date
        $dtEnd = $seg->fecha_fin
            ? $this->formatIcsDate($seg->fecha_fin, null)
            : $this->addMinutes($dtStart, 30);

        $summary = "[{$seg->tipo}] Seguimiento"
            . ($seg->oportunidad?->codigo ? " - {$seg->oportunidad->codigo}" : '');

        $description = $seg->notas ?? '';

        if ($seg->contacto) {
            $description .= "\nContacto: {$seg->contacto->nombres} {$seg->contacto->apellidos}";
        }
        if ($seg->autor) {
            $description .= "\nAsignado por: {$seg->autor->nombre}";
        }

        $description = str_replace(["\r\n", "\n", "\r"], "\\n", $description);

        $createdAt = $seg->created_at
            ? \Carbon\Carbon::parse($seg->created_at)->format('Ymd\THis\Z')
            : now()->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTAMP:{$createdAt}",
            "DTSTART:{$dtStart}",
            "DTEND:{$dtEnd}",
            "SUMMARY:{$summary}",
            "DESCRIPTION:{$description}",
            'STATUS:CONFIRMED',
            'END:VEVENT',
        ];

        return implode("\r\n", $lines);
    }

    private function formatIcsDate(string $date, ?string $time): string
    {
        $base = \Carbon\Carbon::parse($date)->format('Ymd');
        if (!$time) {
            return "{$base}T090000"; // default 9am
        }
        // Strip seconds if present (H:i:s → H:i)
        $time = substr($time, 0, 5);
        return "{$base}T" . str_replace(':', '', $time) . '00';
    }

    private function addMinutes(string $icsDateTime, int $minutes): string
    {
        $dt = \Carbon\Carbon::createFromFormat('Ymd\THis', $icsDateTime)->addMinutes($minutes);
        return $dt->format('Ymd\THis');
    }
}
