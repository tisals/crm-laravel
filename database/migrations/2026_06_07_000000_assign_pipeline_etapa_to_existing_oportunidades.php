<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Mapping of legacy estado values to pipeline + etapa.
     *
     * Key:   legacy `estado` string value
     * Value: destination `pipeline.codigo` + `pipeline_etapas.nombre`
     */
    private array $mapping = [
        'prospecto' => ['pipeline_codigo' => 'RECUPERACION', 'etapa_nombre' => 'Inicio'],
        'cotizacion' => ['pipeline_codigo' => 'COTIZACION',   'etapa_nombre' => 'Borrador'],
        'negociacion' => ['pipeline_codigo' => 'COTIZACION',   'etapa_nombre' => 'En Negociación'],
        'ganado' => ['pipeline_codigo' => 'COTIZACION',   'etapa_nombre' => 'Ganada'],
        'perdido' => ['pipeline_codigo' => 'COTIZACION',   'etapa_nombre' => 'Perdida'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function () {
            // 1. Ensure pipelines exist (create on the fly if missing)
            $this->ensurePipelineExists('COTIZACION', 'Cotización', [
                'Borrador', 'Enviada', 'Aceptada', 'Rechazada', 'Ganada', 'Perdida',
            ]);
            $this->ensurePipelineExists('RECUPERACION', 'Recuperación', [
                'Inicio', 'Con Cita', 'En Negociación', 'Aprobado', 'Rechazado',
            ]);

            // 2. Cache pipeline + etapa lookups
            $pipelineCache = [];   // codigo => id
            $etapaCache = [];      // pipelineId_nombre => id

            foreach ($this->mapping as $estado => $target) {
                $codigo = $target['pipeline_codigo'];
                $etapaNombre = $target['etapa_nombre'];

                if (! isset($pipelineCache[$codigo])) {
                    $pipelineCache[$codigo] = DB::table('pipelines')
                        ->where('codigo', $codigo)
                        ->value('id');
                }

                $pipelineId = $pipelineCache[$codigo];
                $cacheKey = $pipelineId.'_'.$etapaNombre;

                if (! isset($etapaCache[$cacheKey])) {
                    $etapaCache[$cacheKey] = DB::table('pipeline_etapas')
                        ->where('pipeline_id', $pipelineId)
                        ->where('nombre', $etapaNombre)
                        ->value('id');
                }
            }

            // 3. Compute fallback: first etapa of COTIZACION
            $fallbackPipelineId = $pipelineCache['COTIZACION']
                ?? DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');

            $fallbackEtapaId = DB::table('pipeline_etapas')
                ->where('pipeline_id', $fallbackPipelineId)
                ->orderBy('orden')
                ->value('id');

            // 4. Process rows
            $rows = DB::table('oportunidad')
                ->whereNull('pipeline_etapa_id')
                ->get();

            $migratedCount = 0;

            foreach ($rows as $row) {
                $target = $this->mapping[$row->estado] ?? null;

                if ($target) {
                    $pipelineId = $pipelineCache[$target['pipeline_codigo']] ?? null;
                    $etapaCacheKey = $pipelineId.'_'.$target['etapa_nombre'];
                    $etapaId = $etapaCache[$etapaCacheKey] ?? null;

                    if ($pipelineId && $etapaId) {
                        DB::table('oportunidad')
                            ->where('id', $row->id)
                            ->update([
                                'pipeline_id' => $pipelineId,
                                'pipeline_etapa_id' => $etapaId,
                                'updated_at' => now(),
                            ]);
                        $migratedCount++;

                        continue;
                    }
                }

                // Fallback: use first etapa of COTIZACION
                DB::table('oportunidad')
                    ->where('id', $row->id)
                    ->update([
                        'pipeline_id' => $fallbackPipelineId,
                        'pipeline_etapa_id' => $fallbackEtapaId,
                        'updated_at' => now(),
                    ]);
                $migratedCount++;
            }

            Log::info("PipelineEtapa migration: {$migratedCount} oportunidades migrated.");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse — this is a one-time data backfill.
        // Rolling back means we'd have to know which rows were migrated,
        // which is impractical. Instead, a fresh `migrate:fresh` resets everything.
    }

    /**
     * Ensure a pipeline exists with its etapas.
     * Uses updateOrInsert for idempotency.
     */
    private function ensurePipelineExists(string $codigo, string $nombre, array $etapas): void
    {
        $pipelineId = DB::table('pipelines')->where('codigo', $codigo)->value('id');

        if (! $pipelineId) {
            $pipelineId = DB::table('pipelines')->insertGetId([
                'nombre' => $nombre,
                'codigo' => $codigo,
                'habilitado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($etapas as $orden => $etapaNombre) {
            DB::table('pipeline_etapas')->updateOrInsert(
                [
                    'pipeline_id' => $pipelineId,
                    'nombre' => $etapaNombre,
                ],
                [
                    'orden' => $orden + 1,
                    'habilitado' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
};
