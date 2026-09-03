<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 5 follow-up — add `vigencia_meses` to `entidad_relacion`.
 *
 * The original Commit 5 spec included `vigencia_meses` (the contract
 * duration in months) but the migration that shipped missed adding
 * it. This follow-up fills the gap.
 *
 * Semantics: `vigencia_meses = N` means the business relation is
 * contractually scoped to N months from `effective_from`. NULL means
 * "no fixed duration" (open-ended or not applicable).
 *
 * Per the user's model:
 *   - Cliente one-shot:     frecuencia='unica',      recurrencia_cada_meses=NULL, vigencia_meses=NULL
 *   - Cliente 3-month:      frecuencia='unica',      recurrencia_cada_meses=NULL, vigencia_meses=3
 *   - Cliente mensual:      frecuencia='recurrente', recurrencia_cada_meses=1,  vigencia_meses=NULL or 12
 *   - Cliente anual:        frecuencia='recurrente', recurrencia_cada_meses=12, vigencia_meses=12
 *   - Proveedor mensual:    frecuencia='recurrente', recurrencia_cada_meses=1,  vigencia_meses=12
 *   - Proveedor anual:      frecuencia='recurrente', recurrencia_cada_meses=12, vigencia_meses=12
 *   - Prospecto / Propia:   both NULL (not applicable)
 *
 * No CHECK constraint on `vigencia_meses` — it's optional and can be
 * any positive integer. The CHECK on `recurrencia_cada_meses` (set
 * by 140000) still applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `entidad_relacion` ADD COLUMN `vigencia_meses` INT UNSIGNED NULL AFTER `recurrencia_cada_meses`');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `entidad_relacion` DROP COLUMN IF EXISTS `vigencia_meses`');
    }
};
