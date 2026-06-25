<?php

namespace App\Models;

/**
 * @deprecated Use Modules\CRM\Models\Seguimiento directly. Kept for backward
 *             compatibility during the modular-migration transition.
 */
class Seguimiento extends \Modules\CRM\Models\Seguimiento
{
    // Inherits everything (table, fillable, casts, relations, traits).
    // Kept as a thin alias so existing `App\Models\Seguimiento::class` references
    // in seeders/factories/tests continue to work unchanged.
}
