<?php

namespace App\Providers;

use App\Events\ContactUpdated;
use App\Events\OrganizationCreated;
use App\Events\PaymentCompleted;
use App\Infrastructure\Webhook\Listeners\ContactUpdatedListener;
use App\Infrastructure\Webhook\Listeners\OrganizationCreatedListener;
use App\Infrastructure\Webhook\Listeners\PaymentCompletedListener;
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
    ];
}
