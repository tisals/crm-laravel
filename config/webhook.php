<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for sending webhook events to external services (FastAPI).
    |
    */

    'outbound' => [

        /*
        |--------------------------------------------------------------------------
        | Webhook URL
        |--------------------------------------------------------------------------
        |
        | The URL to send outbound webhook requests to.
        |
        */

        'url' => env('WEBHOOK_OUTBOUND_URL', 'http://localhost:8000/api/webhook/crm'),

        /*
        |--------------------------------------------------------------------------
        | HMAC Secret
        |--------------------------------------------------------------------------
        |
        | The secret key used to sign outbound webhook payloads with HMAC-SHA256.
        |
        */

        'secret' => env('WEBHOOK_OUTBOUND_SECRET', 'your-webhook-secret-key'),

    ],

    /*
    |--------------------------------------------------------------------------
    | N8N Pipeline Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for sending pipeline etapa change events to n8n.
    |
    */

    'n8n_pipeline' => [

        'url' => env('N8N_PIPELINE_WEBHOOK_URL', 'https://prod-sailus-getway.jsvdny.easypanel.host/api/v1/webhook/crm-outbound'),

        'secret' => env('N8N_PIPELINE_WEBHOOK_SECRET'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Personas Snapshot Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | PR-J — Mercurio CQRS mirror for `personas`. Every successful create/
    | update/delete on `personas` dispatches `PersonaChanged`; the
    | `PersonasSnapshotEmitter` listener routes the event to this config
    | section and pushes a `DispatchOutboundWebhookJob` onto the `webhooks`
    | queue.
    |
    | REQ-PSWH-004: the secret defaults to `webhook.outbound.secret` when
    | `PERSONA_SNAPSHOT_WEBHOOK_SECRET` is unset, so staging can reuse the
    | existing shared secret without provisioning a new one.
    |
    | REQ-PSWH-005: `EMIT_PERSONA_SNAPSHOT_WEBHOOK=false` is the emergency
    | kill-switch; the listener becomes a no-op (logs `personas_snapshot.skipped`).
    |
    */

    'personas_snapshot' => [

        'enabled' => filter_var(env('EMIT_PERSONA_SNAPSHOT_WEBHOOK', true), FILTER_VALIDATE_BOOLEAN),

        'url' => env('PERSONA_SNAPSHOT_WEBHOOK_URL', 'http://localhost:8000/api/webhook/personas-snapshot'),

        // Elvis fallback mirrors the runtime fallback in CrmWebhookSender so
        // the config file is self-documenting. The Elvis fallback only
        // applies if WEBHOOK_OUTBOUND_SECRET resolves to a non-empty string.
        'secret' => env('PERSONA_SNAPSHOT_WEBHOOK_SECRET') ?: env('WEBHOOK_OUTBOUND_SECRET', 'your-webhook-secret-key'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Entidades Snapshot Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Commit 7 — Mercurio CQRS mirror for `entidad`. Every successful
    | create/update/delete on `entidad`, plus pivot mutations on
    | `entidad_relacion`, dispatches `EntidadChanged`. The
    | `EntidadesSnapshotEmitter` listener routes the event to this config
    | section and pushes a `DispatchOutboundWebhookJob` onto the `webhooks`
    | queue.
    |
    | Idempotency: each event carries a UUIDv4 `event_id` so Mercurio can
    | dedupe replays — see `App\Domain\Events\EntidadChanged`.
    |
    | EMIT_ENTIDADES_SNAPSHOT_WEBHOOK=false is the emergency kill-switch
    | mirroring the personas kill-switch.
    */

    'entidades_snapshot' => [

        'enabled' => filter_var(env('EMIT_ENTIDADES_SNAPSHOT_WEBHOOK', true), FILTER_VALIDATE_BOOLEAN),

        'url' => env('ENTIDADES_SNAPSHOT_WEBHOOK_URL', 'http://localhost:8000/api/webhook/entidades-snapshot'),

        // Elvis fallback mirrors the runtime fallback in CrmWebhookSender.
        'secret' => env('ENTIDADES_SNAPSHOT_WEBHOOK_SECRET') ?: env('WEBHOOK_OUTBOUND_SECRET', 'your-webhook-secret-key'),

    ],

];
