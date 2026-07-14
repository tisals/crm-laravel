<?php
$pdo = new PDO("mysql:host=prod_mariabd;dbname=crm_prod", "sailusdb", getenv("DBPW"));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dryRun = in_array("--dry-run", $argv);
echo $dryRun ? "=== DRY-RUN ===\n" : "=== LIVE MODE ===\n";

$csvPath = "/var/www/html/database/csv/oportunidades.csv";
foreach ($argv as $a) { if (str_starts_with($a, "--csv=")) $csvPath = substr($a, 6); }
if (!file_exists($csvPath)) { die("ERROR: $csvPath not found\n"); }
echo "CSV: $csvPath\n\n";

function normalizeEntityName(string $name): string {
    $name = strtolower(trim($name));
    $accents = ["á"=>"a","é"=>"e","í"=>"i","ó"=>"o","ú"=>"u","ü"=>"u","ñ"=>"n"];
    $name = strtr($name, $accents);
    $name = preg_replace("/\s+/", " ", $name);
    $name = preg_replace("/\s+(s\.?a\.?s\.?|s\.?a\.?|s\.?a|ltda|ltd|e\.?u\.?l\.?l|limitada|spa|s\.?p\.?a\.?|s\.?r\.?l\.?|cia|cia\.)\.?$/i", "", $name);
    return trim($name);
}

$shells = $pdo->query("SELECT e.id, e.nombre FROM entidad e WHERE (e.dominio IS NULL OR e.dominio='') AND (SELECT COUNT(*) FROM oportunidad WHERE entidad_id=e.id)=0 AND (SELECT COUNT(*) FROM contacto WHERE entidad_id=e.id)=0")->fetchAll(PDO::FETCH_ASSOC);
$shellsByName = [];
foreach ($shells as $s) $shellsByName[normalizeEntityName($s["nombre"])] = $s["id"];

$reales = $pdo->query("SELECT e.id, e.nombre FROM entidad e WHERE (e.dominio IS NULL OR e.dominio='') AND ((SELECT COUNT(*) FROM oportunidad WHERE entidad_id=e.id)>0 OR (SELECT COUNT(*) FROM contacto WHERE entidad_id=e.id)>0)")->fetchAll(PDO::FETCH_ASSOC);
$realesByName = [];
foreach ($reales as $r) $realesByName[normalizeEntityName($r["nombre"])] = $r["id"];

echo "Shells (0 opps, 0 contactos, sin dominio): ".count($shells)."\n";
echo "Reales sin dominio: ".count($reales)."\n";

$oppsByCodigo = [];
foreach ($pdo->query("SELECT id, codigo FROM oportunidad")->fetchAll(PDO::FETCH_ASSOC) as $o) $oppsByCodigo[$o["codigo"]] = $o["id"];
$contactosByEmail = [];
foreach ($pdo->query("SELECT id, email_contacto FROM contacto WHERE email_contacto IS NOT NULL AND email_contacto !=''")->fetchAll(PDO::FETCH_ASSOC) as $c) $contactosByEmail[$c["email_contacto"]] = $c["id"];

echo "Oportunidades en BD: ".count($oppsByCodigo)."\n";
echo "Contactos con email en BD: ".count($contactosByEmail)."\n\n";

$fh = fopen($csvPath, "r");
$header = fgetcsv($fh, 0, ";");
$stats = ["reassigned_shell"=>0, "real_match"=>0, "new_entity"=>0, "skipped_with_dom"=>0, "no_opp"=>0];

while (($row = fgetcsv($fh, 0, ";")) !== false) {
    if (count($row) < 14) continue;
    $codigo = trim($row[0]);
    $empresa = trim($row[13]);
    $dominioRaw = trim($row[14] ?? '');
    $email = trim($row[9] ?? '');
    if ($dominioRaw !== "") { $stats["skipped_with_dom"]++; continue; }
    if (!$empresa) continue;
    $empresaNorm = normalizeEntityName($empresa);
    $oppId = $oppsByCodigo[$codigo] ?? null;
    $contactoId = $contactosByEmail[$email] ?? null;
    if (!$oppId) { $stats["no_opp"]++; continue; }
    $targetId = $shellsByName[$empresaNorm] ?? null;
    if ($targetId) {
        if (!$dryRun) {
            $pdo->prepare("UPDATE oportunidad SET entidad_id=? WHERE id=?")->execute([$targetId,$oppId]);
            if ($contactoId) $pdo->prepare("UPDATE contacto SET entidad_id=? WHERE id=?")->execute([$targetId,$contactoId]);
            $pdo->prepare("UPDATE entidad SET nombre=?, nombre_comercial=?, updated_at=NOW() WHERE id=?")->execute([$empresa,$empresa,$targetId]);
        }
        unset($shellsByName[$empresaNorm]);
        $stats["reassigned_shell"]++;
        continue;
    }
    if (isset($realesByName[$empresaNorm])) { $stats["real_match"]++; continue; }
    if (!$dryRun) {
        $pdo->prepare("INSERT INTO entidad (nombre, nombre_comercial, dominio, estado, created_at, updated_at) VALUES (?, ?, NULL, 'Activo', NOW(), NOW())")->execute([$empresa,$empresa]);
        $targetId = $pdo->lastInsertId();
        $realesByName[$empresaNorm] = $targetId;
        $pdo->prepare("UPDATE oportunidad SET entidad_id=? WHERE id=?")->execute([$targetId,$oppId]);
        if ($contactoId) $pdo->prepare("UPDATE contacto SET entidad_id=? WHERE id=?")->execute([$targetId,$contactoId]);
    }
    $stats["new_entity"]++;
}
fclose($fh);

echo "=== A1 — Process sin-dominio ===\n";
foreach ($stats as $k=>$v) echo "  $k: $v\n";

$remaining = $pdo->query("SELECT e.id, e.nombre FROM entidad e WHERE (e.dominio IS NULL OR e.dominio='') AND (SELECT COUNT(*) FROM oportunidad WHERE entidad_id=e.id)=0 AND (SELECT COUNT(*) FROM contacto WHERE entidad_id=e.id)=0")->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== A2 — Shells huérfanas (a borrar) ===\n";
echo "Total: ".count($remaining)."\n";
foreach ($remaining as $s) echo "  [SHELL] id={$s['id']} {$s['nombre']}\n";

if (!$dryRun && count($remaining)>0) {
    $ids = array_column($remaining, "id");
    $ph = implode(",", array_fill(0, count($ids), "?"));
    $pdo->prepare("DELETE FROM entidad WHERE id IN ($ph)")->execute($ids);
    echo "  → Borradas: ".count($ids)."\n";
}

