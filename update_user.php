<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\Usuario::find(2);
$u->update(['nombre' => 'Admin Updated ' . date('H:i:s')]);
echo "ok nombre=" . $u->nombre . PHP_EOL;