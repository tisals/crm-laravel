<?php

namespace App\Mail;

use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Contact $contact,
        public array $diagnostico = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu Reporte Técnico - Tecnoinnsoft',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.registration-report',
            with: [
                'contact' => $this->contact,
                'diagnostico' => $this->diagnostico,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
