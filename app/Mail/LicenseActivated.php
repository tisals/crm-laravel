<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LicenseActivated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $data
    ) {}

    public function envelope(): Envelope
    {
        $planName = $this->data['plan_name'] ?? 'Plan';
        return new Envelope(
            subject: "Activa tu licencia SAIlus — {$planName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.license_activated',
        );
    }
}
