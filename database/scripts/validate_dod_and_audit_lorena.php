<?php
// Validación DOD + audit Lorena
// Resolve paths relative to project root, not this script's dir
$basePath = dirname(__DIR__, 2); // scripts/ is in database/, so go up 2 levels
require $basePath . '/vendor/autoload.php';
$app = require $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== DOD VALIDATION ===" . PHP_EOL;
$opsOver = DB::select("SELECT COUNT(*) as c FROM (SELECT entidad_id FROM oportunidad WHERE entidad_id IS NOT NULL GROUP BY entidad_id HAVING COUNT(*) > 10) t");
echo "Entidades con >10 ops: " . $opsOver[0]->c . PHP_EOL;
$contactosOver = DB::select("SELECT COUNT(*) as c FROM (SELECT entidad_id FROM contacto WHERE entidad_id IS NOT NULL GROUP BY entidad_id HAVING COUNT(*) > 10) t");
echo "Entidades con >10 contactos: " . $contactosOver[0]->c . PHP_EOL;
echo "Total ops: " . DB::table("oportunidad")->count() . PHP_EOL;
echo "Total contactos: " . DB::table("contacto")->count() . PHP_EOL;
echo "Total entidades: " . DB::table("entidad")->count() . PHP_EOL;

echo PHP_EOL . "=== TOP 5 ENTIDADES CON MAS OPS ===" . PHP_EOL;
$top = DB::select("SELECT e.nombre, COUNT(o.id) as total FROM oportunidad o JOIN entidad e ON e.id = o.entidad_id GROUP BY o.entidad_id, e.nombre ORDER BY total DESC LIMIT 5");
foreach ($top as $r) {
    echo "  {$r->nombre}: {$r->total} ops" . PHP_EOL;
}

echo PHP_EOL . "=== AUDIT LORENA BERNAL (1-24 enero 2026) ===" . PHP_EOL;
$lorena = DB::select("SELECT u.id, u.nombre, u.email FROM usuarios u WHERE u.email LIKE '%lorena%' OR u.nombre LIKE '%Lorena%'");
foreach ($lorena as $u) {
    echo "  Usuario: {$u->nombre} (id={$u->id}, email={$u->email})" . PHP_EOL;
}
$lorenaOps = DB::select("
    SELECT COUNT(*) as total
    FROM oportunidad o
    JOIN entidad_usuario eu ON eu.entidad_id = o.entidad_id
    JOIN usuarios u ON u.id = eu.usuario_id
    WHERE u.email = 'gestorcomercial.tis@gmail.com'
      AND o.fecha BETWEEN '2026-01-01' AND '2026-01-24'
");
echo "  Ops asignadas (vía entidad_usuario) ene 1-24: " . ($lorenaOps[0]->total ?? 0) . PHP_EOL;
$lorenaContactos = DB::select("
    SELECT COUNT(*) as total
    FROM contacto c
    JOIN entidad_usuario eu ON eu.entidad_id = c.entidad_id
    JOIN usuarios u ON u.id = eu.usuario_id
    WHERE u.email = 'gestorcomercial.tis@gmail.com'
      AND (c.created_at BETWEEN '2026-01-01' AND '2026-01-24' OR c.created_at IS NULL)
");
echo "  Contactos asignados (vía entidad_usuario) ene 1-24: " . ($lorenaContactos[0]->total ?? 0) . PHP_EOL;

echo PHP_EOL . "=== DISTRIBUCION DE ASIGNACIONES POR USUARIO ===" . PHP_EOL;
$dist = DB::select("
    SELECT u.nombre, u.email, COUNT(DISTINCT eu.entidad_id) as entidades
    FROM entidad_usuario eu
    JOIN usuarios u ON u.id = eu.usuario_id
    GROUP BY u.id, u.nombre, u.email
    ORDER BY entidades DESC
");
foreach ($dist as $r) {
    echo "  {$r->nombre} ({$r->email}): {$r->entidades} entidades asignadas" . PHP_EOL;
}
