<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refactor the Cotización pipeline to use STABLE codigos instead of mutable labels.
 *
 *   Before: 6 stages (Ganada, Perdida, Aceptada, Rechazada, Enviada, Borrador) + 4 garbage
 *           stages from old migrations (Enviado, En Negociación, Aprobado, Rechazado).
 *
 *   After:  5 canonical stages identified by codigo (label parametrizable):
 *
 *           codigo          | default label
 *           ----------------+-----------------
 *           BORRADOR        | Borrador
 *           ENVIADA         | Enviada
 *           EN_NEGOCIACION  | En negociación
 *           ACEPTADA        | Aceptada
 *           RECHAZADA       | Rechazada
 *
 *   The codigo is the stable identifier used by application code (Observer, UseCases,
 *   Dashboard). The label (`nombre`) is the human-readable name, editable without
 *   breaking the application.
 *
 *   Migration of existing opportunities:
 *     - Ops on legacy stages (Enviado, En Negociación, Aprobado, Rechazado,
 *       Ganada, Perdida) are remapped to the closest canonical stage.
 *     - All legacy stages are then deleted.
 *
 *   Note: this migration runs AFTER the Cotización pipeline has been seeded
 *   by PipelineSeeder. Re-running is safe (idempotent).
 */
return new class extends Migration
{
    private const LEGACY_TO_CANONICAL = [
        'Borrador' => 'BORRADOR',
        'Enviada' => 'ENVIADA',
        'Enviado' => 'ENVIADA',
        'En negociación' => 'EN_NEGOCIACION',
        'En Negociación' => 'EN_NEGOCIACION',
        'Aceptada' => 'ACEPTADA',
        'Aprobado' => 'ACEPTADA',
        'Rechazada' => 'RECHAZADA',
        'Rechazado' => 'RECHAZADA',
        'Ganada' => 'ACEPTADA',
        'Perdida' => 'RECHAZADA',
    ];

    private const CANONICAL_STAGES = [
        'BORRADOR' => 0,
        'ENVIADA' => 1,
        'EN_NEGOCIACION' => 2,
        'ACEPTADA' => 3,
        'RECHAZADA' => 4,
    ];

    public function up(): void
    {
        // DDL: add codigo column OUTSIDE any transaction (DDL causes implicit COMMIT in MySQL).
        if (! Schema::hasColumn('pipeline_etapas', 'codigo')) {
            Schema::table('pipeline_etapas', function (Blueprint $table) {
                $table->string('codigo', 50)->nullable()->after('nombre');
            });
        }

        $pipelineId = DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');
        if (! $pipelineId) {
            return;
        }

        // DML: data migration in a transaction.
        DB::transaction(function () use ($pipelineId) {
            $now = now();

            // Assign codigo to all existing stages (including legacy)
            $existingEtapas = DB::table('pipeline_etapas')
                ->where('pipeline_id', $pipelineId)
                ->get();
            foreach ($existingEtapas as $etapa) {
                $canonicalCodigo = self::LEGACY_TO_CANONICAL[$etapa->nombre] ?? null;
                if ($canonicalCodigo) {
                    DB::table('pipeline_etapas')
                        ->where('id', $etapa->id)
                        ->update(['codigo' => $canonicalCodigo, 'updated_at' => $now]);
                }
            }

            // Ensure each canonical stage exists with correct codigo + nombre + orden
            foreach (self::CANONICAL_STAGES as $codigo => $orden) {
                $nombre = $this->nombreForCodigo($codigo);

                $existingId = DB::table('pipeline_etapas')
                    ->where('pipeline_id', $pipelineId)
                    ->where('nombre', $nombre)
                    ->value('id');

                if ($existingId) {
                    DB::table('pipeline_etapas')
                        ->where('id', $existingId)
                        ->update([
                            'codigo' => $codigo,
                            'orden' => $orden,
                            'habilitado' => true,
                            'updated_at' => $now,
                        ]);
                } else {
                    DB::table('pipeline_etapas')->insert([
                        'pipeline_id' => $pipelineId,
                        'codigo' => $codigo,
                        'nombre' => $nombre,
                        'orden' => $orden,
                        'habilitado' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            // Reassign ops from legacy stages to canonical ones
            foreach (self::LEGACY_TO_CANONICAL as $legacyNombre => $canonicalCodigo) {
                $etapaId = DB::table('pipeline_etapas')
                    ->where('pipeline_id', $pipelineId)
                    ->where('nombre', $legacyNombre)
                    ->value('id');
                $canonicalId = DB::table('pipeline_etapas')
                    ->where('pipeline_id', $pipelineId)
                    ->where('codigo', $canonicalCodigo)
                    ->value('id');

                if ($etapaId && $canonicalId && $etapaId !== $canonicalId) {
                    DB::table('oportunidad')
                        ->where('pipeline_etapa_id', $etapaId)
                        ->update(['pipeline_etapa_id' => $canonicalId, 'updated_at' => $now]);
                }
            }

            // Delete legacy stages (anything not in the canonical 5)
            $canonicalNombres = array_map(fn ($c) => $this->nombreForCodigo($c), array_keys(self::CANONICAL_STAGES));
            DB::table('pipeline_etapas')
                ->where('pipeline_id', $pipelineId)
                ->whereNotIn('nombre', $canonicalNombres)
                ->delete();
        });
    }

    public function down(): void
    {
        $pipelineId = DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');
        if (! $pipelineId) {
            return;
        }

        DB::transaction(function () use ($pipelineId) {
            $now = now();

            // Restore Ganada/Perdida stages
            DB::table('pipeline_etapas')->insert([
                ['pipeline_id' => $pipelineId, 'codigo' => 'GANADA', 'nombre' => 'Ganada', 'orden' => 4, 'habilitado' => true, 'created_at' => $now, 'updated_at' => $now],
                ['pipeline_id' => $pipelineId, 'codigo' => 'PERDIDA', 'nombre' => 'Perdida', 'orden' => 5, 'habilitado' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);

            // Remove 'En negociación' stage
            DB::table('pipeline_etapas')
                ->where('pipeline_id', $pipelineId)
                ->where('codigo', 'EN_NEGOCIACION')
                ->delete();

            // Restore 6-stage order (BORRADOR, ENVIADA, ACEPTADA, RECHAZADA)
            $legacyOrder = ['BORRADOR' => 0, 'ENVIADA' => 1, 'ACEPTADA' => 2, 'RECHAZADA' => 3];
            foreach ($legacyOrder as $codigo => $orden) {
                DB::table('pipeline_etapas')
                    ->where('pipeline_id', $pipelineId)
                    ->where('codigo', $codigo)
                    ->update(['orden' => $orden, 'updated_at' => $now]);
            }
        });
    }

    private function nombreForCodigo(string $codigo): string
    {
        return match ($codigo) {
            'BORRADOR' => 'Borrador',
            'ENVIADA' => 'Enviada',
            'EN_NEGOCIACION' => 'En negociación',
            'ACEPTADA' => 'Aceptada',
            'RECHAZADA' => 'Rechazada',
            default => $codigo,
        };
    }
};
