<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Trigger user update
$u = App\Models\Usuario::first();
$u->update(['nombre' => 'Mercurio Integration Test ' . date('H:i:s')]);
echo "user updated to: {$u->nombre}\n";