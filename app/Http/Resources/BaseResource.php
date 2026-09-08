<?php

namespace App\Http\Resources;

use App\Enums\ProjectionLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base class for API resources that participate in the CQRS-Lite
 * depth-projection scheme.
 *
 * Subclasses read `$request->query('depth')` via `depth($request)`
 * and gate their relation-block sections on `whenDepthAtLeast()`.
 * The contract is:
 *
 *   - depth=1 (Shallow): bare entity identity, no side queries
 *   - depth=2 (Default): direct first-degree relations (canonical detail)
 *   - depth=3 (Deep):    + second-degree relations (snapshot / mirror)
 *
 * Unknown or missing depth values resolve to `ProjectionLevel::Default`.
 * See `App\Enums\ProjectionLevel` for the resolution rules.
 *
 * The class is intentionally non-abstract so `new BaseResource($entity)`
 * still works as a generic envelope pass-through (see
 * `tests/Unit/Http/Resources/BaseResourceTest.php`). Subclasses that want
 * to project relations MUST override `toArray()` and read the depth via
 * `$this->depth($request)`.
 */
class BaseResource extends JsonResource
{
    /**
     * Cached projection level per-instance. JsonResource calls
     * `toArray($request)` exactly once per response, so the cache lives
     * just long enough for `whenDepthAtLeast()` to dedupe.
     */
    protected ?ProjectionLevel $cachedLevel = null;

    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }

    /**
     * Resolve the projection level for the current request.
     */
    public function depth(Request $request): ProjectionLevel
    {
        return $this->cachedLevel ??= ProjectionLevel::fromRequest($request);
    }

    /**
     * Run `$fn` only when the current projection depth meets or exceeds
     * `$minLevel`. Otherwise return `$default`.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @param  T  $default
     * @return T
     */
    protected function whenDepthAtLeast(Request $request, ProjectionLevel $minLevel, callable $fn, mixed $default = null): mixed
    {
        return $this->depth($request)->atLeast($minLevel) ? $fn() : $default;
    }

/**
 * Inverse of `whenDepthAtLeast`: run `$fn` ONLY for shallow projection.
 * Useful for "bare identity" blocks where you want to force a minimal
 * shape even if the resource has relations eager-loaded.
 *
 * @template T
 *
 * @param  callable():T  $fn
 * @param  T  $default
 * @return T
 */
protected function whenShallow(Request $request, callable $fn, mixed $default = null): mixed
{
    return $this->depth($request) === ProjectionLevel::Shallow ? $fn() : $default;
}

/**
 * Defensive proxy for Eloquent's `relationLoaded()`. Returns false when
 * the wrapped resource is a domain entity (no relation tracking) so
 * callers don't need to special-case the type. The `JsonResource`
 * magic-call proxy already forwards `relationLoaded()` to the wrapped
 * object when it IS a Model, but a bare entity throws BadMethodCall.
 */
protected function isRelationLoaded(string $relation): bool
{
    $r = $this->resource;

    return method_exists($r, 'relationLoaded') && $r->relationLoaded($relation);
}

/**
 * Defensive getter for an attribute or dynamic property on the wrapped
 * resource. Returns null when the wrapped object has neither the
 * declared property nor an `getAttribute()` method (the case for a
 * domain entity vs. an Eloquent Model). Used by resources that need to
 * read fields that live on one type but not the other — e.g. Entidad
 * resource reading `cantidad_empleados` (Model) vs. `contactos_count`
 * (entity) without emitting PHP 8.2 dynamic-property warnings.
 */
protected function prop(string $name): mixed
{
    $r = $this->resource;

    if (method_exists($r, 'getAttribute')) {
        return $r->getAttribute($name);
    }

    return property_exists($r, $name) ? ($r->{$name} ?? null) : null;
}
}