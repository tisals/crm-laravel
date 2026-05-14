<?php

namespace App\Infrastructure\Webhook\Listeners;

use App\Events\OrganizationCreated;
use App\Infrastructure\Webhook\DispatchOutboundWebhookJob;

class OrganizationCreatedListener
{
    public function handle(OrganizationCreated $event): void
    {
        DispatchOutboundWebhookJob::dispatch('organization.created', [
            'organization_id' => $event->organization->id,
            'name' => $event->organization->name,
        ]);
    }
}
