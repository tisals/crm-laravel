<?php

namespace App\Providers;

use App\Domain\Events\PersonaChanged;
use App\Events\ContactUpdated;
use App\Events\OrganizationCreated;
use App\Events\PaymentCompleted;
use App\Events\PipelineEtapaChanged;
use App\Infrastructure\Webhook\Listeners\ContactUpdatedListener;
use App\Infrastructure\Webhook\Listeners\OrganizationCreatedListener;
use App\Infrastructure\Webhook\Listeners\PaymentCompletedListener;
use App\Infrastructure\Webhook\PersonasSnapshotEmitter;
use App\Listeners\SendPipelineChangeToN8n;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<class-string>>
     */
    protected $listen = [
        OrganizationCreated::class => [
            OrganizationCreatedListener::class,
        ],
        ContactUpdated::class => [
            ContactUpdatedListener::class,
        ],
        PaymentCompleted::class => [
            PaymentCompletedListener::class,
        ],
        PipelineEtapaChanged::class => [
            SendPipelineChangeToN8n::class,
        ],

        // PR-J — PersonaChanged domain event (REQ-PSWH-001) routes to the
        // Mercurio CQRS mirror listener. The listener honours the
        // personas_snapshot kill-switch (REQ-PSWH-005) and queues a
        // DispatchOutboundWebhookJob on the `webhooks` queue (REQ-PSWH-003).
        PersonaChanged::class => [
            PersonasSnapshotEmitter::class,
        ],
    ];
}
