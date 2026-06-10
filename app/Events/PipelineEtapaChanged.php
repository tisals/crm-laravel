<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PipelineEtapaChanged
{
    use Dispatchable;

    public int $oportunidadId;

    public ?int $previousEtapaId;

    public int $newEtapaId;

    public int $pipelineId;

    public string $timestamp;

    public ?int $userId;

    public function __construct(
        int $oportunidadId,
        ?int $previousEtapaId,
        int $newEtapaId,
        int $pipelineId,
        ?int $userId = null
    ) {
        $this->oportunidadId = $oportunidadId;
        $this->previousEtapaId = $previousEtapaId;
        $this->newEtapaId = $newEtapaId;
        $this->pipelineId = $pipelineId;
        $this->timestamp = now()->toISOString();
        $this->userId = $userId;
    }
}
