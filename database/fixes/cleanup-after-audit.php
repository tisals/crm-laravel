<?php
/**
 * Cleanup script for data integrity issues found by audit-data-integrity.php
 *
 * Improved algorithm (v2):
 *   1. Reasign opps to CSV canonical entity (normalized match)
 *   2. Move ALL opps from wrong entities when consolidating
 *   3. Delete EMPTY similar entities (typos, duplicates)
 *   4. Don't create new entity if current has data
 *   5. Delete empty current entity only if no similar match exists
 *
 * Usage:
 *   php database/fixes/cleanup-after-audit.php [--dry-run] [--apply]
 */

$pdo = new PDO(
    "mysql:host=" . (getenv("DB_HOST") ?: "prod_mariabd") . ";dbname=" . (getenv("DB_NAME") ?: "crm_prod"),
    getenv("DB_USER") ?: "sailusdb",
    getenv("DBPW")
);
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

/**
 * Find entities with normalized name similar to $csvName.
 * Returns entities that have BOTH: lower(name) match OR lower(normalized) match.
 * Excludes the entity with id $excludeId.
 */
function findSimilarEntities(PDO $pdo, string $csvName, string $csvNorm, int $excludeId): array {
    $lowerName = strtolower($csvName);
    $stmt = $pdo->prepare("
        SELECT id, nombre,
               (SELECT COUNT(*) FROM oportunidad WHERE entidad_id = e.id) AS opps,
               (SELECT COUNT(*) FROM contacto WHERE entidad_id = e.id) AS contactos
        FROM entidad e
        WHERE (LOWER(TRIM(nombre)) = ?
            OR LOWER(TRIM(REGEXP_REPLACE(nombre, '\\\\s+(s\\\\.?a\\\\.?s\\\\.?|ltda|cia|spa)\\\\\\\\s*\\\\.?$', ''))) = ?)
          AND e.id != ?
    ");
    $stmt->execute([$lowerName, $csvNorm, $excludeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Move ALL opps from $fromEntityId to $toEntityId.
 */
function moveAllOpps(PDO $pdo, int $fromEntityId, int $toEntityId): int {
    if ($fromEntityId === $toEntityId) return 0;
    $stmt = $pdo->prepare("UPDATE oportunidad SET entidad_id = ?, updated_at = NOW() WHERE entidad_id = ?");
    $stmt->execute([$toEntityId, $fromEntityId]);
    return $stmt->rowCount();
}

// ─────────────────────────────────────────────────────────
// STEP 1: Reasign 311 opps with wrong entity (vs CSV)
// ─────────────────────────────────────────────────────────
echo "=== STEP 1: Reasign opps with wrong entity ===\n\n";

$csvPath = "/var/www/html/database/csv/oportunidades.csv";
if (! file_exists($csvPath)) {
    echo "WARNING: CSV not found at $csvPath, skipping step 1\n\n";
} else {
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

    // Find all opps in DB
    $stmt = $pdo->query("SELECT o.id, o.codigo, o.entidad_id, e.nombre AS entidad_actual FROM oportunidad o JOIN entidad e ON o.entidad_id = e.id");
    $allOpps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $moved = 0;
    $consolidated = []; // entities deleted
    $reassignedOps = []; // ops reasigned

    foreach ($allOpps as $r) {
        $codigo = $r["codigo"];
        if (! isset($csvByCodigo[$codigo])) continue;
        $csvEmpr = $csvByCodigo[$codigo];
        $csvNorm = normalizeEntityName($csvEmpr);
        $dbNorm = normalizeEntityName($r["entidad_actual"]);

        // If normalized names match, the opp is already correctly assigned
        if ($csvNorm === $dbNorm) continue;

        // Find similar entities (excluding current)
        $similars = findSimilarEntities($pdo, $csvEmpr, $csvNorm, $r["entidad_id"]);

        // Decide target entity
        $targetId = null;
        $deleteEntityIds = [];

        if (!empty($similars)) {
            // Pick canonical: prefer exact name match, then entity with most data
            usort($similars, function ($a, $b) {
                $aScore = (strtolower(trim($a["nombre"])) === strtolower(trim($GLOBALS["_csvName"] ?? "")) ? 100 : 0)
                    + ($a["opps"] + $a["contactos"]);
                $bScore = (strtolower(trim($b["nombre"])) === strtolower(trim($GLOBALS["_csvName"] ?? "")) ? 100 : 0)
                    + ($b["opps"] + $b["contactos"]);
                return $bScore <=> $aScore;
            });
            $canonical = $similars[0];
            $targetId = $canonical["id"];

            // Move ALL opps from other similar entities to canonical
            // (handles case where wrong entity has multiple opps to consolidate)
            foreach (array_slice($similars, 1) as $other) {
                $movedCount = moveAllOpps($pdo, $other["id"], $targetId);
                if ($movedCount > 0) {
                    $reassignedOps[] = "{$movedCount} opps from '{$other['nombre']}' → '{$canonical['nombre']}'";
                }
                // If other is empty (0 opps, 0 contactos), delete it
                if ($other["opps"] == 0 && $other["contactos"] == 0) {
                    if (! $dryRun) {
                        $pdo->prepare("DELETE FROM entidad WHERE id = ?")->execute([$other["id"]]);
                    }
                    $consolidated[] = "{$other['nombre']} (id {$other['id']})";
                }
            }
        } else {
            // No similar entity. Check if current entity is empty
            $current = $pdo->prepare("
                SELECT
                    (SELECT COUNT(*) FROM oportunidad WHERE entidad_id = ?) AS opps,
                    (SELECT COUNT(*) FROM contacto WHERE entidad_id = ?) AS contactos
            ");
            $current->execute([$r["entidad_id"], $r["entidad_id"]]);
            $curr = $current->fetch(PDO::FETCH_ASSOC);

            if ($curr["opps"] == 0 && $curr["contactos"] == 0) {
                // Current is empty, delete it and create new
                if (! $dryRun) {
                    $pdo->prepare("DELETE FROM entidad WHERE id = ?")->execute([$r["entidad_id"]]);
                }
                $consolidated[] = "{$r['entidad_actual']} (id {$r['entidad_id']})";
            }

            // Create new entity with canonical name
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

        // Reasign this opp
        if ($dryRun) {
            $newName = $similars[0]["nombre"] ?? $csvEmpr;
            echo "  [DRY] opp#{$r['id']} {$codigo}: '{$r['entidad_actual']}' (id {$r['entidad_id']}) → " . ($targetId === "NEW" ? "'{$newName}' (new)" : "'{$newName}' (id {$targetId})") . "\n";
        } else {
            $pdo->prepare("UPDATE oportunidad SET entidad_id = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$targetId, $r["id"]]);
        }
        $moved++;
    }

    echo "\n  Total opps reasignadas: $moved\n";
    echo "  Entidades consolidadas/eliminadas: " . count($consolidated) . "\n";
    if (! empty($consolidated)) {
        echo "    - " . implode("\n    - ", array_slice($consolidated, 0, 20)) . "\n";
        if (count($consolidated) > 20) echo "    ... y " . (count($consolidated) - 20) . " más\n";
    }
    echo "\n";
}

// ─────────────────────────────────────────────────────────
// STEP 2: Move Tecnoinnsoft catch-all contactos
// ─────────────────────────────────────────────────────────
echo "=== STEP 2: Move Tecnoinnsoft catch-all contactos ===\n\n";

$tecnoinnsoftId = 128;
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
        $target = $pdo->prepare("SELECT id, nombre FROM entidad WHERE dominio = ? LIMIT 1");
        $target->execute([$domain]);
        $targetEntity = $target->fetch(PDO::FETCH_ASSOC);

        if (! $targetEntity) {
            echo "  SKIP contacto[{$r['id']}] {$r['email_contacto']} (no entity with dominio '{$domain}')\n";
            continue;
        }

        $targetId = $targetEntity["id"];
        if ($dryRun) {
            echo "  [DRY] contacto[{$r['id']}] {$r['email_contacto']}: Tecnoinnsoft → '{$targetEntity['nombre']}' (id {$targetId})\n";
        } else {
            try {
                $pdo->prepare("UPDATE contacto SET entidad_id = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$targetId, $r["id"]]);
                echo "  contacto[{$r['id']}] {$r['email_contacto']}: Tecnoinnsoft → '{$targetEntity['nombre']}' (id {$targetId})\n";
                $moved++;
            } catch (Exception $e) {
                echo "  FAIL contacto[{$r['id']}]: " . $e->getMessage() . "\n";
            }
        }
    }
    echo "\n  Total: $moved contactos movidos\n\n";
}

// ─────────────────────────────────────────────────────────
// STEP 3: Delete remaining confirmed shells
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
        foreach (array_slice($shells, 0, 30) as $s) {
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