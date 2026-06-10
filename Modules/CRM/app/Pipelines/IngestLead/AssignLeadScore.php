<?php

namespace Modules\CRM\Pipelines\IngestLead;

use Closure;
use Modules\CRM\Actions\AssignScoreAction;

class AssignLeadScore
{
    public function handle(array $passable, Closure $next)
    {
        $data = $passable;

        $action = new AssignScoreAction;
        $action->execute($data['contacto']);

        return $next($data);
    }
}
