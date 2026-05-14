<?php

namespace App\Events;

use App\Models\Contact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Contact $contact,
    ) {}
}
