<?php
/**
 * Cleanup v3: generates a comprehensive report and a temp table with proposed changes.
 *
 * Usage:
 *   php database/fixes/cleanup-v3.php                  # dry-run (generates report + temp table)
 *   php database/fixes/cleanup-v3.php --apply          # apply approved changes
 *   php database/fixes/cleanup-v3.php --apply --from-temp-table  # apply from approved temp table
 *
 * Output:
 *   - database/cleanup-reports/cleanup-pending-YYYY-MM-DD.txt
 *   - temp table: cleanup_proposed_changes
 *
 * Modes:
 *   - opp_reasign (CSV vs DB)
 *   - opp_reasign_by_contact (opp's contacto email domain vs DB)
 *   - entity_infer_dominio (entity with NIT + contacts with shared domain)
 *   - entity_consolidate (duplicate names → keep winner, delete losers)
 *   - shell_delete (in two passes)
 *       - 5a: empty from the start (0 opps, 0 contactos, no dominio)
 *       - 5b: post-movement (entidades que quedaron vacías tras stages 1-4)
 */

$host = getenv("DB_HOST") ?: "prod_mariabd";
$db = getenv("DB_NAME") ?: "crm_prod";
$user = getenv("DB_USER") ?: "sailusdb";
$pw = getenv("DBPW");
$pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pw);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$apply = in_array("--apply", $argv);
$fromTemp = in_array("--from-temp-table", $argv);
$csvPath = "/var/www/html/database/csv/oportunidades.csv";
foreach ($argv as $a) {
    if (str_starts_with($a, "--csv=")) $csvPath = substr($a, 6);
}
if (! file_exists($csvPath)) {
    die("ERROR: $csvPath not found\n");
}

function normalize(string $name): string {
    $name = strtolower(trim($name));
    $accents = ["á"=>"a","é"=>"e","í"=>"i","ó"=>"o","ú"=>"u","ü"=>"u","ñ"=>"n"];
    $name = strtr($name, $accents);
    $name = preg_replace("/\s+/", " ", $name);
    return preg_replace("/\s+(s\.?a\.?s\.?|s\.?a\.?|s\.?a|ltda|ltd|e\.?u\.?l\.?l|limitada|spa|s\.?p\.?a\.?|s\.?r\.?l\.?|cia|cia\.)\.?$/i", "", $name);
}

function extractDomain(string $email): ?string {
    if (! str_contains($email, "@")) return null;
    $parts = explode("@", strtolower(trim($email)), 2);
    return $parts[1] ?? null;
}

function isSocialNetwork(string $value): bool {
    foreach (["facebook.com","instagram.com","linkedin.com","twitter.com","x.com","tiktok.com"] as $d) {
        if (str_contains(strtolower($value), $d)) return true;
    }
    return false;
}

// ─────────────────────────────────────────────────────────
// PREP: ensure table exists, preserve user_notes from previous run
// ─────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cleanup_proposed_changes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action_type ENUM('opp_reasign','opp_reasign_by_contact','entity_infer_dominio','entity_consolidate','shell_delete','entity_create') NOT NULL,
        target_id INT NULL,
        payload LONGTEXT NOT NULL,
        reason TEXT,
        user_notes TEXT,
        status ENUM('pending','approved','rejected','applied') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        payload_hash CHAR(64) GENERATED ALWAYS AS (SHA2(payload, 256)) STORED,
        INDEX (action_type),
        INDEX (status),
        UNIQUE KEY (action_type, payload_hash),
        INDEX (payload_hash)
    ) ENGINE=InnoDB
");
// Add user_notes column if it doesn't exist
try {
    $pdo->exec("ALTER TABLE cleanup_proposed_changes ADD COLUMN user_notes TEXT AFTER reason");
} catch (Exception $e) { /* already exists */ }

// ─────────────────────────────────────────────────────────
// Mode 1: Apply from approved temp table
// ─────────────────────────────────────────────────────────
if ($fromTemp) {
    $approved = $pdo->query("SELECT * FROM cleanup_proposed_changes WHERE status = 'approved' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "Applying " . count($approved) . " approved changes...\n\n";

    $stats = ["opp_reasign" => 0, "entity_create" => 0, "entity_consolidate" => 0, "shell_delete" => 0, "failed" => 0];
    foreach ($approved as $change) {
        $p = json_decode($change["payload"], true);
        try {
            switch ($change["action_type"]) {
                case "opp_reasign":
                case "opp_reasign_by_contact":
                    $newId = $p["new_entity_id"] ?? null;
                    if (! $newId) {
                        $stmt = $pdo->prepare("
                            INSERT INTO entidad (nombre, nombre_comercial, dominio, estado, created_at, updated_at)
                            VALUES (?, ?, ?, 'Activo', NOW(), NOW())
                        ");
                        $stmt->execute([$p["new_entity_name"], $p["new_entity_name"], $p["new_entity_dominio"] ?? null]);
                        $newId = $pdo->lastInsertId();
                        $stats["entity_create"]++;
                    }
                    $pdo->prepare("UPDATE oportunidad SET entidad_id = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$newId, $p["opp_id"]]);
                    $stats["opp_reasign"]++;
                    break;
                case "entity_consolidate":
                    $winnerId = $p["winner_id"];
                    $loserId = $p["loser_id"];

                    // 1. Mover opps (no unique constraint issue)
                    $pdo->prepare("UPDATE oportunidad SET entidad_id = ?, updated_at = NOW() WHERE entidad_id = ?")
                        ->execute([$winnerId, $loserId]);

                    // 2. Deduplicar contactos antes de mover
                    //    Si el email ya existe en el ganador, eliminar el del perdedor
                    //    Si no existe, mover al ganador
                    $loserContacts = $pdo->prepare("SELECT id, email_contacto FROM contacto WHERE entidad_id = ?");
                    $loserContacts->execute([$loserId]);
                    $contacts = $loserContacts->fetchAll(PDO::FETCH_ASSOC);

                    $moved = 0;
                    $skipped = 0;
                    foreach ($contacts as $c) {
                        $email = $c["email_contacto"];
                        if ($email) {
                            $check = $pdo->prepare("SELECT id FROM contacto WHERE entidad_id = ? AND email_contacto = ? LIMIT 1");
                            $check->execute([$winnerId, $email]);
                            if ($check->fetch()) {
                                // Email already exists in winner → delete loser's contact
                                $pdo->prepare("DELETE FROM contacto WHERE id = ?")->execute([$c["id"]]);
                                $skipped++;
                                continue;
                            }
                        }
                        // No duplicate → move to winner
                        $pdo->prepare("UPDATE contacto SET entidad_id = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$winnerId, $c["id"]]);
                        $moved++;
                    }

                    // 3. Eliminar entidad perdedora (ya sin contactos ni opps)
                    $pdo->prepare("DELETE FROM entidad WHERE id = ?")->execute([$loserId]);
                    $stats["entity_consolidate"]++;
                    break;
                case "shell_delete":
                    $pdo->prepare("DELETE FROM entidad WHERE id = ?")->execute([$p["entity_id"]]);
                    $stats["shell_delete"]++;
                    break;
            }
            $pdo->prepare("UPDATE cleanup_proposed_changes SET status = 'applied' WHERE id = ?")
                ->execute([$change["id"]]);
        } catch (Exception $e) {
            $stats["failed"]++;
            echo "  FAIL change #{$change['id']}: " . $e->getMessage() . "\n";
        }
    }
    echo "Resultados:\n";
    foreach ($stats as $k => $v) echo "  $k: $v\n";
    exit(0);
}

// Save user_notes from existing rows BEFORE truncate
$savedNotes = [];
$rows = $pdo->query("SELECT action_type, payload, user_notes FROM cleanup_proposed_changes WHERE user_notes IS NOT NULL AND user_notes != ''")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $key = $r["action_type"] . ":" . hash("sha256", $r["payload"]);
    $savedNotes[$key] = $r["user_notes"];
}
echo "Notas previas guardadas: " . count($savedNotes) . "\n";

// Clear data
$pdo->exec("TRUNCATE TABLE cleanup_proposed_changes");

$entidades = [];
$entByDominio = [];
$entByNorm = [];
$rows = $pdo->query("SELECT id, nombre, dominio, identificacion, linea_negocio, created_at FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $entidades[$r["id"]] = $r;
    if ($r["dominio"]) {
        $key = strtolower(trim($r["dominio"]));
        if (! isset($entByDominio[$key])) $entByDominio[$key] = $r["id"];
    }
    $keyNorm = normalize($r["nombre"]);
    if (! isset($entByNorm[$keyNorm])) $entByNorm[$keyNorm] = $r["id"];
}

// ─────────────────────────────────────────────────────────
// Load CSV
// ─────────────────────────────────────────────────────────
$csvByCodigo = [];
$fh = fopen($csvPath, "r");
$header = fgetcsv($fh, 0, ";");
while (($row = fgetcsv($fh, 0, ";")) !== false) {
    if (count($row) < 14) continue;
    $codigo = trim($row[0]);
    $empresa = trim($row[13]);
    $domRaw = trim($row[14]);
    $email = trim($row[9]);
    if (! $codigo || ! $empresa) continue;
    $dom = $domRaw;
    if ($dom && isSocialNetwork($dom)) $dom = null;

    $canonicalId = null;
    if ($dom && isset($entByDominio[strtolower($dom)])) {
        $canonicalId = $entByDominio[strtolower($dom)];
    } elseif (isset($entByNorm[normalize($empresa)])) {
        $canonicalId = $entByNorm[normalize($empresa)];
    }

    $csvByCodigo[$codigo] = [
        "empresa" => $empresa,
        "empresa_norm" => normalize($empresa),
        "dominio" => $dom,
        "email" => $email,
        "canonical_id" => $canonicalId,
    ];
}
fclose($fh);

// ─────────────────────────────────────────────────────────
// Mode 2: Generate report + temp table
// ─────────────────────────────────────────────────────────

$reportDir = __DIR__ . "/../cleanup-reports";
if (! is_dir($reportDir)) mkdir($reportDir, 0755, true);
$reportFile = $reportDir . "/cleanup-pending-" . date("Y-m-d-His") . ".txt";

$fhReport = fopen($reportFile, "w");
$report = "";

// ─────────────────────────────────────────────────────────
// Stage 1: Reasign opps by CSV canonical entity
// ─────────────────────────────────────────────────────────
$report .= "═══════════════════════════════════════════════════════════════\n";
$report .= "  STAGE 1: Opps a reasignar según CSV (canonical_id conocido)\n";
$report .= "═══════════════════════════════════════════════════════════════\n\n";

$countReasign = 0;
$countNewEntity = 0;
$opps = $pdo->query("SELECT id, codigo, entidad_id FROM oportunidad")->fetchAll(PDO::FETCH_ASSOC);
foreach ($opps as $o) {
    $csv = $csvByCodigo[$o["codigo"]] ?? null;
    if (! $csv) continue;

    // Decide target entity
    $targetId = $csv["canonical_id"];
    $newEntityName = null;
    $newEntityDominio = null;
    $isNewEntity = false;

    if (! $targetId) {
        // No canonical match — need to create new
        $newEntityName = $csv["empresa"];
        $newEntityDominio = $csv["dominio"];
        $targetId = null; // signal to create
        $isNewEntity = true;
    } elseif ($targetId == $o["entidad_id"]) {
        continue; // already correct
    }

    $fromName = $entidades[$o["entidad_id"]]["nombre"] ?? "?";
    $toName = $targetId ? ($entidades[$targetId]["nombre"] ?? "?") : $newEntityName;
    $toStatus = $isNewEntity ? "NUEVA" : "EXISTENTE";
    $toDominio = $targetId ? ($entidades[$targetId]["dominio"] ?? "(NULL)") : ($newEntityDominio ?? "(NULL)");

    $report .= sprintf("  opp[%s] %s\n",
        $o["id"], $o["codigo"]);
    $report .= sprintf("    Inicial: %s (id=%s)\n",
        substr($fromName, 0, 60), $o["entidad_id"]);
    $report .= sprintf("    Final:   %s (id=%s) [%s, dom=%s]\n",
        substr($toName, 0, 60), $targetId ?? "NEW", $toStatus, $toDominio);
    $report .= sprintf("    Razón:   CSV canonical=%s\n\n", $csv["empresa"]);

    $payload = [
        "opp_id" => $o["id"],
        "codigo" => $o["codigo"],
        "from_entity_id" => $o["entidad_id"],
        "from_entity_name" => $fromName,
    ];
    if ($targetId) {
        $payload["new_entity_id"] = $targetId;
        $payload["new_entity_name"] = $toName;
        $payload["target_status"] = "EXISTENTE";
    } else {
        $payload["new_entity_name"] = $newEntityName;
        $payload["new_entity_dominio"] = $newEntityDominio;
        $payload["target_status"] = "NUEVA";
        $countNewEntity++;
    }

    $jsonPayload = json_encode($payload);
    $noteKey = "opp_reasign:" . hash("sha256", $jsonPayload);
    $preservedNote = $savedNotes[$noteKey] ?? null;

    $pdo->prepare("
        INSERT INTO cleanup_proposed_changes (action_type, target_id, payload, reason, user_notes)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        "opp_reasign",
        $o["id"],
        $jsonPayload,
        "CSV canonical entity: " . $csv["empresa"],
        $preservedNote,
    ]);
    $countReasign++;
}
$report .= "\nTotal: $countReasign opps a reasignar ($countNewEntity requieren crear entidad NUEVA)\n\n";
echo $report;
fwrite($fhReport, $report);

// ─────────────────────────────────────────────────────────
// Stage 2: Reasign opps by contacto email domain
// ─────────────────────────────────────────────────────────
$report = "═══════════════════════════════════════════════════════════════\n";
$report .= "  STAGE 2: Opps a reasignar según dominio de contacto\n";
$report .= "═══════════════════════════════════════════════════════════════\n\n";

$contactosByEntidad = $pdo->query("SELECT id, entidad_id, email_contacto FROM contacto WHERE email_contacto LIKE '%@%'")->fetchAll(PDO::FETCH_ASSOC);

// Build map: email → [entidad_id, count]
$emailMap = [];
foreach ($contactosByEntidad as $c) {
    $dom = extractDomain($c["email_contacto"]);
    if (! $dom || str_contains($dom, "gmail") || str_contains($dom, "hotmail")) continue;
    $key = $c["email_contacto"];
    $emailMap[$key][] = $c["entidad_id"];
}

$countContactReasign = 0;
foreach ($opps as $o) {
    // Find contacto of this opp
    $contacto = $pdo->prepare("
        SELECT c.email_contacto, c.entidad_id AS contacto_entidad
        FROM contacto c WHERE c.id = ?
    ");
    $stmt = $pdo->prepare("SELECT contacto_id FROM oportunidad WHERE id = ?");
    $stmt->execute([$o["id"]]);
    $cid = $stmt->fetchColumn();
    if (! $cid) continue;

    $contacto->execute([$cid]);
    $cdata = $contacto->fetch(PDO::FETCH_ASSOC);
    if (! $cdata) continue;

    $dom = extractDomain($cdata["email_contacto"]);
    if (! $dom || str_contains($dom, "gmail") || str_contains($dom, "hotmail") || str_contains($dom, "yahoo") || str_contains($dom, "outlook") || str_contains($dom, "live")) continue;

    // Find entity matching this domain
    $targetId = $entByDominio[$dom] ?? null;
    if (! $targetId) continue;
    if ($targetId == $o["entidad_id"]) continue;
    if ($o["entidad_id"] != $cdata["contacto_entidad"]) continue; // contacto already in correct entity

    $fromName = $entidades[$o["entidad_id"]]["nombre"] ?? "?";
    $toName = $entidades[$targetId]["nombre"] ?? "?";

    $report .= sprintf("  opp[%s] %s\n", $o["id"], $o["codigo"]);
    $report .= sprintf("    Inicial:    %s (id=%s) [contacto=%s, dom=%s]\n",
        substr($fromName, 0, 50), $o["entidad_id"],
        $cdata["email_contacto"], $dom);
    $report .= sprintf("    Final:      %s (id=%s) [EXISTENTE]\n",
        substr($toName, 0, 60), $targetId);
    $report .= "    Razón:      Contacto email domain coincide con entidad destino\n\n";

    $pdo->prepare("
        INSERT INTO cleanup_proposed_changes (action_type, target_id, payload, reason, user_notes)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        "opp_reasign_by_contact",
        $o["id"],
        json_encode([
            "opp_id" => $o["id"],
            "new_entity_id" => $targetId,
            "new_entity_name" => $toName,
            "from_entity_id" => $o["entidad_id"],
            "target_status" => "EXISTENTE",
        ]),
        "Contacto email domain: $dom",
        $savedNotes["opp_reasign_by_contact:" . hash("sha256", json_encode([
            "opp_id" => $o["id"],
            "new_entity_id" => $targetId,
            "new_entity_name" => $toName,
            "from_entity_id" => $o["entidad_id"],
            "target_status" => "EXISTENTE",
        ]))] ?? null,
    ]);
    $countContactReasign++;
}
$report .= "\nTotal: $countContactReasign opps a reasignar por dominio de contacto (todos van a entidades EXISTENTES)\n\n";
echo $report;
fwrite($fhReport, $report);

// ─────────────────────────────────────────────────────────
// Stage 3: Infer dominio from contacts
// ─────────────────────────────────────────────────────────
$report = "═══════════════════════════════════════════════════════════════\n";
$report .= "  STAGE 3: Inferir dominio para entidades sin dominio (NIT + contactos)\n";
$report .= "═══════════════════════════════════════════════════════════════\n\n";

$countInfer = 0;
foreach ($entidades as $eid => $e) {
    if (! empty($e["dominio"])) continue;
    if (empty($e["identificacion"])) continue;

    $dominios = $pdo->prepare("
        SELECT SUBSTRING_INDEX(c.email_contacto, '@', -1) AS dom, COUNT(*) AS c
        FROM contacto c WHERE c.entidad_id = ?
          AND c.email_contacto LIKE '%@%'
          AND c.email_contacto NOT LIKE '%@gmail.%'
          AND c.email_contacto NOT LIKE '%@hotmail.%'
          AND c.email_contacto NOT LIKE '%@yahoo.%'
          AND c.email_contacto NOT LIKE '%@outlook.%'
          AND c.email_contacto NOT LIKE '%@live.%'
        GROUP BY dom ORDER BY c DESC
    ");
    $dominios->execute([$eid]);
    $doms = $dominios->fetchAll(PDO::FETCH_ASSOC);

    if (count($doms) >= 1 && $doms[0]["c"] >= 2) {
        $top = $doms[0];
        $report .= sprintf("  [%s] %s [NIT=%s]\n",
            $eid, substr($e["nombre"], 0, 50), $e["identificacion"]);
        $report .= sprintf("    Inicial:  dominio=(NULL)\n");
        $report .= sprintf("    Final:    dominio=%s (EXISTENTE, UPDATE)\n", $top["dom"]);
        $report .= sprintf("    Razón:    %d contactos con @%s\n\n",
            $top["c"], $top["dom"]);

        $pdo->prepare("
            INSERT INTO cleanup_proposed_changes (action_type, target_id, payload, reason)
            VALUES (?, ?, ?, ?)
        ")->execute([
            "entity_infer_dominio",
            $eid,
            json_encode([
                "entity_id" => $eid,
                "new_dominio" => $top["dom"],
                "current_nit" => $e["identificacion"],
                "supporting_contacts" => $top["c"],
            ]),
            "NIT={$e['identificacion']} + {$top['c']} contactos con @{$top['dom']}",
        ]);
        $countInfer++;
    }
}
$report .= "\nTotal: $countInfer entidades con dominio inferible\n\n";
echo $report;
fwrite($fhReport, $report);

// ─────────────────────────────────────────────────────────
// Stage 4: Consolidate duplicate names
// ─────────────────────────────────────────────────────────
$report = "═══════════════════════════════════════════════════════════════\n";
$report .= "  STAGE 4: Consolidar entidades duplicadas (mismo nombre normalizado)\n";
$report .= "═══════════════════════════════════════════════════════════════\n\n";

$dupPorNombre = [];
foreach ($entidades as $eid => $e) {
    $dupPorNombre[normalize($e["nombre"])][] = $eid;
}

$countConsolidate = 0;
foreach ($dupPorNombre as $norm => $ids) {
    if (count($ids) <= 1) continue;
    if (count($ids) > 8) continue; // skip mega-clusters like POLINTER (handled separately)

    // Pick winner: prefer NIT, then most contactos, then earliest
    usort($ids, function($a, $b) use ($pdo) {
        $ea = $pdo->query("SELECT identificacion, (SELECT COUNT(*) FROM contacto WHERE entidad_id = $a) AS c, (SELECT COUNT(*) FROM oportunidad WHERE entidad_id = $a) AS o, (SELECT MIN(created_at) FROM entidad WHERE id IN ($a,$b)) AS ca FROM entidad WHERE id = $a")->fetch(PDO::FETCH_ASSOC);
        $eb = $pdo->query("SELECT identificacion, (SELECT COUNT(*) FROM contacto WHERE entidad_id = $b) AS c, (SELECT COUNT(*) FROM oportunidad WHERE entidad_id = $b) AS o, (SELECT MIN(created_at) FROM entidad WHERE id IN ($a,$b)) AS ca FROM entidad WHERE id = $b")->fetch(PDO::FETCH_ASSOC);
        $scoreA = (!empty($ea["identificacion"]) ? 1000 : 0) + $ea["c"] * 10 + $ea["o"];
        $scoreB = (!empty($eb["identificacion"]) ? 1000 : 0) + $eb["c"] * 10 + $eb["o"];
        if ($scoreA == $scoreB) return $ea["ca"] <=> $eb["ca"];
        return $scoreB <=> $scoreA;
    });

    $winner = $ids[0];
    $losers = array_slice($ids, 1);
    $winnerName = $entidades[$winner]["nombre"];
    $winnerDominio = $entidades[$winner]["dominio"] ?? "(NULL)";
    $winnerNit = $entidades[$winner]["identificacion"] ?? "(NULL)";

    $report .= sprintf("  Nombre normalizado: '%s'\n", $norm);
    $report .= sprintf("    GANADOR: [%s] %s [dom=%s, NIT=%s]\n",
        $winner, substr($winnerName, 0, 50), $winnerDominio, $winnerNit);
    $report .= "    Perdedores:\n";
    foreach ($losers as $loser) {
        $loserName = $entidades[$loser]["nombre"] ?? "?";
        $loserDominio = $entidades[$loser]["dominio"] ?? "(NULL)";
        $loserNit = $entidades[$loser]["identificacion"] ?? "(NULL)";
        $report .= sprintf("      [%s] %s [dom=%s, NIT=%s] → MOVER opps+contactos a ganador, BORRAR entidad\n",
            $loser, substr($loserName, 0, 45), $loserDominio, $loserNit);

        $consPayload = json_encode([
            "winner_id" => $winner,
            "winner_name" => $winnerName,
            "loser_id" => $loser,
            "loser_name" => $loserName,
        ]);
        $pdo->prepare("
            INSERT INTO cleanup_proposed_changes (action_type, target_id, payload, reason, user_notes)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            "entity_consolidate",
            $winner,
            $consPayload,
            "Duplicate of '$winnerName'",
            $savedNotes["entity_consolidate:" . hash("sha256", $consPayload)] ?? null,
        ]);
        $countConsolidate++;
    }
    $report .= "\n";
}
$report .= "\nTotal: $countConsolidate entidades perdedoras a borrar (mega-clusters excluidos)\n\n";
echo $report;
fwrite($fhReport, $report);

// ─────────────────────────────────────────────────────────
// Stage 5a: Shell deletion — entidades vacías desde el inicio
// ─────────────────────────────────────────────────────────
$report = "═══════════════════════════════════════════════════════════════\n";
$report .= "  STAGE 5a: Shells iniciales (0 opps, 0 contactos, dominio NULL)\n";
$report .= "═══════════════════════════════════════════════════════════════\n\n";

$shells = $pdo->query("
    SELECT id, nombre FROM entidad
    WHERE (dominio IS NULL OR dominio = '')
      AND (SELECT COUNT(*) FROM oportunidad WHERE entidad_id = id) = 0
      AND (SELECT COUNT(*) FROM contacto WHERE entidad_id = id) = 0
")->fetchAll(PDO::FETCH_ASSOC);

$countShells = 0;
foreach ($shells as $s) {
    $pdo->prepare("
        INSERT INTO cleanup_proposed_changes (action_type, target_id, payload, reason)
        VALUES (?, ?, ?, ?)
    ")->execute([
        "shell_delete",
        $s["id"],
        json_encode(["entity_id" => $s["id"], "entity_name" => $s["nombre"], "batch" => "initial"]),
        "Empty shell (initial)",
    ]);
    $countShells++;
}
$report .= sprintf("Total: %d shells iniciales a borrar\n\n", $countShells);
if ($countShells > 0) {
    foreach ($shells as $s) {
        $report .= sprintf("    BORRAR [%s] %s [dom=(NULL), opps=0, contactos=0]\n",
            $s["id"], substr($s["nombre"], 0, 60));
    }
    $report .= "\n";
}
echo $report;
fwrite($fhReport, $report);

// ─────────────────────────────────────────────────────────
// Stage 5b: Shells post-movimiento
//   Después de stages 1-4, calcular qué entidades quedan vacías
// ─────────────────────────────────────────────────────────
$report = "═══════════════════════════════════════════════════════════════\n";
$report .= "  STAGE 5b: Shells post-movimiento (calculados tras stages 1-4)\n";
$report .= "═══════════════════════════════════════════════════════════════\n\n";

// Cargar counts originales
$oppCountByEntity = [];
foreach ($pdo->query("SELECT entidad_id, COUNT(*) c FROM oportunidad GROUP BY entidad_id") as $r) {
    $oppCountByEntity[$r["entidad_id"]] = $r["c"];
}

$contactCountByEntity = [];
foreach ($pdo->query("SELECT entidad_id, COUNT(*) c FROM contacto GROUP BY entidad_id") as $r) {
    $contactCountByEntity[$r["entidad_id"]] = $r["c"];
}

// Restar opps que se reasignarán (stages 1-2)
$propuestasOpp = $pdo->query("
    SELECT payload FROM cleanup_proposed_changes
    WHERE action_type IN ('opp_reasign', 'opp_reasign_by_contact')
      AND status IN ('pending', 'approved')
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($propuestasOpp as $pp) {
    $data = json_decode($pp["payload"], true);
    $fromId = $data["from_entity_id"] ?? null;
    if ($fromId && isset($oppCountByEntity[$fromId])) {
        $oppCountByEntity[$fromId]--;
    }
}

// Procesar consolidaciones (stage 4) — restar opps/contactos de los losers,
// agregar al winner (aunque el winner no importa, se borra de todas formas)
$propuestasCons = $pdo->query("
    SELECT payload FROM cleanup_proposed_changes
    WHERE action_type = 'entity_consolidate'
      AND status IN ('pending', 'approved')
")->fetchAll(PDO::FETCH_ASSOC);

$toDelete = [];
foreach ($propuestasCons as $pp) {
    $data = json_decode($pp["payload"], true);
    $loserId = $data["loser_id"] ?? null;
    if ($loserId) {
        // Loser se borra entera — sus opps/contactos se mueven al winner
        // Para detectar shells, asumimos que perdedor queda en 0
        $oppCountByEntity[$loserId] = 0;
        $contactCountByEntity[$loserId] = 0;
        $toDelete[$loserId] = true;
    }
}

// Detectar shells post-movimiento
$shellsPost = [];
foreach ($entidades as $eid => $e) {
    // Skip si ya está marcada como shell_delete en este run
    if (in_array($eid, array_column($shells, "id"))) continue;
    // Skip si se va a borrar por consolidación
    if (isset($toDelete[$eid])) continue;
    // Skip clientes — no tocar
    if (($e["estado"] ?? "") === "Cliente") continue;

    $oppFinal = $oppCountByEntity[$eid] ?? 0;
    $contactFinal = $contactCountByEntity[$eid] ?? 0;
    $dominioNull = empty($e["dominio"]);

    if ($dominioNull && $oppFinal == 0 && $contactFinal == 0) {
        $shellsPost[] = $eid;
    }
}

$countShellsPost = 0;
foreach ($shellsPost as $eid) {
    $e = $entidades[$eid];
    $pdo->prepare("
        INSERT INTO cleanup_proposed_changes (action_type, target_id, payload, reason)
        VALUES (?, ?, ?, ?)
    ")->execute([
        "shell_delete",
        $eid,
        json_encode([
            "entity_id" => $eid,
            "entity_name" => $e["nombre"],
            "batch" => "post_movement",
        ]),
        "Empty shell (post-movement, was not empty at start)",
    ]);
    $countShellsPost++;
}

$report .= sprintf("Total: %d shells adicionales detectados post-movimiento\n\n", $countShellsPost);
if ($countShellsPost > 0) {
    foreach ($shellsPost as $eid) {
        $e = $entidades[$eid];
        $report .= sprintf("    BORRAR [%s] %s [dom=(NULL), opps=0 tras reasignación, contactos=0]\n",
            $eid, substr($e["nombre"], 0, 60));
    }
    $report .= "\n";
}
echo $report;
fwrite($fhReport, $report);

// ─────────────────────────────────────────────────────────
// Final summary
// ─────────────────────────────────────────────────────────
$summary = "═══════════════════════════════════════════════════════════════\n";
$summary .= "  RESUMEN FINAL — ACCIONES PROPUESTAS\n";
$summary .= "═══════════════════════════════════════════════════════════════\n\n";
$summary .= "Tabla: cleanup_proposed_changes\n";
$summary .= "  Stage 1 (reassign by CSV):    $countReasign\n";
$summary .= "  Stage 2 (reassign by contact): " . ($countContactReasign ?? 0) . "\n";
$summary .= "  Stage 3 (infer dominio):     $countInfer\n";
$summary .= "  Stage 4 (consolidate):       $countConsolidate\n";
$summary .= "  Stage 5 (delete shells):     $countShells\n\n";
$summary .= "Reporte: $reportFile\n\n";
$summary .= "Para revisar:\n";
$summary .= "  SELECT * FROM cleanup_proposed_changes WHERE status = 'pending';\n\n";
$summary .= "Para AGREGAR OBSERVACIONES (notas que se preservan entre corridas):\n";
$summary .= "  UPDATE cleanup_proposed_changes SET user_notes = 'TEXTO' WHERE id = X;\n";
$summary .= "  UPDATE cleanup_proposed_changes SET user_notes = CONCAT(IFNULL(user_notes,''), ' | nuevo texto') WHERE id = X;\n";
$summary .= "  UPDATE cleanup_proposed_changes SET user_notes = NULL WHERE id = X;  (limpiar)\n\n";
$summary .= "Para aprobar/rechazar:\n";
$summary .= "  UPDATE cleanup_proposed_changes SET status = 'approved' WHERE id IN (...);\n";
$summary .= "  UPDATE cleanup_proposed_changes SET status = 'rejected' WHERE id IN (...);\n\n";
$summary .= "Para aplicar DESPUÉS de revisar:\n";
$summary .= "  php database/fixes/cleanup-v3.php --apply --from-temp-table\n\n";
$summary .= "Las notas se preservan entre corridas (basado en hash del payload).\n";

fwrite($fhReport, $summary);
echo $summary;
fclose($fhReport);
