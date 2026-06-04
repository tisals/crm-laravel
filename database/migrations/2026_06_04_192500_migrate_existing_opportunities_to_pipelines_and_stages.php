<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function () {
            // 1. Resolve or create default 'Llegada' pipeline
            $pipelineId = DB::table('pipelines')->where('codigo', 'llegada')->value('id');
            if (!$pipelineId) {
                $pipelineId = DB::table('pipelines')->insertGetId([
                    'nombre' => 'Llegada',
                    'codigo' => 'llegada',
                    'habilitado' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 2. Fetch or create default stages to map old estado string values
            $stages = ['Borrador', 'Enviada', 'Aceptada', 'Rechazada', 'Ganada', 'Perdida'];
            $stageMap = [];

            foreach ($stages as $index => $stageName) {
                $stageId = DB::table('pipeline_etapas')
                    ->where('pipeline_id', $pipelineId)
                    ->where('nombre', $stageName)
                    ->value('id');

                if (!$stageId) {
                    $stageId = DB::table('pipeline_etapas')->insertGetId([
                        'pipeline_id' => $pipelineId,
                        'nombre' => $stageName,
                        'orden' => $index,
                        'habilitado' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $stageMap[$stageName] = $stageId;
            }

            // 3. Migrate existing opportunities
            $opportunities = DB::table('oportunidad')
                ->whereNull('pipeline_id')
                ->get();

            foreach ($opportunities as $opp) {
                // If the old estado was not a stage name, fallback to 'Borrador'
                $oldEstado = $opp->estado;
                $stageName = in_array($oldEstado, $stages) ? $oldEstado : 'Borrador';
                
                // Get the stage ID
                $stageId = $stageMap[$stageName] ?? $stageMap['Borrador'];

                DB::table('oportunidad')
                    ->where('id', $opp->id)
                    ->update([
                        'pipeline_id' => $pipelineId,
                        'pipeline_etapa_id' => $stageId,
                        'estado' => 'Activa', // Change state column to 'Activa'
                    ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::transaction(function () {
            // We reverse opportunities back to their stage names in the 'estado' column
            $opportunities = DB::table('oportunidad')
                ->whereNotNull('pipeline_id')
                ->whereNotNull('pipeline_etapa_id')
                ->get();

            foreach ($opportunities as $opp) {
                $stageName = DB::table('pipeline_etapas')
                    ->where('id', $opp->pipeline_etapa_id)
                    ->value('nombre');

                if ($stageName) {
                    DB::table('oportunidad')
                        ->where('id', $opp->id)
                        ->update([
                            'pipeline_id' => null,
                            'pipeline_etapa_id' => null,
                            'estado' => $stageName,
                        ]);
                }
            }
        });
    }
};
