<?php

namespace App\Mail;

use App\Models\Oportunidad;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CotizacionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Oportunidad $oportunidad,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Cotización {$this->oportunidad->codigo}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cotizacion',
            with: [
                'codigo' => $this->oportunidad->codigo,
                'entidad' => $this->oportunidad->entidad?->nombre ?? 'Cliente',
                'url' => url("/api/v1/oportunidades/{$this->oportunidad->id}/pdf"),
            ],
        );
    }

    public function attachments(): array
    {
        $oportunidad = $this->oportunidad->load(['entidad', 'contacto', 'detalles.producto']);

        $data = [
            'cotizacion_no' => $oportunidad->codigo,
            'opportunity' => [
                'id' => $oportunidad->codigo,
                'version' => '1',
                'sent_at' => $oportunidad->created_at?->format('d/m/Y') ?? now()->format('d/m/Y'),
                'due_date' => $oportunidad->validez_oferta ?? 30,
                'observations' => $oportunidad->observaciones ?? '',
                'aclarations' => $oportunidad->aclaraciones ?? '',
                'payment_conditions' => $oportunidad->forma_pago ?? '',
                'guarantees' => $oportunidad->garantia ?? '',
                'delivery_time' => $oportunidad->tiempo_entrega ?? '',
            ],
            'brand' => [
                'logo' => '',
                'slogan' => 'Soluciones en Seguridad y Salud en el Trabajo',
                'name' => 'deseguridad.net',
                'business_sign' => 'Tecnoinnsoft SAS · NIT 901.234.567-0',
            ],
            'entity' => [
                'name' => $oportunidad->entidad?->nombre ?? '—',
                'city' => $oportunidad->entidad?->dominio ?? '—',
            ],
            'contact' => [
                'user' => [
                    'name' => $oportunidad->contacto?->nombres ?? '—',
                    'email' => $oportunidad->contacto?->email_contacto ?? '—',
                ],
            ],
            'detalle_oportunidad' => $oportunidad->detalles->map(fn ($d) => [
                'name' => $d->producto?->nombre ?? $d->concepto ?? 'Servicio',
                'unidad' => $d->medida ?? 'Und',
                'qty' => number_format((float) $d->cantidad, 2),
                'unit_value' => '$' . number_format((float) $d->vr_unitario, 0),
                'total' => '$' . number_format((float) $d->vr_total, 0),
                'iva' => (float) $d->iva,
            ]),
            'subtotal' => '$' . number_format($oportunidad->detalles->sum('vr_total'), 0),
            'iva' => '$' . number_format($oportunidad->detalles->sum('iva'), 0),
            'total_general' => '$' . number_format($oportunidad->detalles->sum('vr_total') + $oportunidad->detalles->sum('iva'), 0),
        ];

        $pdf = Pdf::loadView('pdf.cotizacion', $data);
        $pdf->setPaper('letter');

        return [
            Attachment::fromData(fn () => $pdf->output(), "cotizacion-{$this->oportunidad->codigo}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
