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
 * Idempotent:
 *   - Contacto: if email exists at a different entity, UPDATE entidad_id
 *   - Contacto: if email exists at the same entity, skip
 *
 * Usage:
 *   php database/fixes/import-delta-solo-nuevos.php [--dry-run] [--csv=/path/to/oportunidades.csv]
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
// Step 1: Load existing data
// ─────────────────────────────────────────────────────────

// Existing codigos → skip
$existingCodigos = [];
foreach ($pdo->query("SELECT codigo FROM oportunidad")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $existingCodigos[$r["codigo"]] = true;
}

// Entities by domain
$entitiesByDomain = [];
foreach ($pdo->query("SELECT id, nombre, dominio FROM entidad WHERE dominio IS NOT NULL AND dominio != ''")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $entitiesByDomain[$e["dominio"]] = $e["id"];
}

// Entities without domain by normalized name
$entitiesByNormName = [];
foreach ($pdo->query("SELECT id, nombre FROM entidad WHERE dominio IS NULL OR dominio = ''")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $norm = normalizeEntityName($e["nombre"]);
    if (! isset($entitiesByNormName[$norm])) {
        $entitiesByNormName[$norm] = $e["id"];
    }
}

// Contactos by email (with their entidad_id)
$contactosByEmail = [];
foreach ($pdo->query("SELECT id, entidad_id, email_contacto FROM contacto WHERE email_contacto IS NOT NULL AND email_contacto != ''")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $contactosByEmail[$c["email_contacto"]] = $c;
}

echo "Existing codigos in DB: " . count($existingCodigos) . "\n";
echo "Entities with domain: " . count($entitiesByDomain) . "\n";
echo "Entities without domain: " . count($entitiesByNormName) . "\n";
echo "Contactos with email: " . count($contactosByEmail) . "\n\n";

// ─────────────────────────────────────────────────────────
// Step 2: Process CSV — only NEW codigos
// ─────────────────────────────────────────────────────────

$fh = fopen($csvPath, "r");
$header = fgetcsv($fh, 0, ";");

$stats = [
    "created"           => 0,
    "skipped_existing"  => 0,
    "skipped_dup_in_csv"=> 0,
    "failed"            => 0,
];
$csvCodigosSeen = [];

while (($row = fgetcsv($fh, 0, ";")) !== false) {
    if (count($row) < 14) continue;

    $codigo     = trim($row[0] ?? '');
    $empresa    = trim($row[13] ?? '');
    $domRaw     = trim($row[14] ?? '');
    $email      = trim($row[9] ?? '');

    if (! $codigo || ! $empresa) continue;

    // Skip if already in DB
    if (isset($existingCodigos[$codigo])) {
        $stats["skipped_existing"]++;
        continue;
    }
    // Skip duplicates within this CSV
    if (isset($csvCodigosSeen[$codigo])) {
        $stats["skipped_dup_in_csv"]++;
        continue;
    }
    $csvCodigosSeen[$codigo] = true;

    $empresaNorm = normalizeEntityName($empresa);
    $parsed = parseDominio($domRaw);
    $dominio = $parsed["dominio"];
    $redSocial = $parsed["red_social"];

    // Find or create entity
    $entityId = null;
    if ($dominio && isset($entitiesByDomain[$dominio])) {
        $entityId = $entitiesByDomain[$dominio];
    } elseif (! $dominio && isset($entitiesByNormName[$empresaNorm])) {
        $entityId = $entitiesByNormName[$empresaNorm];
    }

    if (! $entityId) {
        try {
            if (! $dryRun) {
                $linea = trim($row[20] ?? '');
                $stmt = $pdo->prepare("
                    INSERT INTO entidad (nombre, nombre_comercial, linea_negocio, dominio, red_social_url, estado, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 'Activo', NOW(), NOW())
                ");
                $stmt->execute([$empresa, $empresa, $linea, $dominio, $redSocial]);
                $entityId = $pdo->lastInsertId();
                if ($dominio) {
                    $entitiesByDomain[$dominio] = $entityId;
                } else {
                    $entitiesByNormName[$empresaNorm] = $entityId;
                }
            }
        } catch (Exception $e) {
            $stats["failed"]++;
            echo "  FAIL creating entity for {$codigo}: " . $e->getMessage() . "\n";
            continue;
        }
    }

    // Create oportunidad
    $valor = (float) preg_replace("/[^0-9.]/", "", $row[12] ?? "0");
    $fechaRaw = trim($row[10] ?? '');
    $fechaSql = null;
    if (preg_match("/(\d{2})\/(\d{2})\/(\d{4})/", $fechaRaw, $m)) {
        $fechaSql = "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    try {
        if (! $dryRun) {
            $pdo->prepare("
                INSERT INTO oportunidad (codigo, entidad_id, valor_sin_iva, fecha, estado, is_latest, version, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'Activa', TRUE, 0, NOW(), NOW())
            ")->execute([$codigo, $entityId, $valor, $fechaSql]);
        }
        $stats["created"]++;
    } catch (Exception $e) {
        $stats["failed"]++;
        echo "  FAIL creating opp {$codigo}: " . $e->getMessage() . "\n";
        continue;
    }

    // Handle contacto (idempotent)
    if ($email !== "" && ! $dryRun) {
        $existing = $contactosByEmail[$email] ?? null;
        $nombre = trim(explode("\n", trim($row[5] ?? ''))[0] ?? '');

        if ($existing) {
            // Contacto exists — update entity if different
            if ((int) $existing["entidad_id"] !== (int) $entityId) {
                try {
                    $pdo->prepare("UPDATE contacto SET entidad_id = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$entityId, $existing["id"]]);
                    $contactosByEmail[$email]["entidad_id"] = $entityId;
                } catch (Exception $e) {
                    // FK or other constraint — log and skip
                    echo "  SKIP contacto update {$email}: " . $e->getMessage() . "\n";
                }
            }
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO contacto (entidad_id, nombres, apellidos, email_contacto, estado, created_at, updated_at)
                    VALUES (?, ?, ' ', ?, 'Activo', NOW(), NOW())
                ")->execute([$entityId, $nombre ?: "Sin nombre", $email]);
                $contactosByEmail[$email] = [
                    "id" => $pdo->lastInsertId(),
                    "entidad_id" => $entityId,
                ];
            } catch (Exception $e) {
                echo "  SKIP contacto insert {$email}: " . $e->getMessage() . "\n";
            }
        }
    }
}
fclose($fh);

echo "=== RESUMEN ===\n";
foreach ($stats as $k => $v) {
    echo "  $k: $v\n";
}