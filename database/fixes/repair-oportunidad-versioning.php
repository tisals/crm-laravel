<?php
/**
 * Repair oportunidad versioning data.
 *
 * Background
 * ----------
 * Two incompatible versioning conventions existed in the codebase:
 *   - CSV / legacy importer (used in production for 2834+ rows):
 *       code suffix = " v2" (lowercase v, space)
 *       root version = 0, latest version = highest
 *       parent_id of OLD versions points to the latest
 *       latest row has parent_id = NULL
 *   - Interactive endpoint (CrearVersionOportunidadUseCase, before this fix):
 *       code suffix = "-V2" (uppercase V, hyphen)
 *       root version = 1, latest version = highest
 *       parent_id of NEW versions points to the root
 *
 * After the fix in CrearVersionOportunidadUseCase the project now uses the
 * CSV/legacy convention consistently. This script backfills existing rows
 * so the data matches the new invariant:
 *
 *   For each family (group of rows sharing the same base codigo):
 *     - The non-deleted row with the highest version becomes the latest:
 *         is_latest = 1, estado = 'Activa', parent_id = NULL
 *     - Every other non-deleted row of the same family:
 *         is_latest = 0, estado = 'Inactiva', parent_id = <latest_id>
 *     - Soft-deleted rows are left alone (they stay out of normal queries).
 *
 * Usage
 * -----
 *   # Dry-run (default): prints the families it would touch and the proposed
 *   # post-state, but writes nothing.
 *   php database/fixes/repair-oportunidad-versioning.php
 *
 *   # Apply:
 *   php database/fixes/repair-oportunidad-versioning.php --apply
 *
 *   # Only target a specific family by base codigo (useful for one-off fixes):
 *   php database/fixes/repair-oportunidad-versioning.php --apply --codigo="GC-01-2026-105"
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);
$targetCodigo = null;
foreach ($argv as $arg) {
    if (preg_match('/^--codigo=(.+)$/', $arg, $m)) {
        $targetCodigo = trim($m[1], "\"' ");
    }
}

/**
 * Strip " vN" / "-VN" suffix from a codigo.
 * Centralised in CrearVersionOportunidadUseCase::stripVersionSuffix — duplicated
 * here to avoid coupling a one-off fix script to a class autoload.
 */
$strip = static function (string $codigo): string {
    return trim(preg_replace('/[\s\-]+[vV]\d+$/', '', $codigo) ?? $codigo);
};

echo $apply ? "=== APPLY MODE ===\n\n" : "=== DRY-RUN (no writes) ===\n\n";

// 1. Collect every non-deleted row, ordered to make grouping deterministic.
$rows = DB::table('oportunidad')
    ->select('id', 'codigo', 'version', 'is_latest', 'estado', 'parent_id')
    ->whereNull('deleted_at')
    ->orderBy('id')
    ->get();

// 2. Group by base codigo.
$byFamily = [];
foreach ($rows as $row) {
    $base = $strip($row->codigo);
    if ($targetCodigo && $base !== $targetCodigo) {
        continue;
    }
    $byFamily[$base][] = $row;
}

if (empty($byFamily)) {
    echo "No families matched.\n";
    exit(0);
}

echo 'Families found: '.count($byFamily)."\n\n";

$touchedLatest = 0;
$touchedOthers = 0;
$skippedClean = 0;

foreach ($byFamily as $base => $members) {
    // The latest is the non-deleted row with the highest version number.
    $latest = null;
    foreach ($members as $m) {
        if ($latest === null || (int) $m->version > (int) $latest->version) {
            $latest = $m;
        }
    }

    echo "Family: {$base}  (".count($members)." rows)\n";
    echo "  latest -> id={$latest->id} codigo={$latest->codigo} version={$latest->version}\n";

    // 3a. Update the latest.
    $needsLatestFix = ! $latest->is_latest || $latest->estado !== 'Activa' || $latest->parent_id !== null;
    if ($needsLatestFix) {
        echo "    [latest fix]   is_latest: {$latest->is_latest}->1, estado: {$latest->estado}->Activa, parent_id: ".($latest->parent_id ?? 'NULL')."->NULL\n";
        if ($apply) {
            DB::table('oportunidad')->where('id', $latest->id)->update([
                'is_latest' => 1,
                'estado' => 'Activa',
                'parent_id' => null,
            ]);
        }
        $touchedLatest++;
    } else {
        echo "    [latest ok]\n";
        $skippedClean++;
    }

    // 3b. Update every other row in the family.
    foreach ($members as $m) {
        if ((int) $m->id === (int) $latest->id) {
            continue;
        }
        $needsFix = $m->is_latest || $m->estado === 'Activa' || (int) $m->parent_id !== (int) $latest->id;
        if ($needsFix) {
            echo "    [other fix]    id={$m->id} codigo={$m->codigo} version={$m->version}  is_latest: {$m->is_latest}->0, estado: {$m->estado}->Inactiva, parent_id: ".($m->parent_id ?? 'NULL')."->{$latest->id}\n";
            if ($apply) {
                DB::table('oportunidad')->where('id', $m->id)->update([
                    'is_latest' => 0,
                    'estado' => 'Inactiva',
                    'parent_id' => $latest->id,
                ]);
            }
            $touchedOthers++;
        }
    }
    echo "\n";
}

echo "----- Summary -----\n";
echo "Latest rows fixed:    {$touchedLatest}\n";
echo "Other rows fixed:     {$touchedOthers}\n";
echo "Already-clean rows:   {$skippedClean}\n";
echo $apply ? "Changes applied.\n" : "Dry-run only. Re-run with --apply to write.\n";
