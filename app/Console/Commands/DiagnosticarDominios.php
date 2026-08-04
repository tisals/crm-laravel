<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * crm:diagnosticar-dominios
 *
 * LISTA (no modifica) las entidades con dominios potencialmente mal cargados.
 *
 * USO:
 *   php artisan crm:diagnosticar-dominios
 *   php artisan crm:diagnosticar-dominios --export-csv=diagnostico.csv
 *
 * IMPORTANTE: SOLO LISTA. NO modifica la BD. Cero riesgo operacional.
 */
class DiagnosticarDominios extends Command
{
    protected $signature = 'crm:diagnosticar-dominios {--export-csv= : Path CSV de salida}';

    protected $description = 'Lista (sin modificar) entidades con dominios potencialmente mal cargados';

    public function handle()
    {
        $exportPath = $this->option('export-csv');

        $this->info('Diagnosticando dominios de entidades (solo lectura)...');
        $this->newLine();

        // Conteos básicos
        $total = (int) DB::table('entidad')->whereNull('deleted_at')->count();
        $conDominio = (int) DB::table('entidad')
            ->whereNull('deleted_at')
            ->whereNotNull('dominio')
            ->where('dominio', '!=', '')
            ->count();
        $sinDominio = (int) DB::table('entidad')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('dominio')->orWhere('dominio', '');
            })
            ->count();

        // 1. Dominios compartidos
        $this->info('=== 1. DOMINIOS COMPARTIDOS (2+ entidades con mismo dominio) ===');
        $compartidos = DB::select('SELECT dominio, COUNT(*) as total FROM entidad WHERE deleted_at IS NULL AND dominio IS NOT NULL AND dominio != "" GROUP BY dominio HAVING total > 1 ORDER BY total DESC, dominio ASC');
        $this->info('Total: ' . count($compartidos) . ' dominios compartidos');
        foreach (array_slice($compartidos, 0, 20) as $c) {
            $this->line('  ' . $c->dominio . ' → ' . $c->total . ' entidades');
        }
        if (count($compartidos) > 20) {
            $this->line('  ... y ' . (count($compartidos) - 20) . ' mas');
        }
        $this->newLine();

        // 2. Sin dominio
        $this->info('=== 2. ENTIDADES SIN DOMINIO ===');
        $this->info('Total: ' . $sinDominio . ' entidades sin dominio');
        $sinDomList = DB::select('SELECT id, nombre, tipo_id, estado FROM entidad WHERE deleted_at IS NULL AND (dominio IS NULL OR dominio = "") ORDER BY nombre ASC LIMIT 20');
        foreach ($sinDomList as $e) {
            $this->line('  #' . $e->id . ' ' . substr($e->nombre, 0, 60) . ' [' . ($e->tipo_id ?? 'null') . ']');
        }
        if ($sinDominio > 20) {
            $this->line('  ... y ' . ($sinDominio - 20) . ' mas');
        }
        $this->newLine();

        // 3. Sospechosos heurísticos (nombre no contiene keyword del dominio)
        $this->info('=== 3. SOSPECHOSOS (nombre no contiene keyword del dominio) ===');
        $todos = DB::select('SELECT id, nombre, dominio, estado FROM entidad WHERE deleted_at IS NULL AND dominio IS NOT NULL AND dominio != "" ORDER BY nombre ASC');
        $sospechosos = [];
        foreach ($todos as $e) {
            $domainMain = strtolower(explode('.', $e->dominio)[0]);
            $domainClean = str_replace('-', '', $domainMain);
            $nameClean = strtolower(str_replace(['-', '.', ',', ' '], '', $e->nombre));
            if (strpos($nameClean, $domainClean) === false) {
                $sospechosos[] = $e;
            }
        }
        $this->info('Total: ' . count($sospechosos) . ' entidades sospechosas (heuristica simple)');
        foreach (array_slice($sospechosos, 0, 20) as $e) {
            $this->line('  #' . $e->id . ' ' . substr($e->nombre, 0, 50) . ' → ' . $e->dominio);
        }
        if (count($sospechosos) > 20) {
            $this->line('  ... y ' . (count($sospechosos) - 20) . ' mas');
        }
        $this->newLine();

        // Resumen
        $this->table(['Metrica', 'Valor'], [
            ['Entidades activas', $total],
            ['Con dominio', $conDominio],
            ['Sin dominio', $sinDominio],
            ['Dominios compartidos', count($compartidos)],
            ['Sospechosos heuristicos', count($sospechosos)],
        ]);

        if ($exportPath) {
            $this->exportarCsv($exportPath, $compartidos, $sinDomList, $sospechosos);
        } else {
            $this->newLine();
            $this->info('Tip: pasa --export-csv=archivo.csv para exportar el reporte completo.');
        }

        $this->newLine();
        $this->info('Diagnostico completado. Esta herramienta SOLO LISTA, no modifica nada.');

        return 0;
    }

    private function exportarCsv($filepath, $compartidos, $sinDomList, $sospechosos)
    {
        $fp = fopen($filepath, 'w');
        if (! $fp) {
            $this->error('No se pudo abrir el archivo: ' . $filepath);
            return;
        }

        fputcsv($fp, ['=== DOMINIOS COMPARTIDOS ===']);
        fputcsv($fp, ['dominio', 'total_entidades']);
        foreach ($compartidos as $c) {
            fputcsv($fp, [$c->dominio, $c->total]);
        }
        fputcsv($fp, []);

        fputcsv($fp, ['=== ENTIDADES SIN DOMINIO (muestra 20) ===']);
        fputcsv($fp, ['entidad_id', 'nombre', 'tipo_id', 'estado']);
        foreach ($sinDomList as $e) {
            fputcsv($fp, [$e->id, $e->nombre, $e->tipo_id ?? '', $e->estado]);
        }
        fputcsv($fp, []);

        fputcsv($fp, ['=== SOSPECHOSOS (muestra 20) ===']);
        fputcsv($fp, ['entidad_id', 'nombre', 'dominio', 'estado']);
        foreach ($sospechosos as $e) {
            fputcsv($fp, [$e->id, $e->nombre, $e->dominio, $e->estado]);
        }

        fclose($fp);
        $this->newLine();
        $this->info('Reporte exportado a: ' . $filepath);
    }
}
