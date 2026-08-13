<?php

namespace App\Application\Enums;

/**
 * Outcome of attempting to delete a DetalleOportunidad.
 *
 * Three distinct semantic states — the controller uses this to map to
 * precise HTTP status codes (200 / 404 / 422) per spec scenarios:
 *   - Deleted   → 200, soft-deleted successfully
 *   - NotFound  → 404, the id does not exist (or was already soft-deleted)
 *   - FkBlocked → 422, the row exists but a FK constraint blocks deletion
 */
enum DeleteOutcome: string
{
    case Deleted = 'deleted';
    case NotFound = 'not_found';
    case FkBlocked = 'fk_blocked';

    /**
     * Human-readable reason for FK-blocked deletions. For other cases,
     * the controller synthesizes its own message.
     */
    public function reason(string $driverMessage = ''): string
    {
        return match ($this) {
            self::Deleted => 'Eliminado correctamente.',
            self::NotFound => 'Detalle no encontrado.',
            self::FkBlocked => $driverMessage !== ''
                ? 'No se puede eliminar el detalle: '.$driverMessage
                : 'No se puede eliminar el detalle: restricción de integridad.',
        };
    }
}
