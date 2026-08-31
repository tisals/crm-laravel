<?php

namespace App\Domain\Events;

/**
 * PR-J — `PersonaChanged` domain event (REQ-PSWH-001, AD-3, AD-9).
 *
 * Fired by `StorePersonaUseCase`, `UpdatePersonaUseCase`, and
 * `DestroyPersonaUseCase` AFTER the DB write commits. The
 * `App\Infrastructure\Webhook\PersonasSnapshotEmitter` listener picks it
 * up and queues the snapshot webhook job for Mercury's CQRS mirror.
 *
 * Payload shape is locked by spec REQ-PSWH-001:
 *  - `action`: 'created' | 'updated' | 'deleted'
 *  - `persona_id`: int (the row id; survives delete because the listener
 *    captures the pre-delete snapshot and emits it with `action='deleted'`)
 *  - `snapshot`: full persona state for created/updated; for deleted it
 *    carries the pre-delete shape plus a `deleted_at` ISO-8601 string so
 *    receivers can reconcile their mirror table.
 *  - `occurred_at`: ISO-8601 string, stamped at dispatch time. Receivers
 *    SHOULD dedupe by `(persona_id, occurred_at)`.
 *
 * Why a final class? The event shape is a public contract — Mercury's
 * mirror downstream depends on the exact field names. Sealing the class
 * prevents "helpful" subclasses from silently changing the wire shape.
 */
final class PersonaChanged
{
    public function __construct(
        public string $action,
        public int $persona_id,
        public array $snapshot,
        public string $occurred_at,
    ) {}
}
