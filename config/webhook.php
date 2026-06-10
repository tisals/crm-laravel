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

];
