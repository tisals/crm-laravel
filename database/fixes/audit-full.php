<?php
/**
 * Comprehensive audit + cleanup plan generator.
 *
 * For each contacto: determine which entity it SHOULD belong to (by domain).
 * For each opp: determine which entity it SHOULD belong to (from CSV).
 * Cross-reference and produce a list of:
 *   - Reasignments needed (opp or contacto)
 *   - Entities to create
 *   - Entities to delete (shells)
 *   - Entities to consolidate (duplicates)
 *
 * Usage: php database/fixes/audit-full.php [--csv=/path/to/oportunidades.csv]
 */

$host = getenv("DB_HOST") ?: "prod_mariabd";
$db = getenv("DB_NAME") ?: "crm_prod";
$user = getenv("DB_USER") ?: "sailusdb";
$pw = getenv("DBPW");
$pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pw);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
    $email = strtolower(trim($email));
    if (! str_contains($email, "@")) return null;
    $parts = explode("@", $email, 2);
    return $parts[1] ?? null;
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  AUDITORÍA FULL — PLAN DE LIMPIEZA\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─────────────────────────────────────────────────────────
// STEP 1: Cargar entidades existentes (indexadas por dominio y nombre)
// ─────────────────────────────────────────────────────────
$entidades = [];
$entByDominio = [];
$entByNorm = [];

$rows = $pdo->query("SELECT id, nombre, dominio, identificacion, linea_negocio, created_at FROM entidad")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $entidades[$r["id"]] = $r;
    if ($r["dominio"]) {
        $key = strtolower(trim($r["dominio"]));
        if (! isset($entByDominio[$key])) {
            $entByDominio[$key] = $r["id"];
        }
    }
    $keyNorm = normalize($r["nombre"]);
    if (! isset($entByNorm[$keyNorm])) {
        $entByNorm[$keyNorm] = $r["id"];
    }
}
echo "STEP 1: " . count($entidades) . " entidades cargadas\n";

// ─────────────────────────────────────────────────────────
// STEP 2: Cargar CSV y resolver entidad canónica por codigo
// ─────────────────────────────────────────────────────────
$csvByCodigo = []; // codigo -> [empresa, dominio, email, canonical_id]
$csvEmpresas = []; // unique normalized empresa names
$unmatchedEmpresas = []; // empresas from CSV not found in DB

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
    if ($dom && (str_contains(strtolower($dom), "facebook.com") || str_contains(strtolower($dom), "instagram.com"))) {
        $dom = null;
    }

    // Find canonical entity
    $canonicalId = null;
    $empresaNorm = normalize($empresa);
    if ($dom && isset($entByDominio[strtolower($dom)])) {
        $canonicalId = $entByDominio[strtolower($dom)];
    } elseif (isset($entByNorm[$empresaNorm])) {
        $canonicalId = $entByNorm[$empresaNorm];
    } else {
        $unmatchedEmpresas[$empresaNorm] = $empresa;
    }

    $csvByCodigo[$codigo] = [
        "empresa" => $empresa,
        "empresa_norm" => $empresaNorm,
        "dominio" => $dom,
        "email" => $email,
        "canonical_id" => $canonicalId,
    ];
}
fclose($fh);
echo "STEP 2: " . count($csvByCodigo) . " CSV rows procesados\n";
echo "  Empresas del CSV sin matchear entidad existente: " . count($unmatchedEmpresas) . "\n";

// ─────────────────────────────────────────────────────────
// STEP 3: Cargar opps, detectar mismatches con CSV
// ─────────────────────────────────────────────────────────
$opps = $pdo->query("SELECT id, codigo, entidad_id FROM oportunidad")->fetchAll(PDO::FETCH_ASSOC);
$oppReasign = [];
$oppOK = 0;
foreach ($opps as $o) {
    $csv = $csvByCodigo[$o["codigo"]] ?? null;
    if (! $csv) continue;
    if ($csv["canonical_id"] === null) continue; // no canonical entity
    if ($csv["canonical_id"] != $o["entidad_id"]) {
        $oppReasign[] = [
            "opp_id" => $o["id"],
            "codigo" => $o["codigo"],
            "from" => $o["entidad_id"],
            "from_name" => $entidades[$o["entidad_id"]]["nombre"] ?? "?",
            "to" => $csv["canonical_id"],
            "to_name" => $csv["empresa"],
        ];
    } else {
        $oppOK++;
    }
}
echo "STEP 3: " . count($oppReasign) . " opps a reasignar (de " . count($opps) . " totales, " . $oppOK . " OK)\n\n";

// ─────────────────────────────────────────────────────────
// STEP 4: Para cada entidad sin dominio, inferir desde sus contactos
// ─────────────────────────────────────────────────────────
echo "STEP 4: Entidades sin dominio — inferir desde contactos\n\n";
$inferDominio = [];
$noInferible = [];

foreach ($entidades as $eid => $e) {
    if (! empty($e["dominio"])) continue;
    if (empty($e["identificacion"])) continue; // skip entities without NIT (less reliable)

    // Count contacts with non-generic email domains
    $dominios = [];
    $rows = $pdo->prepare("
        SELECT SUBSTRING_INDEX(c.email_contacto, '@', -1) AS dom, COUNT(*) AS c
        FROM contacto c
        WHERE c.entidad_id = ? AND c.email_contacto LIKE '%@%'
          AND c.email_contacto NOT LIKE '%@gmail.%'
          AND c.email_contacto NOT LIKE '%@hotmail.%'
          AND c.email_contacto NOT LIKE '%@yahoo.%'
          AND c.email_contacto NOT LIKE '%@outlook.%'
          AND c.email_contacto NOT LIKE '%@live.%'
        GROUP BY dom
        ORDER BY c DESC
    ");
    $rows->execute([$eid]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) >= 1) {
        // Pick dominant domain (if there's clear winner)
        $top = $rows[0];
        if ($top["c"] >= 2) {
            $inferDominio[] = [
                "entidad_id" => $eid,
                "nombre" => $e["nombre"],
                "nit" => $e["identificacion"],
                "dominio_actual" => "(NULL)",
                "dominio_inferido" => $top["dom"],
                "contactos" => $top["c"],
            ];
        } else {
            $noInferible[] = [
                "entidad_id" => $eid,
                "nombre" => $e["nombre"],
                "motivo" => "Solo 1 contacto con dominio no-genérico",
            ];
        }
    } else {
        $noInferible[] = [
            "entidad_id" => $eid,
            "nombre" => $e["nombre"],
            "motivo" => "Sin contactos con dominio no-genérico",
        ];
    }
}
printf("  Entidades con dominio inferible: %d\n", count($inferDominio));
foreach (array_slice($inferDominio, 0, 15) as $i) {
    printf("    [%s] %s — %s → %s (NIT %s, %d contactos)\n",
        $i["entidad_id"], substr($i["nombre"], 0, 35),
        $i["dominio_actual"], $i["dominio_inferido"],
        $i["nit"], $i["contactos"]);
}
if (count($inferDominio) > 15) echo "    ... y " . (count($inferDominio) - 15) . " más\n";

printf("\n  Entidades sin dominio inferible: %d\n", count($noInferible));
foreach (array_slice($noInferible, 0, 10) as $i) {
    printf("    [%s] %s — %s\n", $i["entidad_id"], substr($i["nombre"], 0, 35), $i["motivo"]);
}

// ─────────────────────────────────────────────────────────
// STEP 5: Entidades duplicadas (por nombre normalizado)
// ─────────────────────────────────────────────────────────
echo "\nSTEP 5: Entidades duplicadas (mismo nombre normalizado)\n\n";
$dupPorNombre = [];
foreach ($entidades as $eid => $e) {
    $norm = normalize($e["nombre"]);
    $dupPorNombre[$norm][] = $eid;
}
ksort($dupPorNombre);

$duplicates = [];
foreach ($dupPorNombre as $norm => $ids) {
    if (count($ids) > 1) {
        $duplicates[$norm] = $ids;
    }
}

printf("Total nombres normalizados con duplicados: %d\n", count($duplicates));
foreach (array_slice($duplicates, 0, 15, true) as $norm => $ids) {
    echo "  '$norm' → entidades: " . implode(", ", $ids) . "\n";
}
if (count($duplicates) > 15) echo "  ... y " . (count($duplicates) - 15) . " más\n";

// ─────────────────────────────────────────────────────────
// STEP 6: Resumen final
// ─────────────────────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  RESUMEN DE ACCIONES A EJECUTAR\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "1. Reasignar opps: " . count($oppReasign) . "\n";
echo "2. Inferir dominio para entidades: " . count($inferDominio) . "\n";
echo "3. Consolidar entidades duplicadas: " . count($duplicates) . " nombres normalizados\n";

echo "\nPRIORIDAD DE ACCIÓN:\n";
echo "  ALTA:   Reasignar opps con match claro (CSV → canonical_id conocido)\n";
echo "  ALTA:   Inferir dominio para entidades con NIT + múltiples contactos\n";
echo "  MEDIA:  Consolidar duplicados de POLINTER (14) y otros nombres repetidos\n";
echo "  BAJA:   Investigar manualmente los sin dominio inferible\n";
