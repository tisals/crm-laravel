<?php

namespace App\Domain\Events;

/**
 * Commit 7 of `tenant-data-model-correction` — `EntidadChanged` domain
 * event. Mirrors `PersonaChanged` (PR-J) so Mercurio's CQRS mirror can
 * ingest entity writes through the same shape.
 *
 * Fired by:
 *   - `StoreEntidadUseCase`   → action='created'
 *   - `UpdateEntidadUseCase`  → action='updated' (only on effective change)
 *   - `DestroyEntidadUseCase` → action='deleted' (pre-delete snapshot)
 *   - `EntidadRelacionObserver` (created/updated/deleted pivot rows)
 *     → action='updated' (because the entity's `is_active` / relaciones
 *     count just changed)
 *
 * Payload shape:
 *  - `action`: 'created' | 'updated' | 'deleted'
 *  - `entidad_id`: int (the row id; survives delete because the use case
 *    captures the pre-delete snapshot before issuing the soft-delete)
 *  - `snapshot`: receiver-facing projection — `id`, `nombre`,
 *    `nombre_comercial`, `tipo_persona`, `identificacion`, principal-row
 *    fields (`email_principal`, `telefono_principal`, `direccion_principal`,
 *    `dominio`), `is_active` (derived from the open pivot row — see
 *    `Entidad::getEstadoAttribute()`), `relaciones_count`, `contactos_count`,
 *    `oportunidades_count`, `usuarios_count`, and a `deleted_at` ISO-8601
 *    string when the entity was soft-deleted.
 *  - `occurred_at`: ISO-8601 string stamped at dispatch time.
 *  - `event_id`: UUIDv4 — Mercurio dedupes replays by this id (Commit 7
 *    adds idempotency on top of the persona event's `occurred_at` key).
 *
 * Why a final class? Same rationale as `PersonaChanged`: the wire shape is
 * a public contract with Mercurio's CQRS mirror downstream.
 */
final class EntidadChanged
{
    public function __construct(
        public string $action,
        public int $entidad_id,
        public array $snapshot,
        public string $occurred_at,
        public string $event_id,
    ) {}
}