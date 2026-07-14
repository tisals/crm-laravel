<?php
/**
 * Reconcile entity assignments for CSV rows WITHOUT dominio.
 *
 * Process (A1):
 *   For each row in oportunidades.csv where dominio is empty:
 *     - Try to match a SHELL (entidad with 0 opps, 0 contactos, sin dominio)
 *       by normalized name. If found, REASSIGN opp + contacto to that shell.
 *     - Else if matches an existing REAL entity (real sin dominio) by normalized
 *       name → do nothing (already correct).
 *     - Else → CREATE new entity, REASSIGN opp + contacto.
 *
 * Process (A2):
 *   DELETE remaining shells (0 opps, 0 contactos, sin dominio).
 *
 * Idempotent: reasigns existing contactos to new entity if needed.
 *
 * Usage:
 *   php database/fixes/reconcile-entities-sin-dominio.php [--dry-run] [--csv=/path/to/oportunidades.csv]
 */

$pdo = new PDO("mysql:host=prod_mariabd;dbname=crm_prod", "sailusdb", getenv("DBPW"));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$dryRun = in_array("--dry-run", $argv);
echo $dryRun ? "=== DRY-RUN ===\n" : "=== LIVE MODE ===\n";

$csvPath = "/var/www/html/database/csv/oportunidades.csv";
foreach ($argv as $a) {
    if (str_starts_with($a, "--csv=")) $csvPath = substr($a, 6);
}
if (!file_exists($csvPath)) {
    die("ERROR: $csvPath not found\n");
}
echo "CSV: $csvPath\n\n";

// ─────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────
function normalizeEntityName(string $name): string {
    $name = strtolower(trim($name));
    $accents = ["á"=>"a","é"=>"e","í"=>"i","ó"=>"o","ú"=>"u","ü"=>"u","ñ"=>"n"];
    $name = strtr($name, $accents);
    $name = preg_replace("/\s+/", " ", $name);
    $name = preg_replace(
        "/\s+(s\.?a\.?s\.?|s\.?a\.?|s\.?a|ltda|ltd|e\.?u\.?l\.?l|limitada|spa|s\.?p\.?a\.?|s\.?r\.?l\.?|cia|cia\.)\.?$/i",
        "",
        $name
    );
    return trim($name);
}

// ─────────────────────────────────────────────────────────
// Step 1: Classify entities
// ─────────────────────────────────────────────────────────

// Shells: dominio IS NULL, 0 opps, 0 contactos
$shells = $pdo->query("
    SELECT e.id, e.nombre
    FROM entidad e
    WHERE (e.dominio IS NULL OR e.dominio = '')
      AND (SELECT COUNT(*) FROM oportunidad WHERE entidad_id = e.id) = 0
      AND (SELECT COUNT(*) FROM contacto WHERE entidad_id = e.id) = 0
")->fetchAll(PDO::FETCH_ASSOC);

$shellsByName = [];
foreach ($shells as $s) {
    $key = normalizeEntityName($s["nombre"]);
    if (!isset($shellsByName[$key])) {
        $shellsByName[$key] = $s["id"];
    }
}

// Reales sin dominio
$reales = $pdo->query("
    SELECT e.id, e.nombre
    FROM entidad e
    WHERE (e.dominio IS NULL OR e.dominio = '')
      AND ((SELECT COUNT(*) FROM oportunidad WHERE entidad_id = e.id) > 0
           OR (SELECT COUNT(*) FROM contacto WHERE entidad_id = e.id) > 0)
")->fetchAll(PDO::FETCH_ASSOC);

$realesByName = [];
foreach ($reales as $r) {
    $key = normalizeEntityName($r["nombre"]);
    if (!isset($realesByName[$key])) {
        $realesByName[$key] = $r["id"];
    }
}

echo "Shells (0 opps, 0 contactos, sin dominio): " . count($shells) . "\n";
echo "Reales sin dominio: " . count($reales) . "\n";

// Preload contacto index (only non-empty emails)
$contactosByEmail = [];
foreach ($pdo->query("SELECT id, entidad_id, email_contacto FROM contacto WHERE email_contacto IS NOT NULL AND email_contacto != ''")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $contactosByEmail[$c["email_contacto"]] = $c;
}

// Preload opp index
$oppsByCodigo = [];
foreach ($pdo->query("SELECT id, codigo FROM oportunidad")->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $oppsByCodigo[$o["codigo"]] = $o["id"];
}

echo "Oportunidades en BD: " . count($oppsByCodigo) . "\n";
echo "Contactos con email: " . count($contactosByEmail) . "\n\n";

// ─────────────────────────────────────────────────────────
// Step 2: Process CSV — only rows WITHOUT dominio
// ─────────────────────────────────────────────────────────

$fh = fopen($csvPath, "r");
$header = fgetcsv($fh, 0, ";");

$stats = [
    "reassigned_shell" => 0,
    "real_match" => 0,
    "new_entity" => 0,
    "skipped_with_dom" => 0,
    "no_opp" => 0,
    "failed" => 0,
];
$csvCodigosSeen = [];

while (($row = fgetcsv($fh, 0, ";")) !== false) {
    if (count($row) < 14) continue;

    $codigo     = trim($row[0] ?? '');
    $empresa    = trim($row[13] ?? '');
    $dominioRaw = trim($row[14] ?? '');
    $email      = trim($row[9] ?? '');

    // Only process rows WITHOUT dominio
    if ($dominioRaw !== "") {
        $stats["skipped_with_dom"]++;
        continue;
    }
    if (! $codigo || ! $empresa) continue;

    // Skip duplicates within CSV
    if (isset($csvCodigosSeen[$codigo])) {
        continue;
    }
    $csvCodigosSeen[$codigo] = true;

    $empresaNorm = normalizeEntityName($empresa);
    $oppId = $oppsByCodigo[$codigo] ?? null;

    if (! $oppId) {
        $stats["no_opp"]++;
        continue;
    }

    // Try shell first
    $targetId = $shellsByName[$empresaNorm] ?? null;

    // Else try real existing entity
    if (! $targetId) {
        $targetId = $realesByName[$empresaNorm] ?? null;
        if ($targetId) {
            $stats["real_match"]++;
            continue;
        }
    }

    // Else create new entity
    if (! $targetId) {
        try {
            if (! $dryRun) {
                $stmt = $pdo->prepare("
                    INSERT INTO entidad (nombre, nombre_comercial, dominio, estado, created_at, updated_at)
                    VALUES (?, ?, NULL, 'Activo', NOW(), NOW())
                ");
                $stmt->execute([$empresa, $empresa]);
                $targetId = $pdo->lastInsertId();
                $realesByName[$empresaNorm] = $targetId;
            }
            $stats["new_entity"]++;
        } catch (Exception $e) {
            $stats["failed"]++;
            echo "  FAIL creating entity for {$codigo}: " . $e->getMessage() . "\n";
            continue;
        }
    } else {
        $stats["reassigned_shell"]++;
        // Mark shell as used so we don't reassign again
        unset($shellsByName[$empresaNorm]);
    }

    if (! $dryRun) {
        // Reassign opportunity
        try {
            $pdo->prepare("UPDATE oportunidad SET entidad_id = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$targetId, $oppId]);
        } catch (Exception $e) {
            $stats["failed"]++;
            echo "  FAIL updating opp {$codigo}: " . $e->getMessage() . "\n";
            continue;
        }

        // Handle contacto
        if ($email !== "") {
            $existing = $contactosByEmail[$email] ?? null;
            if ($existing) {
                // Contacto exists — update entity if needed
                if ((int) $existing["entidad_id"] !== (int) $targetId) {
                    try {
                        $pdo->prepare("UPDATE contacto SET entidad_id = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$targetId, $existing["id"]]);
                    } catch (Exception $e) {
                        // Duplicate key — skip
                    }
                }
            } else {
                // Create new contacto
                try {
                    $nombre = trim(explode("\n", trim($row[5] ?? ''))[0] ?? '');
                    $pdo->prepare("
                        INSERT INTO contacto (entidad_id, nombres, apellidos, email_contacto, estado, created_at, updated_at)
                        VALUES (?, ?, ' ', ?, 'Activo', NOW(), NOW())
                    ")->execute([$targetId, $nombre ?: 'Sin nombre', $email]);
                    $contactosByEmail[$email] = ["id" => $pdo->lastInsertId(), "entidad_id" => $targetId];
                } catch (Exception $e) {
                    // ignore duplicate
                }
            }
        }
    }
}
fclose($fh);

echo "=== A1 — Process sin-dominio ===\n";
foreach ($stats as $k => $v) {
    echo "  $k: $v\n";
}

// ─────────────────────────────────────────────────────────
// Step 3 (A2): Delete remaining shells
// ─────────────────────────────────────────────────────────

$remaining = $pdo->query("
    SELECT e.id, e.nombre
    FROM entidad e
    WHERE (e.dominio IS NULL OR e.dominio = '')
      AND (SELECT COUNT(*) FROM oportunidad WHERE entidad_id = e.id) = 0
      AND (SELECT COUNT(*) FROM contacto WHERE entidad_id = e.id) = 0
")->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== A2 — Shells huérfanas (a borrar) ===\n";
echo "Total: " . count($remaining) . "\n";
foreach ($remaining as $s) {
    echo "  [SHELL] id={$s['id']} {$s['nombre']}\n";
}

if (! $dryRun && count($remaining) > 0) {
    $ids = array_column($remaining, "id");
    $ph = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $pdo->prepare("DELETE FROM entidad WHERE id IN ($ph)");
    $stmt->execute($ids);
    echo "  → Borradas: " . count($ids) . "\n";
}