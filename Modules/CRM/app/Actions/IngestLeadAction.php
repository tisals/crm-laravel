<?php

namespace Modules\CRM\Actions;

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;

class IngestLeadAction
{
    public function execute(array $data)
    {
        return DB::transaction(function () use ($data) {
            return app(Pipeline::class)
                ->send($data)
                ->through([
                    \Modules\CRM\Pipelines\IngestLead\NormalizeLeadData::class,
                    \Modules\CRM\Pipelines\IngestLead\ResolveOrCreateEntidad::class,
                    \Modules\CRM\Pipelines\IngestLead\ResolveOrCreateContacto::class,
                    \Modules\CRM\Pipelines\IngestLead\AssignLeadScore::class,
                    \Modules\CRM\Pipelines\IngestLead\CreateOportunidad::class,
                ])
                ->then(function ($passable) {
                    return $passable;
                });
        });
    }
}
