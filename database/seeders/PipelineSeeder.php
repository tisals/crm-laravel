<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PipelineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds 2 default pipelines idempotently:
     * - Cotización (codigo: COTIZACION) with 5 etapas
     * - Recuperación (codigo: RECUPERACION) with 5 etapas
     *
     * NOTE: stages are identified by STABLE codigo (parametrizable label).
     * After the migration 2026_06_25_000000_refactor_cotizacion_pipeline_etapas,
     * the Cotización pipeline has only 5 canonical stages (no more Ganada/Perdida).
     */
    public function run(): void
    {
        // 1. Cotización pipeline (5 stages, identified by codigo)
        DB::table('pipelines')->updateOrInsert(
            ['codigo' => 'COTIZACION'],
            ['nombre' => 'Cotización', 'habilitado' => true, 'updated_at' => now(), 'created_at' => now()]
        );
        $cotizacionId = DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');

        $cotizacionStages = [
            'BORRADOR' => 'Borrador',
            'ENVIADA' => 'Enviada',
            'EN_NEGOCIACION' => 'En negociación',
            'ACEPTADA' => 'Aceptada',
            'RECHAZADA' => 'Rechazada',
        ];
        foreach ($cotizacionStages as $codigo => $nombre) {
            DB::table('pipeline_etapas')->updateOrInsert(
                ['pipeline_id' => $cotizacionId, 'codigo' => $codigo],
                [
                    'nombre' => $nombre,
                    'orden' => array_search($codigo, array_keys($cotizacionStages)),
                    'habilitado' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
        // Clean up obsolete stages NOT in the canonical codigo list (only if no linked opportunities)
        $this->cleanupObsoleteStages($cotizacionId, array_keys($cotizacionStages));

        // 2. Recuperación pipeline (legacy 5 stages with labels)
        DB::table('pipelines')->updateOrInsert(
            ['codigo' => 'RECUPERACION'],
            ['nombre' => 'Recuperación', 'habilitado' => true, 'updated_at' => now(), 'created_at' => now()]
        );
        $recuperacionId = DB::table('pipelines')->where('codigo', 'RECUPERACION')->value('id');

        $recuperacionStages = [
            'Inicio',
            'Con Cita',
            'En Negociación',
            'Aprobado',
            'Rechazado',
        ];
        foreach ($recuperacionStages as $index => $stage) {
            DB::table('pipeline_etapas')->updateOrInsert(
                ['pipeline_id' => $recuperacionId, 'nombre' => $stage],
                ['orden' => $index, 'habilitado' => true, 'updated_at' => now(), 'created_at' => now()]
            );
        }
        $this->cleanupObsoleteStages($recuperacionId, $recuperacionStages);
    }

    /**
     * Delete stages for a pipeline that are NOT in the canonical list
     * and have no linked opportunities.
     *
     * @param  array<int, string>|array<string, string>  $canonicalKeys  Stage codigos (Cotización) or names (Recuperación)
     */
    private function cleanupObsoleteStages(int $pipelineId, array $canonicalKeys): void
    {
        $obsolete = DB::table('pipeline_etapas')
            ->where('pipeline_id', $pipelineId)
            ->where(function ($q) use ($canonicalKeys) {
                $q->whereNotIn('codigo', $canonicalKeys)
                  ->orWhereNull('codigo');
            })
            ->get();

        foreach ($obsolete as $stage) {
            $hasOpps = DB::table('oportunidad')
                ->where('pipeline_etapa_id', $stage->id)
                ->exists();
            if (! $hasOpps) {
                DB::table('pipeline_etapas')->where('id', $stage->id)->delete();
            }
        }
    }
}
