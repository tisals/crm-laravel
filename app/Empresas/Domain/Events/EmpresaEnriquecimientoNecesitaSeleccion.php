<?php

namespace App\Empresas\Domain\Events;

use Illuminate\Support\Str;

/**
 * PR3 of `complementar-entidad` — entity-empresa-enrichment D-event.
 *
 * "Homonimia" event emitted by the application service when the MCP
 * `buscarPorDominio` call returns 2+ candidates. NO annex row is
 * persisted in this branch; the event signals downstream layers to
 * surface a selection UI to the user.
 *
 * Wire contract (design §11):
 *   - `event_id`: UUIDv4
 *   - `entidad_id`: int
 *   - `candidates_count`: int (>= 2)
 *   - `candidatos`: list<array> — full EmpresaCandidato payloads
 *
 * The class is `final` (sealed) and all props are `readonly public`.
 */
final class EmpresaEnriquecimientoNecesitaSeleccion
{
    private const UUID_V4_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public readonly string $event_id;

    /**
     * @param int                       $entidad_id
     * @param int                       $candidates_count  must be >= 2 and equal to count($candidatos)
     * @param array<int,array<string,mixed>> $candidatos   list of EmpresaCandidato::fromArray() shapes
     * @param string|null               $eventId       UUIDv4 — auto-generated when null
     */
    public function __construct(
        public readonly int $entidad_id,
        public readonly int $candidates_count,
        public readonly array $candidatos,
        ?string $eventId = null,
    ) {
        if ($candidates_count < 2) {
            throw new \InvalidArgumentException(
                'candidates_count must be >= 2 for EmpresaEnriquecimientoNecesitaSeleccion.'
            );
        }

        if (empty($candidatos)) {
            throw new \InvalidArgumentException('candidatos must not be empty.');
        }

        if (count($candidatos) !== $candidates_count) {
            throw new \InvalidArgumentException(sprintf(
                'candidates_count (%d) must equal count($candidatos) (%d).',
                $candidates_count,
                count($candidatos)
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
    }
}