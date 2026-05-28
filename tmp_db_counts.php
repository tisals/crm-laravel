<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

// Disable DomPDF provider
$app->instance('dompdf', null);

echo "=== TABLE COUNTS ===" . PHP_EOL;
echo "entidad: " . DB::table('entidad')->count() . PHP_EOL;
echo "contacto: " . DB::table('contacto')->count() . PHP_EOL;
echo "oportunidad: " . DB::table('oportunidad')->count() . PHP_EOL;
echo "detalle_oportunidad: " . DB::table('detalle_oportunidad')->count() . PHP_EOL;
echo "productos: " . DB::table('productos')->count() . PHP_EOL;

echo PHP_EOL . "=== ENTIDAD ESTADOS ===" . PHP_EOL;
$estados = DB::table('entidad')->selectRaw('estado, count(*) as cnt')->groupBy('estado')->get();
foreach ($estados as $e) {
    echo "  {$e->estado}: {$e->cnt}" . PHP_EOL;
}

echo PHP_EOL . "=== SAMPLE ENTITIES (limit 10) ===" . PHP_EOL;
$entities = DB::table('entidad')->limit(10)->get();
foreach ($entities as $e) {
    echo "id:{$e->id} tipo_id:{$e->tipo_id} identificacion:{$e->identificacion} nombre:{$e->nombre} estado:{$e->estado} dominio:{$e->dominio} linea_negocio:{$e->linea_negocio}" . PHP_EOL;
}

echo PHP_EOL . "=== ENTITIES WITH 'Propia' ESTADO ===" . PHP_EOL;
$propia = DB::table('entidad')->where('estado', 'Propia')->get();
foreach ($propia as $e) {
    echo "id:{$e->id} nombre:{$e->nombre} dominio:{$e->dominio}" . PHP_EOL;
}

echo PHP_EOL . "=== SAMPLE CONTACTOS ===" . PHP_EOL;
$contactos = DB::table('contacto')->limit(5)->get();
foreach ($contactos as $c) {
    echo "id:{$c->id} entidad_id:{$c->entidad_id} nombres:{$c->nombres} apellidos:{$c->apellidos} email:{$c->email_contacto}" . PHP_EOL;
}

echo PHP_EOL . "=== SAMPLE OPORTUNIDADES ===" . PHP_EOL;
$ops = DB::table('oportunidad')->limit(5)->get();
foreach ($ops as $o) {
    echo "id:{$o->id} codigo:{$o->codigo} entidad_id:{$o->entidad_id} contacto_id:{$o->contacto_id} fecha:{$o->fecha} estado:{$o->estado}" . PHP_EOL;
}

echo PHP_EOL . "=== PRODUCTOS SAMPLE ===" . PHP_EOL;
$productos = DB::table('productos')->limit(10)->get();
foreach ($productos as $p) {
    echo "id:{$p->id} nombre:{$p->nombre} linea_negocio:{$p->linea_negocio} iva:{$p->iva} estado:{$p->estado}" . PHP_EOL;
}
