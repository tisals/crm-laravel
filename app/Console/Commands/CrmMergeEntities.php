<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * crm:merge-entities — Fusionar dos entidades duplicadas.
 *
 * Estrategia: la entidad GANADORA absorbe a la HUÉRFANA.
 *  - contacto_id de oportunidad/seguimiento: reasigna a la ganadora
 *  - oportunidad: reasigna entidad_id a la ganadora
 *  - seguimiento: reasigna entidad_id a la ganadora
 *  - contacto de la huérfana: se BORRA (idempotente, cascade-safe)
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
        $ctsLoser = DB::table('contacto')->where('entidad_id', $loserId)->count();

        $this->info("");
        $this->info("Entidad huérfana tiene:");
        $this->info("  - $oppsLoser oportunidades");
        $this->info("  - $segsLoser seguimientos");
        $this->info("  - $ctsLoser contactos");
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

        if ($ctsLoser > 0) {
            $this->info("");
            $this->info("Contactos que se BORRARÁN:");
            $cts = DB::table('contacto')->where('entidad_id', $loserId)->get(['id', 'nombres', 'apellidos', 'email_contacto']);
            foreach ($cts as $c) {
                $this->line("  - [cto={$c->id}] {$c->nombres} {$c->apellidos} <{$c->email_contacto}>");
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

            $ctsDeleted = DB::table('contacto')
                ->where('entidad_id', $loserId)
                ->delete();

            $entDeleted = DB::table('entidad')
                ->where('id', $loserId)
                ->delete();

            $this->info("");
            $this->info("════════════════════════════════════════════════════════════");
            $this->info(" Merge aplicado");
            $this->info("════════════════════════════════════════════════════════════");
            $this->info("  - $oppsUpdated oportunidades reasignadas");
            $this->info("  - $segsUpdated seguimientos reasignados");
            $this->info("  - $ctsDeleted contactos borrados");
            $this->info("  - $entDeleted entidad borrada");
            $this->info("");

            return self::SUCCESS;
        });
    }
}
