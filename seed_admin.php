<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Roles table is also empty. Need to create admin rol first.
$rol = App\Models\Rol::firstOrCreate(['nombre' => 'Admin', 'estado' => 'Activo']);

$u = App\Models\Usuario::create([
    'nombre' => 'Admin Principal',
    'email' => 'admin@tecnoinnsoft.dev',
    'password_hash' => bcrypt('password'),
    'rol_id' => $rol->id,
    'estado' => 'Activo',
]);
echo 'created user id=' . $u->id . ' rol_id=' . $rol->id . PHP_EOL;