<?php
/**
 * Fix script: recalcula `iva` y `vr_total` en detalle_oportunidad.
 *
 * El importador CSV original calculaba IVA solo para 1 unidad en vez de
 * para toda la línea. Este script recalcula usando el IVA actual del
 * producto asociado.
 *
 * Uso:
 *   php artisan tinker --execute='require "database/fixes/fix-detalle-iva.php";'
 *   o directamente:
 *   php database/fixes/fix-detalle-iva.php [--dry-run] [--apply]
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$isDryRun = ! in_array('--apply', $argv, true);

echo "=== Fix detalle_oportunidad: recalcular IVA + vr_total ===\n";
echo ($isDryRun ? "MODO DRY-RUN (no aplica cambios)\n" : "MODO APPLY (aplicando cambios)\n") . "\n";

// Traer todos los detalles con su producto
$detalles = DB::table('detalle_oportunidad as d')
    ->leftJoin('productos as p', 'p.id', '=', 'd.producto_id')
    ->select(
        'd.id',
        'd.oportunidad_id',
        'd.cantidad',
        'd.vr_unitario',
        'd.iva as old_iva',
        'd.vr_total as old_vr_total',
        'p.iva as producto_iva'
    )
    ->get();

echo "Total detalles: " . count($detalles) . "\n\n";

$fixed = 0;
$unchanged = 0;
$fallback = 0;
$batches = [];

foreach ($detalles as $d) {
    $cantidad = (float) $d->cantidad;
    $vrUnitario = (float) $d->vr_unitario;
    $subtotal = $cantidad * $vrUnitario;

    // Si el producto existe, usar su IVA. Si no, fallback a 19%.
    $ivaPct = $d->producto_iva !== null ? (float) $d->producto_iva : 19.0;
    if ($d->producto_iva === null) {
        $fallback++;
    }

    $ivaCorrecto = round($subtotal * ($ivaPct / 100), 2);
    $totalCorrecto = round($subtotal + $ivaCorrecto, 2);

    $ivaViejo = (float) $d->old_iva;
    $totalViejo = (float) $d->old_vr_total;

    // Diff tolerance 0.01 para evitar updates por redondeo
    if (abs($ivaCorrecto - $ivaViejo) < 0.01 && abs($totalCorrecto - $totalViejo) < 0.01) {
        $unchanged++;
        continue;
    }

    $fixed++;
    if ($fixed <= 5) {
        echo sprintf(
            "id=%d opp=%d cant=%g vr_u=%.2f | old iva=%.2f total=%.2f → new iva=%.2f total=%.2f (iva%%=%g)\n",
            $d->id, $d->oportunidad_id, $cantidad, $vrUnitario,
            $ivaViejo, $totalViejo, $ivaCorrecto, $totalCorrecto, $ivaPct
        );
    }
    if ($fixed === 6) {
        echo "... (más correcciones omitidas en el log)\n";
    }

    if (! $isDryRun) {
        $batches[] = [
            'id' => $d->id,
            'iva' => $ivaCorrecto,
            'vr_total' => $totalCorrecto,
        ];
        if (count($batches) >= 200) {
            DB::transaction(function () use (&$batches) {
                foreach ($batches as $b) {
                    DB::table('detalle_oportunidad')->where('id', $b['id'])->update([
                        'iva' => $b['iva'],
                        'vr_total' => $b['vr_total'],
                    ]);
                }
            });
            $batches = [];
        }
    }
}

if (! $isDryRun && count($batches) > 0) {
    DB::transaction(function () use (&$batches) {
        foreach ($batches as $b) {
            DB::table('detalle_oportunidad')->where('id', $b['id'])->update([
                'iva' => $b['iva'],
                'vr_total' => $b['vr_total'],
            ]);
        }
    });
}

echo "\n=== Resumen ===\n";
echo "Total detalles:   " . count($detalles) . "\n";
echo "Corregidos:       $fixed\n";
echo "Sin cambios:      $unchanged\n";
echo "Sin producto (fallback 19%): $fallback\n";

if ($isDryRun) {
    echo "\nPara aplicar los cambios, ejecutar con --apply:\n";
    echo "  php database/fixes/fix-detalle-iva.php --apply\n";
} else {
    echo "\n✅ Cambios aplicados.\n";
}