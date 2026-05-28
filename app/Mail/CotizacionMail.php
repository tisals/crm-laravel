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
        public ?string $mensajePersonalizado = null,
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
                'mensajePersonalizado' => $this->mensajePersonalizado,
            ],
        );
    }

    public function attachments(): array
    {
        $oportunidad = $this->oportunidad->load(['entidad', 'contacto', 'detalles.producto']);

        // ── Brand resolution desde linea_negocio de TODOS los productos ──
        $brandEntity = null;
        $lineas = $oportunidad->detalles
            ->pluck('producto.linea_negocio')
            ->filter()
            ->unique();

        $hasTecnoinnsoft = $lineas->contains(fn ($ln) => stripos($ln, 'tecnoinnsoft') !== false);
        $hasDeseguridad  = $lineas->contains(fn ($ln) => stripos($ln, 'deseguridad') !== false);

        if ($hasTecnoinnsoft) {
            $brandEntity = \App\Models\Entidad::where('estado', 'Propia')
                ->where(function ($q) {
                    $q->where('nombre', 'like', '%Tecnoinnsoft%')
                      ->orWhere('nombre_comercial', 'like', '%Tecnoinnsoft%');
                })->first();
        }

        if (!$brandEntity && $hasDeseguridad) {
            $brandEntity = \App\Models\Entidad::where('estado', 'Propia')
                ->where(function ($q) {
                    $q->where('nombre', 'like', '%Deseguridad%')
                      ->orWhere('nombre_comercial', 'like', '%Deseguridad%');
                })->first();
        }

        if (!$brandEntity) {
            $brandEntity = \App\Models\Entidad::where('estado', 'Propia')->first();
        }
        // ── Fin brand resolution ──

        $subtotal = $oportunidad->detalles->sum(fn ($d) => (float) $d->cantidad * (float) $d->vr_unitario);
        $iva = $oportunidad->detalles->sum('iva');
        $total = $oportunidad->detalles->sum('vr_total');

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
                'logo' => $brandEntity?->logo ?? '',
                'slogan' => 'Soluciones en Seguridad y Salud en el Trabajo',
                'name' => $brandEntity?->nombre_comercial
                    ?? $brandEntity?->nombre
                    ?? 'deseguridad.net',
                'nombre_comercial' => $brandEntity?->nombre_comercial ?? $brandEntity?->nombre ?? '',
                'nit' => $brandEntity?->identificacion ?? '',
                'email' => $brandEntity?->email ?? '',
                'direccion' => $brandEntity?->direccion ?? '',
                'telefono' => $brandEntity?->telefono ?? '',
                'dominio' => $brandEntity?->dominio ?? '',
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
                'producto' => $d->producto?->nombre ?? $d->concepto ?? 'Servicio',
                'descripcion' => $d->descripcion ?? $d->concepto ?? '—',
                'unidad' => $d->medida ?? 'Und',
                'qty' => number_format((float) $d->cantidad, 2),
                'unit_value' => '$' . number_format((float) $d->vr_unitario, 0),
                'total' => '$' . number_format((float) $d->vr_total, 0),
                'iva' => (float) $d->iva,
            ]),
            'subtotal' => '$' . number_format($subtotal, 0),
            'iva' => '$' . number_format($iva, 0),
            'total_general' => '$' . number_format($total, 0),
        ];

        $pdf = Pdf::loadView('pdf.cotizacion', $data);
        $pdf->setPaper('letter');
        $pdf->setOption('isRemoteEnabled', true);

        return [
            Attachment::fromData(fn () => $pdf->output(), "cotizacion-{$this->oportunidad->codigo}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
