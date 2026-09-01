<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * crm:merge-entities — Fusionar dos entidades duplicadas.
 *
 * Estrategia: la entidad GANADORA absorbe a la HUÉRFANA.
 *  - oportunidad: reasigna entidad_id a la ganadora
 *  - seguimiento: reasigna entidad_id a la ganadora
 *  - entidad_persona: migra los pivots del loser al winner (las personas
 *    siguen existiendo; sólo se cambia su entidad binding). Si la persona
 *    YA tiene un pivot con la winner, el pivot del loser se borra sin
 *    crear duplicado.
 *  - contactos del huérfano: NO se borran en este commit. Cada contacto
 *    pertenece a una persona; el pivot de la persona ya se migró arriba.
 *    Borrar contactos sería destructivo sin necesidad (la auditoría se
 *    preserva via soft-delete si fuera necesario en una iteración futura).
 *  - entidad huérfana: se BORRA
 *
 * NO se preserva historial — es un merge destructivo. Hacer --dry-run
 * primero para ver qué se va a tocar.
 *
 * Uso:
 *   php artisan crm:merge-entities 361 451 --dry-run
 *   php artisan crm:merge-entities 361 451          # ejecuta el merge
 */
class CrmMergeEntities extends Command
{
    protected $signature = 'crm:merge-entities
        {winner : ID de la entidad que sobrevive (ganadora)}
        {loser : ID de la entidad que se va a absorber (huérfana)}
        {--dry-run : Solo mostrar lo que se haría}';

    protected $description = 'Fusionar dos entidades duplicadas (winner absorbe a loser)';

    public function handle(): int
    {
        $winnerId = (int) $this->argument('winner');
        $loserId = (int) $this->argument('loser');
        $dryRun = $this->option('dry-run');

        if ($winnerId === $loserId) {
            $this->error("winner y loser no pueden ser el mismo ID");
            return self::FAILURE;
        }

        $winner = DB::table('entidad')->where('id', $winnerId)->first();
        $loser = DB::table('entidad')->where('id', $loserId)->first();

        if (! $winner) {
            $this->error("No existe la entidad winner (id={$winnerId})");
            return self::FAILURE;
        }
        if (! $loser) {
            $this->error("No existe la entidad loser (id={$loserId})");
            return self::FAILURE;
        }

        $this->info("════════════════════════════════════════════════════════════");
        $this->info(" Merge de entidades");
        $this->info("════════════════════════════════════════════════════════════");
        $this->info(" GANADORA (sobrevive): id={$winner->id} \"{$winner->nombre}\"");
        $this->info(" HUÉRFANA  (se borra):  id={$loser->id} \"{$loser->nombre}\"");
        $this->info("════════════════════════════════════════════════════════════");

        // Stats PRE
        $oppsLoser = DB::table('oportunidad')->where('entidad_id', $loserId)->count();
        $segsLoser = DB::table('seguimiento')->where('entidad_id', $loserId)->count();
        // Per commit fe99f70: `contacto.entidad_id` was dropped. Contactos
        // belong to the loser entidad via the persona pivot; the count is
        // for visibility only (no destructive op runs against contactos here).
        $ctsLoser = DB::table('contacto as c')
            ->join('entidad_persona as ep', 'ep.persona_id', '=', 'c.persona_id')
            ->where('ep.entidad_id', $loserId)
            ->count();
        $pivotsLoser = DB::table('entidad_persona')->where('entidad_id', $loserId)->count();

        $this->info("");
        $this->info("Entidad huérfana tiene:");
        $this->info("  - $oppsLoser oportunidades");
        $this->info("  - $segsLoser seguimientos");
        $this->info("  - $ctsLoser contactos (via pivot)");
        $this->info("  - $pivotsLoser pivots entidad_persona");
        $this->info("");

        if ($oppsLoser > 0) {
            $this->info("Oportunidades que se reasignarán:");
            $opps = DB::table('oportunidad')->where('entidad_id', $loserId)->get(['id', 'codigo', 'estado', 'fecha']);
            foreach ($opps as $o) {
                $this->line("  - [opp={$o->id}] {$o->codigo} ({$o->estado}, {$o->fecha})");
            }
        }

        if ($segsLoser > 0) {
            $this->info("");
            $this->info("Seguimientos que se reasignarán:");
            $segs = DB::table('seguimiento')->where('entidad_id', $loserId)->get(['id', 'tipo', 'estado', 'fecha']);
            foreach ($segs as $s) {
                $this->line("  - [seg={$s->id}] {$s->tipo} ({$s->estado}, {$s->fecha})");
            }
        }

        if ($pivotsLoser > 0) {
            $this->info("");
            $this->info("Pivots entidad_persona que se migrarán (persona_id, loser) → (persona_id, winner):");
            $pivots = DB::table('entidad_persona')->where('entidad_id', $loserId)->get(['persona_id', 'categoria']);
            foreach ($pivots as $p) {
                $this->line("  - [persona={$p->persona_id}, categoria={$p->categoria}]");
            }
        }

        if ($dryRun) {
            $this->info("");
            $this->warn("DRY-RUN: nada se modificó. Re-correr sin --dry-run para aplicar.");
            return self::SUCCESS;
        }

        if (! $this->confirm("¿Aplicar el merge?", false)) {
            $this->info("Cancelado por el usuario.");
            return self::FAILURE;
        }

        // Aplicar
        return DB::transaction(function () use ($winnerId, $loserId) {
            $oppsUpdated = DB::table('oportunidad')
                ->where('entidad_id', $loserId)
                ->update(['entidad_id' => $winnerId, 'updated_at' => now()]);

            $segsUpdated = DB::table('seguimiento')
                ->where('entidad_id', $loserId)
                ->update(['entidad_id' => $winnerId, 'updated_at' => now()]);

            // Migrate entidad_persona pivots from loser to winner.
            // For each (persona_id, loser) we ensure a matching
            // (persona_id, winner) exists; if one already exists we drop
            // the loser pivot (it would otherwise violate the composite
            // PK once we try to re-insert under winner).
            $loserPivots = DB::table('entidad_persona')
                ->where('entidad_id', $loserId)
                ->get();

            $pivotsMigrated = 0;
            $pivotsDeduped = 0;
            foreach ($loserPivots as $pivot) {
                $existingWinner = DB::table('entidad_persona')
                    ->where('persona_id', $pivot->persona_id)
                    ->where('entidad_id', $winnerId)
                    ->exists();

                if ($existingWinner) {
                    DB::table('entidad_persona')
                        ->where('persona_id', $pivot->persona_id)
                        ->where('entidad_id', $loserId)
                        ->delete();
                    $pivotsDeduped++;
                } else {
                    DB::table('entidad_persona')
                        ->where('persona_id', $pivot->persona_id)
                        ->where('entidad_id', $loserId)
                        ->update([
                            'entidad_id' => $winnerId,
                            'updated_at' => now(),
                        ]);
                    $pivotsMigrated++;
                }
            }

            $entDeleted = DB::table('entidad')
                ->where('id', $loserId)
                ->delete();

            $this->info("");
            $this->info("════════════════════════════════════════════════════════════");
            $this->info(" Merge aplicado");
            $this->info("════════════════════════════════════════════════════════════");
            $this->info("  - $oppsUpdated oportunidades reasignadas");
            $this->info("  - $segsUpdated seguimientos reasignados");
            $this->info("  - $pivotsMigrated pivots migrados (persona, loser) → (persona, winner)");
            $this->info("  - $pivotsDeduped pivots deduplicados (ya existía binding con winner)");
            $this->info("  - $entDeleted entidad borrada");
            $this->info("");

            return self::SUCCESS;
        });
    }
}
