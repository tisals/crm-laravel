<?php

namespace App\Enums;

use Illuminate\Http\Request;

/**
 * CQRS-Lite depth projection for API responses.
 *
 * Lets the API consumer choose how much relation graph is embedded in the
 * response payload, controlled via the `?depth=` query param:
 *
 *   - Shallow (depth=1): bare entity. No eager-loaded relations, no
 *     per-resource side queries. Cheapest, intended for list endpoints
 *     (e.g. dropdowns, infinite scroll pages).
 *   - Default (depth=2): entity + direct first-degree relations. The
 *     canonical detail-endpoint shape.
 *   - Deep    (depth=3): entity + direct + second-degree relations.
 *     Reserved for webhook snapshots and the Mercury mirror; includes
 *     nested collections (e.g. oportunidad.detalles.producto).
 *
 * Unrecognised or absent query values clamp to Default (depth=2) — the
 * projection MUST never return a 4xx because the caller asked for the
 * wrong depth. The whole point of the depth parameter is to be a hint,
 * not a contract.
 */
enum ProjectionLevel: int
{
    case Shallow = 1;
    case Default = 2;
    case Deep = 3;

    /**
     * Resolve the projection level from an inbound HTTP request.
     *
     * Reads `$request->query('depth')`. Any non-integer, out-of-range, or
     * missing value clamps to `Default`. Negative numbers also clamp to
     * `Default` (a `0` would otherwise render zero relations at all, which
     * is not a supported mode).
     */
    public static function fromRequest(?Request $request): self
    {
        if ($request === null) {
            return self::Default;
        }

        $raw = $request->query('depth');

        if ($raw === null || $raw === '') {
            return self::Default;
        }

        // Accept "1", 1, "3" — but reject "1.5", "abc", "1; DROP TABLE".
        // filter_var with the integer flag is the standard sanitizer.
        $value = filter_var($raw, FILTER_VALIDATE_INT);

        if ($value === false || $value < 1 || $value > 3) {
            return self::Default;
        }

        return self::from($value);
    }

    /**
     * Numeric rank for comparison. Higher = more depth.
     */
    public function rank(): int
    {
        return $this->value;
    }

    /**
     * True when this projection is at least as deep as the given level.
     */
    public function atLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }
}