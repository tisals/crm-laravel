<?php
/**
 * Comprehensive data integrity audit for oportunidades/entidades/contactos.
 *
 * Detects:
 *   - Entidades sin oportunidades (probables shells o huérfanas)
 *   - Entidades sin contactos (probables entidades mal creadas)
 *   - Entidades con dominio NULL o vacío
 *   - Entidades con dominios mezclados en sus contactos (merge malo)
 *   - Entidades con emails que NO matchean su dominio (probables orphans)
 *   - Entidades con >5 oportunidades (candidatas para revisión)
 *   - Contactos sin entidad (huérfanos)
 *   - Contactos con email duplicado en múltiples entidades
 *   - Oportunidades con entidad inconsistente vs CSV
 *   - Codigos en CSV que NO están en BD (missing)
 *   - Codigos en BD que NO están en CSV (extras)
 *
 * Usage: php database/fixes/audit-data-integrity.php [--csv=/path/to/oportunidades.csv]
 */

$pdo = new PDO("mysql:host=prod_mariabd;dbname=crm_prod", "sailusdb", getenv("DBPW"));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$csvPath = "/var/www/html/database/csv/oportunidades.csv";
foreach ($argv as $a) {
    if (str_starts_with($a, "--csv=")) $csvPath = substr($a, 6);
}
if (!file_exists($csvPath)) {
    die("ERROR: $csvPath not found\n");
}

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

function extractDomain(string $email): ?string {
    $parts = explode("@", $email);
    return $parts[1] ?? null;
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  AUDITORÍA DE INTEGRIDAD DE DATOS — crm_prod\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─────────────────────────────────────────────────────────
// 1. RESUMEN EJECUTIVO
// ─────────────────────────────────────────────────────────
echo "## 1. RESUMEN EJECUTIVO\n\n";

$stats = [
    ['Entidades TOTAL', "SELECT COUNT(*) FROM entidad"],
    ['Entidades con dominio', "SELECT COUNT(*) FROM entidad WHERE dominio IS NOT NULL AND dominio != ''"],
    ['Entidades SIN dominio', "SELECT COUNT(*) FROM entidad WHERE dominio IS NULL OR dominio = ''"],
    ['Oportunidades TOTAL', "SELECT COUNT(*) FROM oportunidad"],
    ['Oportunidades is_latest=true', "SELECT COUNT(*) FROM oportunidad WHERE is_latest = TRUE"],
    ['Oportunidades is_latest=false', "SELECT COUNT(*) FROM oportunidad WHERE is_latest = FALSE"],
    ['Contactos TOTAL', "SELECT COUNT(*) FROM contacto"],
    ['Contactos con email', "SELECT COUNT(*) FROM contacto WHERE email_contacto IS NOT NULL AND email_contacto != ''"],
];

printf("%-40s %10s\n", "Métrica", "Total");
echo str_repeat("-", 52) . "\n";
foreach ($stats as [$name, $sql]) {
    $val = $pdo->query($sql)->fetchColumn();
    printf("%-40s %10s\n", $name, number_format($val));
}
echo "\n";

// ─────────────────────────────────────────────────────────
// 2. ENTIDADES SOSPECHOSAS
// ─────────────────────────────────────────────────────────
echo "## 2. ENTIDADES SIN OPORTUNIDADES (candidatas a borrar)\n\n";
$rows = $pdo->query("
    SELECT e.id, e.nombre, e.dominio,
           (SELECT COUNT(*) FROM contacto WHERE entidad_id = e.id) AS contactos
    FROM entidad e
    LEFT JOIN oportunidad o ON o.entidad_id = e.id
    WHERE o.id IS NULL
    ORDER BY contactos DESC, e.nombre
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    printf("%-6s %-50s %-25s %8s\n", "ID", "Nombre", "Dominio", "Contactos");
    echo str_repeat("-", 92) . "\n";
    foreach ($rows as $r) {
        printf("%-6s %-50s %-25s %8s\n",
            $r["id"],
            substr($r["nombre"], 0, 49),
            substr($r["dominio"] ?? "(NULL)", 0, 24),
            $r["contactos"]
        );
    }
}
echo "\nTotal sin oportunidades: " . $pdo->query("SELECT COUNT(*) FROM entidad e LEFT JOIN oportunidad o ON o.entidad_id = e.id WHERE o.id IS NULL")->fetchColumn() . "\n\n";

echo "## 3. ENTIDADES CON MUCHAS OPORTUNIDADES (revisar posible merge)\n\n";
$rows = $pdo->query("
    SELECT e.id, e.nombre, e.dominio, COUNT(o.id) AS opps
    FROM entidad e
    JOIN oportunidad o ON o.entidad_id = e.id
    GROUP BY e.id
    HAVING opps >= 5
    ORDER BY opps DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    printf("%-6s %-50s %-25s %6s\n", "ID", "Nombre", "Dominio", "Opps");
    echo str_repeat("-", 90) . "\n";
    foreach ($rows as $r) {
        printf("%-6s %-50s %-25s %6s\n",
            $r["id"],
            substr($r["nombre"], 0, 49),
            substr($r["dominio"] ?? "(NULL)", 0, 24),
            $r["opps"]
        );
    }
}
echo "\n";

echo "## 4. ENTIDADES CON DOMINIO PERO CONTACTOS CON OTRO DOMINIO (merge malo)\n\n";
echo "Buscando entidades donde >30% de los emails NO matchean el dominio de la entidad...\n\n";
$rows = $pdo->query("
    SELECT e.id, e.nombre, e.dominio,
           COUNT(c.id) AS total_contactos,
           SUM(CASE WHEN c.email_contacto LIKE CONCAT('%@', e.dominio) THEN 1 ELSE 0 END) AS matching,
           ROUND(100 * SUM(CASE WHEN c.email_contacto LIKE CONCAT('%@', e.dominio) THEN 1 ELSE 0 END) / COUNT(c.id), 1) AS pct_match
    FROM entidad e
    JOIN contacto c ON c.entidad_id = e.id
    WHERE e.dominio IS NOT NULL AND e.dominio != ''
      AND c.email_contacto IS NOT NULL AND c.email_contacto != ''
    GROUP BY e.id, e.dominio
    HAVING COUNT(c.id) >= 3 AND pct_match < 70
    ORDER BY pct_match ASC, total_contactos DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    printf("%-6s %-45s %-20s %8s %8s %8s\n", "ID", "Nombre", "Dominio", "Total", "Match", "%");
    echo str_repeat("-", 100) . "\n";
    foreach ($rows as $r) {
        printf("%-6s %-45s %-20s %8s %8s %8s\n",
            $r["id"],
            substr($r["nombre"], 0, 44),
            substr($r["dominio"], 0, 19),
            $r["total_contactos"],
            $r["matching"],
            $r["pct_match"] . "%"
        );
    }
}
echo "\n";

echo "## 5. ENTIDADES CON DOMINIOS MIXTOS EN CONTACTOS (top dominios por entidad)\n\n";
$rows = $pdo->query("
    SELECT e.id, e.nombre, e.dominio,
           COUNT(DISTINCT SUBSTRING_INDEX(c.email_contacto, '@', -1)) AS dominios_distintos,
           GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(c.email_contacto, '@', -1) SEPARATOR ', ') AS dominios
    FROM entidad e
    JOIN contacto c ON c.entidad_id = e.id
    WHERE c.email_contacto IS NOT NULL AND c.email_contacto != ''
    GROUP BY e.id
    HAVING dominios_distintos >= 3
    ORDER BY dominios_distintos DESC, e.nombre
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    foreach ($rows as $r) {
        printf("[%s] %s — %d dominios distintos: %s\n",
            $r["id"],
            $r["nombre"],
            $r["dominios_distintos"],
            $r["dominios"]
        );
    }
}
echo "\n";

// ─────────────────────────────────────────────────────────
// 6. OPORTUNIDADES INCONSISTENTES
// ─────────────────────────────────────────────────────────
echo "## 6. OPORTUNIDADES SIN CONTACTO ASIGNADO\n\n";
$total = $pdo->query("SELECT COUNT(*) FROM oportunidad WHERE contacto_id IS NULL")->fetchColumn();
echo "Total opp sin contacto_id: $total\n\n";

echo "## 7. OPORTUNIDADES CON MÚLTIPLES CONTACTOS EN DISTINTA ENTIDAD\n\n";
$rows = $pdo->query("
    SELECT o.id, o.codigo, o.entidad_id,
           (SELECT COUNT(*) FROM contacto WHERE entidad_id = o.entidad_id) AS contactos_en_entidad,
           o.estado
    FROM oportunidad o
    WHERE (SELECT COUNT(*) FROM contacto WHERE entidad_id != o.entidad_id AND email_contacto IN (
        SELECT email_contacto FROM contacto WHERE entidad_id = o.entidad_id
    )) > 0
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    foreach ($rows as $r) {
        printf("[%s] %s — entidad %s, %d contactos\n",
            $r["id"], $r["codigo"], $r["entidad_id"], $r["contactos_en_entidad"]
        );
    }
} else {
    echo "(ninguno)\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────
// 8. CONTACTOS HUÉRFANOS
// ─────────────────────────────────────────────────────────
echo "## 8. CONTACTOS SIN ENTIDAD VÁLIDA\n\n";
$total = $pdo->query("
    SELECT COUNT(*) FROM contacto c
    LEFT JOIN entidad e ON c.entidad_id = e.id
    WHERE e.id IS NULL
")->fetchColumn();
echo "Total: $total\n\n";

echo "## 9. EMAILS DUPLICADOS EN MÚLTIPLES ENTIDADES\n\n";
$rows = $pdo->query("
    SELECT c.email_contacto,
           COUNT(DISTINCT c.entidad_id) AS entidades_distintas,
           GROUP_CONCAT(DISTINCT c.entidad_id) AS entidades
    FROM contacto c
    WHERE c.email_contacto IS NOT NULL AND c.email_contacto != ''
    GROUP BY c.email_contacto
    HAVING COUNT(DISTINCT c.entidad_id) > 1
    ORDER BY entidades_distintas DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    foreach ($rows as $r) {
        printf("%s → %d entidades (ids: %s)\n",
            $r["email_contacto"],
            $r["entidades_distintas"],
            $r["entidades"]
        );
    }
} else {
    echo "(ninguno)\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────
// 10. CROSS-CHECK CON CSV
// ─────────────────────────────────────────────────────────
echo "## 10. CROSS-CHECK CON CSV (oportunidades.csv)\n\n";

// Cargar CSV: agrupar por codigo + empresa canonica
echo "Cargando CSV...\n";
$csvByCodigo = [];
$fh = fopen($csvPath, "r");
$header = fgetcsv($fh, 0, ";");
while (($row = fgetcsv($fh, 0, ";")) !== false) {
    if (count($row) < 14) continue;
    $codigo = trim($row[0] ?? '');
    $empresa = trim($row[13] ?? '');
    if (! $codigo || ! $empresa) continue;
    if (! isset($csvByCodigo[$codigo])) {
        $csvByCodigo[$codigo] = normalizeEntityName($empresa);
    }
}
fclose($fh);
echo "CSV rows únicos: " . count($csvByCodigo) . "\n\n";

// Opp en DB con entidad inconsistente
echo "10a. Opp en BD cuya entidad NO matchea la del CSV:\n";
$inconsistent = 0;
$rows = $pdo->query("
    SELECT o.id, o.codigo, e.nombre AS entidad_actual, COUNT(c.id) AS contactos
    FROM oportunidad o
    JOIN entidad e ON o.entidad_id = e.id
    LEFT JOIN contacto c ON c.entidad_id = e.id
    GROUP BY o.id
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    if (isset($csvByCodigo[$r["codigo"]])) {
        $csvName = $csvByCodigo[$r["codigo"]];
        $dbName = normalizeEntityName($r["entidad_actual"]);
        if ($csvName !== $dbName) {
            $inconsistent++;
            if ($inconsistent <= 10) {
                printf("  [%s] %s → entidad '%s' (CSV dice '%s')\n",
                    $r["id"], $r["codigo"], $r["entidad_actual"], $csvName
                );
            }
        }
    }
}
echo "Total inconsistentes: $inconsistent (mostrando primeros 10)\n\n";

// Codigos en CSV que NO están en BD
echo "10b. Codigos en CSV sin opp en BD:\n";
$existingCodigos = [];
foreach ($pdo->query("SELECT codigo FROM oportunidad")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $existingCodigos[$r["codigo"]] = true;
}
$missing = 0;
foreach ($csvByCodigo as $codigo => $empresa) {
    if (! isset($existingCodigos[$codigo])) {
        $missing++;
        if ($missing <= 5) {
            printf("  %s — debería estar en entidad '%s'\n", $codigo, $empresa);
        }
    }
}
echo "Total missing: $missing (mostrando primeros 5)\n\n";

// Codigos en BD que NO están en CSV
echo "10c. Codigos en BD sin row en CSV:\n";
$notInCsv = 0;
foreach ($existingCodigos as $codigo => $_) {
    if (! isset($csvByCodigo[$codigo])) {
        $notInCsv++;
        if ($notInCsv <= 5) {
            printf("  %s\n", $codigo);
        }
    }
}
echo "Total not-in-CSV: $notInCsv (mostrando primeros 5)\n\n";

// ─────────────────────────────────────────────────────────
// 11. CASO ESPECIAL: Contacto con dominio @XXX vs entidad con dominio YYY
// ─────────────────────────────────────────────────────────
echo "## 11. CASO ESPECIAL: contactos que parecen ser de OTRA entidad\n\n";
echo "(contacto con email de dominio X, pero entidad dice Y — útil para detectar\ncontactos mal asignados por merge)\n\n";
$rows = $pdo->query("
    SELECT c.id, c.email_contacto, c.entidad_id, e.nombre AS entidad_nombre,
           SUBSTRING_INDEX(c.email_contacto, '@', -1) AS email_domain
    FROM contacto c
    JOIN entidad e ON c.entidad_id = e.id
    WHERE e.dominio IS NOT NULL AND e.dominio != ''
      AND c.email_contacto LIKE '%@%'
      AND c.email_contacto NOT LIKE CONCAT('%@', e.dominio)
      AND c.email_contacto NOT LIKE '%@gmail.%'
      AND c.email_contacto NOT LIKE '%@hotmail.%'
      AND c.email_contacto NOT LIKE '%@yahoo.%'
      AND c.email_contacto NOT LIKE '%@outlook.%'
      AND c.email_contacto NOT LIKE '%@live.%'
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    foreach ($rows as $r) {
        printf("  contacto[%s] email=%s (dom=%s) → entidad '%s'\n",
            $r["id"], $r["email_contacto"], $r["email_domain"], $r["entidad_nombre"]
        );
    }
} else {
    echo "(ninguno)\n";
}
echo "\n";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  FIN DE AUDITORÍA\n";
echo "═══════════════════════════════════════════════════════════════════\n";