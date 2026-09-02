<?php

use App\Application\Services\RbacService;
use App\Models\Permiso;
use App\Models\Usuario;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$rbac = app(RbacService::class);
echo "Has Permission (1, 'entidades.index'): ".($rbac->hasPermission(1, 'entidades.index') ? 'YES' : 'NO')."\n";
echo "Has Permission (4, 'entidades.index'): ".($rbac->hasPermission(4, 'entidades.index') ? 'YES' : 'NO')."\n";

$permisos1 = Permiso::where('rol_id', 1)->get();
echo 'Permisos for rol 1: '.json_encode($permisos1->pluck('vista'))."\n";

$permisos4 = Permiso::where('rol_id', 4)->get();
echo 'Permisos for rol 4: '.json_encode($permisos4->pluck('vista'))."\n";

$user = Usuario::where('email', 'innovacionydesarrollo.tis@gmail.com')->first();
if ($user) {
    echo 'Alejandro rol_id: '.$user->rol_id."\n";
} else {
    echo "Alejandro not found\n";
}

$user2 = Usuario::find(4);
if ($user2) {
    echo 'User 4 email: '.$user2->email.' rol_id: '.$user2->rol_id."\n";
}
