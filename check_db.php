<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "config DB: " . config('database.connections.mysql.database') . PHP_EOL;
echo "config Host: " . config('database.connections.mysql.host') . PHP_EOL;
echo "config Port: " . config('database.connections.mysql.port') . PHP_EOL;
echo "env DB_DATABASE: " . env('DB_DATABASE') . PHP_EOL;
echo "env DB_HOST: " . env('DB_HOST') . PHP_EOL;
echo "PHP_EOL";
echo "Users in this DB:" . PHP_EOL;
$users = Illuminate\Support\Facades\DB::table('usuarios')->get(['id', 'email', 'estado']);
foreach ($users as $u) {
    echo "  - id={$u->id} email={$u->email} estado={$u->estado}" . PHP_EOL;
}
echo "Total: " . $users->count() . PHP_EOL;