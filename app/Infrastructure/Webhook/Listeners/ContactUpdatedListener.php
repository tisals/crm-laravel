<?php

namespace App\Infrastructure\Webhook\Listeners;

use App\Events\ContactUpdated;
use App\Infrastructure\Webhook\DispatchOutboundWebhookJob;

class ContactUpdatedListener
{
    public function handle(ContactUpdated $event): void
    {
        DispatchOutboundWebhookJob::dispatch('contact.updated', [
            'contact_id' => $event->contact->id,
            'email' => $event->contact->email,
            'name' => $event->contact->name,
        ]);
    }
}
