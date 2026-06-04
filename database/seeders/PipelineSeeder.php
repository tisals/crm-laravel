<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PipelineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Ingest pipelines
        $llegadaId = DB::table('pipelines')->insertGetId([
            'nombre' => 'Llegada',
            'codigo' => 'llegada',
            'habilitado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rescateId = DB::table('pipelines')->insertGetId([
            'nombre' => 'Rescate',
            'codigo' => 'rescate',
            'habilitado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Ingest stages for Llegada
        $llegadaStages = ['Borrador', 'Enviada', 'Aceptada', 'Rechazada', 'Ganada', 'Perdida'];
        foreach ($llegadaStages as $index => $stage) {
            DB::table('pipeline_etapas')->insert([
                'pipeline_id' => $llegadaId,
                'nombre' => $stage,
                'orden' => $index,
                'habilitado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Ingest stages for Rescate
        $rescateStages = ['Rescate_Inicial', 'Rescate_En_Progreso', 'Rescate_Exitoso', 'Rescate_Fallido'];
        foreach ($rescateStages as $index => $stage) {
            DB::table('pipeline_etapas')->insert([
                'pipeline_id' => $rescateId,
                'nombre' => $stage,
                'orden' => $index,
                'habilitado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
