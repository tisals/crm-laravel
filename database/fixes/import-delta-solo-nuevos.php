<?php
/**
 * Delta import: only NEW oportunidad codigos from oportunidades.csv.
 *
 * Rules (canonical triple per row):
 *   - 1 codigo = 1 oportunidad
 *   - 1 (empresa, dominio) = 1 entidad (no fuzzy matches)
 *   - 1 email = 1 contacto
 *   - If codigo already exists in DB → SKIP (no duplicates)
 *   - If codigo appears multiple times in this CSV → only the first row is used
 *
 * Usage:
 *   php database/fixes/import-delta-solo-nuevos.php [--dry-run] [--csv=/path/to/oportunidades.csv]
 *
 * This script is designed for the transition period: the OLD import method
 * creates duplicates when CSV rows have different entities for the same codigo.
 * This DELTA import only processes codigos that don't exist yet, using the
 * canonical logic (1 codigo = 1 opp).
 *
 * To run safely: always start with --dry-run to see what would be created.
 */

$dryRun = in_array("--dry-run", $argv);
echo $dryRun ? "=== DRY-RUN ===\n" : "=== LIVE MODE ===\n";

$pdo = new PDO("mysql:host=prod_mariabd;dbname=crm_prod", "sailusdb", getenv("DBPW"));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$csvPath = "/var/www/html/database/csv/oportunidades.csv";
foreach ($argv as $a) {
    if (str_starts_with($a, "--csv=")) $csvPath = substr($a, 6);
}
if (!file_exists($csvPath)) {
    die("ERROR: $csvPath not found\n");
}
echo "CSV: $csvPath\n\n";

// ─────────────────────────────────────────────────────────
// Helper: normalize entity name (strip suffixes, accents, lower)
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
// Helper: detect social network URL
// ─────────────────────────────────────────────────────────
function isSocialNetworkUrl(string $value): bool {
    $domains = ["facebook.com", "instagram.com", "linkedin.com", "twitter.com", "x.com", "tiktok.com"];
    foreach ($domains as $d) {
        if (str_contains(strtolower($value), $d)) return true;
    }
    return false;
}

function parseDominio(string $raw): array {
    if (! $raw) return ["dominio" => null, "red_social" => null];
    if (isSocialNetworkUrl($raw)) return ["dominio" => null, "red_social" => $raw];
    return ["dominio" => $raw, "red_social" => null];
}

// ─────────────────────────────────────────────────────────
// Step 1: Load existing data from DB
// ─────────────────────────────────────────────────────────
$existingCodigos = [];
foreach ($pdo->query("SELECT codigo FROM oportunidad")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $existingCodigos[$r["codigo"]] = true;
}
echo "Existing codigos in DB: " . count($existingCodigos) . "\n";

$entitiesByDomain = [];
foreach ($pdo->query("SELECT id, nombre, dominio FROM entidad WHERE dominio IS NOT NULL AND dominio != ''")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $entitiesByDomain[$e["dominio"]] = $e;
}
echo "Entities with domain: " . count($entitiesByDomain) . "\n";

$entitiesByNormName = [];
foreach ($pdo->query("SELECT id, nombre FROM entidad WHERE dominio IS NULL OR dominio = ''")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $norm = normalizeEntityName($e["nombre"]);
    if (! isset($entitiesByNormName[$norm])) {
        $entitiesByNormName[$norm] = $e["id"];
    }
}
echo "Entities without domain: " . count($entitiesByNormName) . "\n";

$contactosByEmail = [];
foreach ($pdo->query("SELECT id, entidad_id, nombres FROM contacto WHERE email_contacto IS NOT NULL AND email_contacto != ''")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $contactosByEmail[$c["email_contacto"]] = $c;
}
echo "Contactos with email: " . count($contactosByEmail) . "\n\n";

// ─────────────────────────────────────────────────────────
// Step 2: Process CSV — only NEW codigos
// ─────────────────────────────────────────────────────────
$fh = fopen($csvPath, "r");
$header = fgetcsv($fh, 0, ";");

$stats = [
    "created"             => 0,
    "skipped_existing"    => 0,
    "skipped_dup_in_csv"  => 0,
    "failed"              => 0,
];
$csvCodigosSeen = [];

while (($row = fgetcsv($fh, 0, ";")) !== false) {
    if (count($row) < 14) continue;

    $codigo     = trim($row[0]);
    $empresa    = trim($row[13]);
    $domRaw     = trim($row[14] ?? "");
    $email      = trim($row[9] ?? "");
    $nombre     = trim($row[5] ?? "");
    $valor      = (float) preg_replace("/[^0-9.]/", "", $row[12] ?? "0");
    $fecha      = trim($row[10] ?? "");
    $lineaNeg   = trim($row[20] ?? "");  // Línea Negocio

    if (! $codigo || ! $empresa) continue;

    // Skip if already in DB
    if (isset($existingCodigos[$codigo])) {
        $stats["skipped_existing"]++;
        continue;
    }
    // Skip duplicates WITHIN the CSV (only first wins)
    if (isset($csvCodigosSeen[$codigo])) {
        $stats["skipped_dup_in_csv"]++;
        continue;
    }
    $csvCodigosSeen[$codigo] = true;

    // Parse dominio
    $parsed = parseDominio($domRaw);
    $dominio   = $parsed["dominio"];
    $redSocial = $parsed["red_social"];

    // 1. Find or create entity
    $entityId = null;
    if ($dominio && isset($entitiesByDomain[$dominio])) {
        $entityId = $entitiesByDomain[$dominio]["id"];
    } else if (! $dominio) {
        $norm = normalizeEntityName($empresa);
        if (isset($entitiesByNormName[$norm])) {
            $entityId = $entitiesByNormName[$norm];
        }
    }

    if (! $entityId) {
        // Create new entity (canonical: 1 (empresa, dominio) = 1 entity)
        if (! $dryRun) {
            $stmt = $pdo->prepare("
                INSERT INTO entidad (nombre, nombre_comercial, linea_negocio, dominio, red_social_url, estado, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'Activo', NOW(), NOW())
            ");
            $stmt->execute([$empresa, $empresa, $lineaNeg, $dominio, $redSocial]);
            $entityId = $pdo->lastInsertId();
            // Update indexes for next rows
            if ($dominio) {
                $entitiesByDomain[$dominio] = ["id" => $entityId, "nombre" => $empresa, "dominio" => $dominio];
            } else {
                $entitiesByNormName[normalizeEntityName($empresa)] = $entityId;
            }
        } else {
            $entityId = "NEW";
        }
    }

    // 2. Find or create contacto (only if email)
    $contactoId = null;
    if ($email) {
        if (isset($contactosByEmail[$email])) {
            $existing = $contactosByEmail[$email];
            $contactoId = $existing["id"];
            // Re-asign if needed
            if (! $dryRun && $entityId !== "NEW" && $existing["entidad_id"] != $entityId) {
                $pdo->prepare("UPDATE contacto SET entidad_id = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$entityId, $contactoId]);
            }
        } else {
            if (! $dryRun) {
                $stmt = $pdo->prepare("
                    INSERT INTO contacto (entidad_id, nombres, apellidos, email_contacto, estado, created_at, updated_at)
                    VALUES (?, ?, ' ', ?, 'Activo', NOW(), NOW())
                ");
                $stmt->execute([is_numeric($entityId) ? $entityId : 0, $nombre ?: "Sin nombre", $email]);
                $contactoId = $pdo->lastInsertId();
                $contactosByEmail[$email] = ["id" => $contactoId, "entidad_id" => $entityId, "nombres" => $nombre];
            } else {
                $contactoId = "NEW";
            }
        }
    }

    // 3. Create oportunidad
    if (! $dryRun && is_numeric($entityId)) {
        try {
            // Parse fecha (DD/MM/YYYY)
            $fechaSql = null;
            if (preg_match("/(\d{2})\/(\d{2})\/(\d{4})/", $fecha, $m)) {
                $fechaSql = "{$m[3]}-{$m[2]}-{$m[1]}";
            }
            $stmt = $pdo->prepare("
                INSERT INTO oportunidad (codigo, entidad_id, valor_sin_iva, fecha, estado, is_latest, version, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'Activa', TRUE, 0, NOW(), NOW())
            ");
            $stmt->execute([$codigo, $entityId, $valor, $fechaSql]);
            $stats["created"]++;
        } catch (Exception $e) {
            $stats["failed"]++;
            echo "  FAIL: {$codigo} → " . $e->getMessage() . "\n";
        }
    } else if ($dryRun) {
        $stats["created"]++;
    }
}
fclose($fh);

echo "=== RESUMEN ===\n";
foreach ($stats as $k => $v) {
    echo "  $k: $v\n";
}