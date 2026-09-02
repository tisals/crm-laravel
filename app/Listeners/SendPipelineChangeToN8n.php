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

        // Eager load relationships for enriched payload.
        //
        // Resolve `Oportunidad` through the container (`app()->make`)
        // instead of the static facade so unit tests can swap in a
        // Mockery double via `$this->app->instance(Oportunidad::class,
        // $mock)`. Static `Oportunidad::with(...)` calls bypass the
        // container and would ignore the registered instance.
        $oportunidadModel = app(\Modules\CRM\Models\Oportunidad::class);
        $oportunidad = $oportunidadModel
            ->with(['contacto', 'entidad.usuarios', 'pipeline', 'pipelineEtapa'])
            ->find($event->oportunidadId);

        // Resolve `pipeline` / `pipelineEtapa` / `contacto` / `entidad`
        // safely. The `oportunidad` table has a legacy `pipeline` string
        // column that shadows the `pipeline()` belongsTo relation on
        // direct attribute access, so we walk the relation method
        // explicitly. We also tolerate test mocks that swap in a
        // stdClass (no relationLoaded() / getRelation()) by falling
        // back to direct property reads.
        $resolve = function ($model, string $key) {
            if (! is_object($model)) {
                return null;
            }
            if (method_exists($model, 'relationLoaded') && $model->relationLoaded($key)) {
                return $model->getRelation($key);
            }
            if (method_exists($model, $key)) {
                $r = $model->{$key}();
                if (method_exists($r, 'first')) {
                    return $r->first();
                }

                return $r;
            }

            return $model->{$key} ?? null;
        };

        $pipeline = $resolve($oportunidad, 'pipeline');
        $etapa = $resolve($oportunidad, 'pipelineEtapa');
        $contacto = $resolve($oportunidad, 'contacto');
        $entidad = $resolve($oportunidad, 'entidad');

        // Comercial asignado comes from entidad_persona pivot (renamed in
        // commit fe99f70; resolved via usuarios.persona_id FK).
        $comercial = $entidad?->usuarios?->first();

        $payload = [
            'dedup_key' => 'pipeline-change-'.$event->oportunidadId.'-'.$event->timestamp,
            'oportunidad_id' => $event->oportunidadId,
            'oportunidad_codigo' => $oportunidad?->codigo,
            'previous_etapa_id' => $event->previousEtapaId,
            'new_etapa_id' => $event->newEtapaId,
            'pipeline_id' => $event->pipelineId,
            'pipeline_nombre' => $pipeline?->nombre,
            'pipeline_codigo' => $pipeline?->codigo,
            'etapa_nombre' => $etapa?->nombre,
            'contacto_nombre' => $contacto
                ? trim($contacto->nombres.' '.$contacto->apellidos)
                : null,
            'contacto_email' => $contacto?->email_contacto,
            'entidad_nombre' => $entidad?->nombre,
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
