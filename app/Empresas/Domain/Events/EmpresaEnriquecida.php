<?php

namespace App\Empresas\Domain\Events;

use Illuminate\Support\Str;

/**
 * PR3 of `complementar-entidad` — entity-empresa-enrichment D-event.
 *
 * "Happy path" event emitted by the application service after a
 * successful MCP lookup + Habeas filter + Decreto resolution +
 * persistence into `entidad_enriquecimiento`.
 *
 * Wire contract (design §11 / spec R-Event):
 *   - `event_id`: UUIDv4 (lets listeners dedup replays)
 *   - `entidad_id`: int
 *   - `fuente_origen`: string in {socrata|rues|manual}
 *   - `enriquecido_at`: ISO 8601 string
 *   - `enriquecimiento_hash`: 64-char lowercase hex (SHA-256)
 *
 * The class is `final` (sealed) and all props are `readonly public`
 * (Laravel can dispatch + serialise it across the queue boundary
 * without defensive copies).
 */
final class EmpresaEnriquecida
{
    private const VALID_FUENTES = ['socrata', 'rues', 'manual'];
    private const UUID_V4_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public readonly string $event_id;

    public function __construct(
        public readonly int $entidad_id,
        public readonly string $fuente_origen,
        public readonly string $enriquecido_at,
        public readonly string $enriquecimiento_hash,
        ?string $eventId = null,
    ) {
        if (!in_array($fuente_origen, self::VALID_FUENTES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'fuente_origen must be one of [%s]; got "%s".',
                implode(', ', self::VALID_FUENTES),
                $fuente_origen
            ));
        }

        if (!preg_match('/^[0-9a-f]{64}$/', $enriquecimiento_hash)) {
            throw new \InvalidArgumentException('enriquecimiento_hash must be a 64-char lowercase hex SHA-256.');
        }

        $resolvedId = $eventId ?? (string) Str::uuid();

        if (!preg_match(self::UUID_V4_REGEX, $resolvedId)) {
            throw new \InvalidArgumentException(sprintf(
                'event_id must be a UUIDv4; got "%s".',
                $resolvedId
            ));
        }

        $this->event_id = $resolvedId;
    }
}