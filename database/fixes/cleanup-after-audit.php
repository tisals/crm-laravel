<?php
/**
 * Cleanup script for data integrity issues found by audit-data-integrity.php
 *
 * Priority 1: Reasign 7 opps with wrong entity (vs CSV)
 * Priority 2: Move contactos from Tecnoinnsoft catch-all to correct entities
 * Priority 3: Delete confirmed shells (0 opps, 0 contactos, dominio NULL)
 *
 * Usage:
 *   php database/fixes/cleanup-after-audit.php [--dry-run] [--apply]
 */

$pdo = new PDO("mysql:host=prod_mariabd;dbname=crm_prod", "sailusdb", getenv("DBPW"));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$apply = in_array("--apply", $argv);
$dryRun = ! $apply;
echo $dryRun ? "=== DRY-RUN (no changes) ===\n" : "=== LIVE MODE (changes applied) ===\n\n";

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
// STEP 1: Reasign 7 opps with wrong entity (vs CSV)
// ─────────────────────────────────────────────────────────
echo "=== STEP 1: Reasign 7 opps with wrong entity ===\n\n";

$csvPath = "/var/www/html/database/csv/oportunidades.csv";
if (! file_exists($csvPath)) {
    echo "WARNING: CSV not found at $csvPath, skipping step 1\n\n";
} else {
    // Build canonical map: codigo -> normalized empresa name
    $csvByCodigo = [];
    $fh = fopen($csvPath, "r");
    $header = fgetcsv($fh, 0, ";");
    while (($row = fgetcsv($fh, 0, ";")) !== false) {
        if (count($row) < 14) continue;
        $codigo = trim($row[0] ?? '');
        $empresa = trim($row[13] ?? '');
        if (! $codigo || ! $empresa) continue;
        if (! isset($csvByCodigo[$codigo])) {
            $csvByCodigo[$codigo] = $empresa;
        }
    }
    fclose($fh);

    // Find opps with mismatched entity name vs CSV
    $stmt = $pdo->query("
        SELECT o.id, o.codigo, o.entidad_id, e.nombre AS entidad_actual
        FROM oportunidad o
        JOIN entidad e ON o.entidad_id = e.id
    ");
    $mismatched = [];
    foreach ($stmt as $r) {
        if (! isset($csvByCodigo[$r["codigo"]])) continue;
        $csvName = normalizeEntityName($csvByCodigo[$r["codigo"]]);
        $dbName = normalizeEntityName($r["entidad_actual"]);
        if ($csvName !== $dbName) {
            $mismatched[] = $r;
        }
    }

    if (empty($mismatched)) {
        echo "(no mismatches found)\n\n";
    } else {
        $fixed = 0;
        foreach ($mismatched as $r) {
            $codigo = $r["codigo"];
            $csvEmpr = $csvByCodigo[$codigo];

            // Find or create the canonical entity
            $csvNorm = normalizeEntityName($csvEmpr);
            $existing = $pdo->prepare("SELECT id FROM entidad WHERE LOWER(TRIM(nombre)) = ? OR LOWER(TRIM(REGEXP_REPLACE(nombre, '\\\\s+(s\\\\.?a\\\\.?s\\\\.?|ltda|cia|spa)\\\\\\\\s*\\\\.?$', ''))) = ? LIMIT 1");
            $existing->execute([strtolower($csvEmpr), $csvNorm]);
            $targetId = $existing->fetchColumn();

            if (! $targetId) {
                // Create new entity
                if (! $dryRun) {
                    $insert = $pdo->prepare("
                        INSERT INTO entidad (nombre, nombre_comercial, estado, created_at, updated_at)
                        VALUES (?, ?, 'Activo', NOW(), NOW())
                    ");
                    $insert->execute([$csvEmpr, $csvEmpr]);
                    $targetId = $pdo->lastInsertId();
                } else {
                    $targetId = "NEW";
                }
            }

            $opStr = $r["id"];
            $oldStr = $r["entidad_actual"];
            if ($dryRun) {
                echo "  [DRY] opp#{$opStr} {$codigo}: {$oldStr} → {$csvEmpr} (new id: {$targetId})\n";
            } else {
                $pdo->prepare("UPDATE oportunidad SET entidad_id = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$targetId, $opStr]);
                echo "  opp#{$opStr} {$codigo}: {$oldStr} → {$csvEmpr} (id {$targetId})\n";
            }
            $fixed++;
        }
        echo "\n  Total: $fixed opps reasignadas\n\n";
    }
}

// ─────────────────────────────────────────────────────────
// STEP 2: Move contactos from Tecnoinnsoft (id 128) to correct entities
// ─────────────────────────────────────────────────────────
echo "=== STEP 2: Move Tecnoinnsoft catch-all contactos ===\n\n";

$tecnoinnsoftId = 128;

// Find emails @X in Tecnoinnsoft and target entity X
$rows = $pdo->query("
    SELECT c.id, c.email_contacto, SUBSTRING_INDEX(c.email_contacto, '@', -1) AS email_domain
    FROM contacto c
    WHERE c.entidad_id = {$tecnoinnsoftId}
      AND c.email_contacto LIKE '%@%'
      AND c.email_contacto NOT LIKE '%@gmail.%'
      AND c.email_contacto NOT LIKE '%@hotmail.%'
      AND c.email_contacto NOT LIKE '%@yahoo.%'
      AND c.email_contacto NOT LIKE '%@outlook.%'
      AND c.email_contacto NOT LIKE '%@live.%'
    GROUP BY c.id
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "(no Tecnoinnsoft contactos to move)\n\n";
} else {
    $moved = 0;
    foreach ($rows as $r) {
        $domain = $r["email_domain"];

        // Find target entity with matching dominio
        $target = $pdo->prepare("SELECT id, nombre FROM entidad WHERE dominio = ? LIMIT 1");
        $target->execute([$domain]);
        $targetEntity = $target->fetch(PDO::FETCH_ASSOC);

        if (! $targetEntity) {
            echo "  SKIP contacto[{$r['id']}] {$r['email_contacto']} (no entity with dominio '{$domain}')\n";
            continue;
        }

        $targetId = $targetEntity["id"];

        if ($dryRun) {
            echo "  [DRY] contacto[{$r['id']}] {$r['email_contacto']}: Tecnoinnsoft → {$targetEntity['nombre']} (id {$targetId})\n";
        } else {
            try {
                $pdo->prepare("UPDATE contacto SET entidad_id = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$targetId, $r["id"]]);
                echo "  contacto[{$r['id']}] {$r['email_contacto']}: Tecnoinnsoft → {$targetEntity['nombre']} (id {$targetId})\n";
                $moved++;
            } catch (Exception $e) {
                echo "  FAIL contacto[{$r['id']}]: " . $e->getMessage() . "\n";
            }
        }
    }
    echo "\n  Total: $moved contactos movidos\n\n";
}

// ─────────────────────────────────────────────────────────
// STEP 3: Delete confirmed shells (0 opps, 0 contactos, dominio NULL)
// ─────────────────────────────────────────────────────────
echo "=== STEP 3: Delete confirmed shells ===\n\n";

$shells = $pdo->query("
    SELECT e.id, e.nombre
    FROM entidad e
    WHERE (e.dominio IS NULL OR e.dominio = '')
      AND (SELECT COUNT(*) FROM oportunidad WHERE entidad_id = e.id) = 0
      AND (SELECT COUNT(*) FROM contacto WHERE entidad_id = e.id) = 0
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($shells)) {
    echo "(no shells to delete)\n";
} else {
    echo "Shells encontradas: " . count($shells) . "\n";
    if ($dryRun) {
        echo "(no se borra nada en dry-run)\n";
        // List first 20
        foreach (array_slice($shells, 0, 20) as $s) {
            echo "  [{$s['id']}] {$s['nombre']}\n";
        }
    } else {
        $ids = array_column($shells, "id");
        $ph = implode(",", array_fill(0, count($ids), "?"));
        $stmt = $pdo->prepare("DELETE FROM entidad WHERE id IN ($ph)");
        $stmt->execute($ids);
        echo "  → Borradas: " . count($ids) . "\n";
    }
}

echo "\n=== FIN DE CLEANUP ===\n";
if ($dryRun) {
    echo "Pasaste --dry-run (default). Para aplicar: php cleanup-after-audit.php --apply\n";
}