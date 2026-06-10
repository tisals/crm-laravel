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
     */
    public function run(): void
    {
        // 1. Cotización pipeline
        DB::table('pipelines')->updateOrInsert(
            ['codigo' => 'COTIZACION'],
            ['nombre' => 'Cotización', 'habilitado' => true, 'updated_at' => now(), 'created_at' => now()]
        );
        $cotizacionId = DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');

        $cotizacionStages = [
            'Borrador',
            'Enviada',
            'Aceptada',
            'Rechazada',
            'Ganada',
            'Perdida',
        ];
        foreach ($cotizacionStages as $index => $stage) {
            DB::table('pipeline_etapas')->updateOrInsert(
                ['pipeline_id' => $cotizacionId, 'nombre' => $stage],
                ['orden' => $index, 'habilitado' => true, 'updated_at' => now(), 'created_at' => now()]
            );
        }
        // Clean up obsolete stages not in the canonical list (only if no linked opportunities)
        $this->cleanupObsoleteStages($cotizacionId, $cotizacionStages);

        // 2. Recuperación pipeline
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
     */
    private function cleanupObsoleteStages(int $pipelineId, array $canonicalNames): void
    {
        $obsolete = DB::table('pipeline_etapas')
            ->where('pipeline_id', $pipelineId)
            ->whereNotIn('nombre', $canonicalNames)
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
