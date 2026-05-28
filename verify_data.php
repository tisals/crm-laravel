<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Sample ciudades ===\n";
foreach (DB::table('ciudades')->limit(5)->get() as $c) {
    echo "  {$c->cod_municipio} | {$c->nombre} | {$c->departamento}\n";
}

echo "\n=== Sample entidades ===\n";
foreach (DB::table('entidad')->limit(5)->get() as $e) {
    echo "  {$e->identificacion} | {$e->nombre} | estado: {$e->estado} | tipo: {$e->tipo_persona} | ciudad: {$e->ciudad_cod}\n";
}

echo "\n=== Sample contactos (with entidad) ===\n";
foreach (DB::table('contacto')->whereNotNull('entidad_id')->limit(5)->get() as $c) {
    echo "  {$c->email_contacto} | {$c->nombres} {$c->apellidos} | entidad_id: {$c->entidad_id}\n";
}

echo "\n=== Sample productos ===\n";
foreach (DB::table('productos')->limit(5)->get() as $p) {
    echo "  {$p->nombre} | IVA: {$p->iva} | linea: {$p->linea_negocio}\n";
}

echo "\n=== Idempotency check: run seeders again ===\n";
