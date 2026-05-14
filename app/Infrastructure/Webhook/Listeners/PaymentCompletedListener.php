<?php

namespace App\Infrastructure\Webhook\Listeners;

use App\Events\PaymentCompleted;
use App\Infrastructure\Webhook\DispatchOutboundWebhookJob;

class PaymentCompletedListener
{
    public function handle(PaymentCompleted $event): void
    {
        DispatchOutboundWebhookJob::dispatch('payment.completed', [
            'organization_id' => $event->organization->id,
            'name' => $event->organization->name,
            ...$event->paymentData,
        ]);
    }
}
