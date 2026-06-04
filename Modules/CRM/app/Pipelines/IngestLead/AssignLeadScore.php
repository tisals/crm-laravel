<?php

namespace Modules\CRM\Pipelines\IngestLead;

use Modules\CRM\Actions\AssignScoreAction;
use Closure;

class AssignLeadScore
{
    public function handle(array $passable, Closure $next)
    {
        $data = $passable;

        $action = new AssignScoreAction();
        $action->execute($data['contacto']);

        return $next($data);
    }
}
