<?php
/**
 * Deep diagnostic for entity-contact mismatches.
 * For each contacto, check if its email domain matches the entity's dominio.
 * Find oportunidades that have contacto_id pointing to a contacto whose
 * domain doesn't match the opp's entity's domain.
 *
 * Usage: php storage/app/diagnose-contact-entity.php
 */

$host = getenv("DB_HOST") ?: "prod_mariabd";
$db = getenv("DB_NAME") ?: "crm_prod";
$user = getenv("DB_USER") ?: "sailusdb";
$pw = getenv("DBPW");
$pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pw);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== 1. TOP 20 ENTIDADES CON CONTACTOS DE DOMINIOS MEZCLADOS ===\n\n";
$rows = $pdo->query("
    SELECT e.id, e.nombre, e.dominio,
           COUNT(c.id) AS total,
           SUM(CASE WHEN c.email_contacto LIKE CONCAT('%@', e.dominio) THEN 1 ELSE 0 END) AS matching,
           COUNT(DISTINCT SUBSTRING_INDEX(c.email_contacto, '@', -1)) AS dominios_distintos
    FROM entidad e
    JOIN contacto c ON c.entidad_id = e.id
    WHERE c.email_contacto IS NOT NULL AND c.email_contacto != ''
      AND e.dominio IS NOT NULL AND e.dominio != ''
    GROUP BY e.id
    HAVING dominios_distintos >= 2
    ORDER BY total DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

printf("%-6s %-40s %-20s %6s %6s %4s\n", "ID", "Nombre", "Dominio", "Total", "Match", "#D");
echo str_repeat("-", 88) . "\n";
foreach ($rows as $r) {
    $pct = $r["total"] > 0 ? round(100 * $r["matching"] / $r["total"], 0) : 0;
    printf("%-6s %-40s %-20s %6s %6s %4s\n",
        $r["id"],
        substr($r["nombre"], 0, 39),
        substr($r["dominio"], 0, 19),
        $r["total"],
        "{$pct}%",
        $r["dominios_distintos"]
    );
}

echo "\n=== 2. OPPS CUYO CONTACTO PERTENECE A OTRA ENTIDAD (mismatch contacto-entidad) ===\n\n";
echo "Para cada opp con contacto_id, verificamos si el email del contacto\n";
echo "matchea con el dominio de la entidad de la opp. Si no matchea → reasignar.\n\n";

$rows = $pdo->query("
    SELECT o.id AS opp_id, o.codigo,
           o.entidad_id AS entidad_opp, e.nombre AS entidad_opp_nombre,
           c.id AS contacto_id, c.email_contacto,
           SUBSTRING_INDEX(c.email_contacto, '@', -1) AS email_domain,
           c.entidad_id AS entidad_contacto, ec.nombre AS entidad_contacto_nombre,
           c.email_contacto NOT LIKE CONCAT('%@', IFNULL(e.dominio, '')) AS mismatched
    FROM oportunidad o
    JOIN contacto c ON c.id = o.contacto_id
    JOIN entidad e ON o.entidad_id = e.id
    JOIN entidad ec ON c.entidad_id = ec.id
    WHERE c.email_contacto IS NOT NULL AND c.email_contacto != ''
      AND e.dominio IS NOT NULL AND e.dominio != ''
      AND c.email_contacto NOT LIKE CONCAT('%@', e.dominio)
    ORDER BY o.id
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "(no mismatches found)\n";
} else {
    foreach ($rows as $r) {
        printf("  opp[%s] %s: opp-ent=%s(%s), contacto[email=%s, dom=%s] en entidad=%s(%s)\n",
            $r["opp_id"],
            $r["codigo"],
            substr($r["entidad_opp_nombre"], 0, 30),
            $r["entidad_opp"],
            $r["email_contacto"],
            $r["email_domain"],
            substr($r["entidad_contacto_nombre"], 0, 30),
            $r["entidad_contacto"]
        );
    }
    echo "\nTotal: " . count($rows) . " mismatches (limited to 50)\n";
}

echo "\n=== 3. POLINTER: TODAS LAS ENTIDADES Y SUS OPPS ===\n\n";
$rows = $pdo->query("
    SELECT e.id, e.nombre, e.dominio,
           (SELECT COUNT(*) FROM oportunidad WHERE entidad_id = e.id) AS opps,
           (SELECT COUNT(*) FROM contacto WHERE entidad_id = e.id) AS contactos,
           (SELECT COUNT(*) FROM oportunidad o
              JOIN contacto c ON c.id = o.contacto_id
              WHERE o.entidad_id = e.id 
                AND c.email_contacto LIKE '%@polinter.com.co%') AS opps_con_contacto_polinter
    FROM entidad e
    WHERE LOWER(e.nombre) LIKE '%polinter%'
       OR e.dominio = 'polinter.com.co'
    ORDER BY opps DESC
")->fetchAll(PDO::FETCH_ASSOC);

printf("%-6s %-40s %-20s %5s %5s %5s\n", "ID", "Nombre", "Dominio", "Opps", "Cont", "PolC");
echo str_repeat("-", 90) . "\n";
foreach ($rows as $r) {
    printf("%-6s %-40s %-20s %5s %5s %5s\n",
        $r["id"],
        substr($r["nombre"], 0, 39),
        substr($r["dominio"] ?? "(NULL)", 0, 19),
        $r["opps"],
        $r["contactos"],
        $r["opps_con_contacto_polinter"] ?? 0
    );
}

echo "\n=== 4. BANCO DE BOGOTÁ: ENTIDADES RELACIONADAS ===\n\n";
$rows = $pdo->query("
    SELECT e.id, e.nombre, e.dominio, e.identificacion,
           (SELECT COUNT(*) FROM oportunidad WHERE entidad_id = e.id) AS opps,
           (SELECT COUNT(*) FROM contacto WHERE entidad_id = e.id) AS contactos
    FROM entidad e
    WHERE LOWER(e.nombre) LIKE '%banco%bogot%'
       OR LOWER(e.nombre) LIKE '%bogota%'
       OR e.dominio LIKE '%bancodebogota%'
    ORDER BY opps DESC
")->fetchAll(PDO::FETCH_ASSOC);

printf("%-6s %-45s %-30s %12s %5s %5s\n", "ID", "Nombre", "Dominio", "Identificacion", "Opps", "Cont");
echo str_repeat("-", 110) . "\n";
foreach ($rows as $r) {
    printf("%-6s %-45s %-30s %12s %5s %5s\n",
        $r["id"],
        substr($r["nombre"], 0, 44),
        substr($r["dominio"] ?? "(NULL)", 0, 29),
        $r["identificacion"] ?? "(NULL)",
        $r["opps"],
        $r["contactos"]
    );
}
