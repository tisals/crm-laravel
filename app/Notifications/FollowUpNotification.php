<?php

namespace App\Notifications;

use App\Models\Seguimiento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FollowUpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Seguimiento $seguimiento,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $fecha = $this->seguimiento->fecha;
        $hora = $this->seguimiento->hora?->slice(0, 5) ?? '';

        return (new MailMessage)
            ->subject('⏰ Recordatorio de Seguimiento - ' . $this->seguimiento->tipo)
            ->line("Tienes un seguimiento programado para el {$fecha}" . ($hora ? " a las {$hora}" : ''))
            ->line($this->seguimiento->notas ?? '')
            ->action('Ver en CRM', url('/crm'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'seguimiento_id' => $this->seguimiento->id,
            'contacto_id' => $this->seguimiento->contacto_id,
            'oportunidad_id' => $this->seguimiento->oportunidad_id,
            'tipo' => $this->seguimiento->tipo,
            'fecha' => $this->seguimiento->fecha,
            'hora' => $this->seguimiento->hora,
            'notas' => $this->seguimiento->notas,
            'estado' => $this->seguimiento->estado,
        ];
    }
}
