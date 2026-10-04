<?php

namespace App\Empresas\Domain\Events;

use Illuminate\Support\Str;

/**
 * PR4 of `complementar-entidad` — entity-empresa-enrichment D-event.
 *
 * "Failure path" event emitted by the queue worker after the
 * `EnriquecerEmpresaJob` exhausts its retries (or hits a terminal
 * `McpServerUnavailable`). Mirrors the wire contract of
 * `EmpresaEnriquecida` (UUIDv4 `event_id`, readonly properties).
 *
 * Wire contract (design §11):
 *   - `event_id`:   UUIDv4 (lets listeners dedup replays)
 *   - `entidad_id`: int
 *   - `motivo`:     string (trimmed; non-empty)
 *   - `attempts`:   int >= 1
 *
 * @see ${SPEC}/specs/entity-empresa-enrichment/spec.md
 */
final class EmpresaEnriquecimientoFailed
{
    private const UUID_V4_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public readonly string $event_id;

    public readonly string $motivo;

    public function __construct(
        public readonly int $entidad_id,
        string $motivo,
        public readonly int $attempts,
        ?string $eventId = null,
    ) {
        $trimmedMotivo = trim($motivo);

        if ($trimmedMotivo === '') {
            throw new \InvalidArgumentException('motivo must be a non-empty string.');
        }

        if ($attempts < 1) {
            throw new \InvalidArgumentException(sprintf(
                'attempts must be >= 1; got %d.',
                $attempts
            ));
        }

        $resolvedId = $eventId ?? (string) Str::uuid();

        if (!preg_match(self::UUID_V4_REGEX, $resolvedId)) {
            throw new \InvalidArgumentException(sprintf(
                'event_id must be a UUIDv4; got "%s".',
                $resolvedId
            ));
        }

        $this->event_id = $resolvedId;
        $this->motivo = $trimmedMotivo;
    }
}