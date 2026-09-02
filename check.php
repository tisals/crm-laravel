<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\Usuario::where('email', 'admin@tecnoinnsoft.dev')->first();
echo 'User id=' . $u->id . ' estado=' . $u->estado . PHP_EOL;
echo 'Hash: ' . $u->password_hash . PHP_EOL;
echo 'password_verify("password", hash): ' . (password_verify('password', $u->password_hash) ? 'YES' : 'NO') . PHP_EOL;
