<?php

namespace Modules\CRM\Actions;

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Pipelines\IngestLead\AssignLeadScore;
use Modules\CRM\Pipelines\IngestLead\CreateOportunidad;
use Modules\CRM\Pipelines\IngestLead\NormalizeLeadData;
use Modules\CRM\Pipelines\IngestLead\ResolveOrCreateContacto;
use Modules\CRM\Pipelines\IngestLead\ResolveOrCreateEntidad;

class IngestLeadAction
{
    public function execute(array $data)
    {
        return DB::transaction(function () use ($data) {
            return app(Pipeline::class)
                ->send($data)
                ->through([
                    NormalizeLeadData::class,
                    ResolveOrCreateEntidad::class,
                    ResolveOrCreateContacto::class,
                    AssignLeadScore::class,
                    CreateOportunidad::class,
                ])
                ->then(function ($passable) {
                    return $passable;
                });
        });
    }
}
