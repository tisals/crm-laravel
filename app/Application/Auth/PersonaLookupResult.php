<?php

namespace App\Application\Auth;

/**
 * Discriminated result for `ShowPersonaUseCase::execute`.
 *
 * Three states are needed to fully describe the outcome of a tenant-aware
 * lookup (REQ-PRAPI-003, OI-4):
 *
 *   - FOUND:     the persona exists and is visible to the requester
 *   - NOT_FOUND: no such persona (controller maps to HTTP 404)
 *   - FORBIDDEN: the persona exists, but belongs to a different entidad
 *                and the requester is NOT an admin (controller maps to 403)
 *
 * Returning a value object instead of `null|Throwable|Entity` keeps the
 * application layer free of HTTP concerns; the controller is the only
 * layer that knows how to translate the state into a status code.
 */
final class PersonaLookupResult
{
    public const STATUS_FOUND = 'found';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_FORBIDDEN = 'forbidden';

    /**
     * @param  string  $status  one of the STATUS_* constants
     * @param  mixed  $persona  Persona domain entity (only when status=FOUND)
     */
    private function __construct(
        public readonly string $status,
        public readonly mixed $persona = null,
    ) {}

    public static function found(mixed $persona): self
    {
        return new self(self::STATUS_FOUND, $persona);
    }

    public static function notFound(): self
    {
        return new self(self::STATUS_NOT_FOUND);
    }

    public static function forbidden(): self
    {
        return new self(self::STATUS_FORBIDDEN);
    }

    public function isFound(): bool
    {
        return $this->status === self::STATUS_FOUND;
    }
}
