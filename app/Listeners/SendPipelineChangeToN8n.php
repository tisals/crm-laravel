<?php

namespace App\Listeners;

use App\Events\PipelineEtapaChanged;
use App\Infrastructure\Webhook\CrmWebhookSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\CRM\Models\Oportunidad;

class SendPipelineChangeToN8n implements ShouldQueue
{
    public function __construct(
        private CrmWebhookSender $sender
    ) {}

    public function handle(PipelineEtapaChanged $event): void
    {
        if (! config('webhook.n8n_pipeline.url')) {
            return;
        }

        // Eager load relationships for enriched payload
        $oportunidad = Oportunidad::with(['contacto', 'entidad.usuarios', 'pipeline', 'pipelineEtapa'])
            ->find($event->oportunidadId);

        // Comercial asignado comes from entidad_usuario pivot
        $comercial = $oportunidad?->entidad?->usuarios?->first();

        $payload = [
            'dedup_key' => 'pipeline-change-'.$event->oportunidadId.'-'.$event->timestamp,
            'oportunidad_id' => $event->oportunidadId,
            'oportunidad_codigo' => $oportunidad?->codigo,
            'previous_etapa_id' => $event->previousEtapaId,
            'new_etapa_id' => $event->newEtapaId,
            'pipeline_id' => $event->pipelineId,
            'pipeline_nombre' => $oportunidad?->pipeline?->nombre,
            'pipeline_codigo' => $oportunidad?->pipeline?->codigo,
            'etapa_nombre' => $oportunidad?->pipelineEtapa?->nombre,
            'contacto_nombre' => $oportunidad?->contacto
                ? trim($oportunidad->contacto->nombres.' '.$oportunidad->contacto->apellidos)
                : null,
            'contacto_email' => $oportunidad?->contacto?->email_contacto,
            'entidad_nombre' => $oportunidad?->entidad?->nombre,
            'asesor_nombre' => $comercial?->nombre,
            'asesor_email' => $comercial?->email,
            'asesor_telefono' => $comercial?->telefono,
            'timestamp' => $event->timestamp,
            'user_id' => $event->userId,
        ];

        $this->sender->send(
            event: 'pipeline.etapa_changed',
            data: $payload,
            configPrefix: 'n8n_pipeline',
        );
    }
}
