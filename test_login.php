<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$response = Http::withHeaders([
    'Accept' => 'application/json',
])->timeout(10)->post('http://localhost/api/v1/auth/token-exchange', [
    'email' => 'mercurio-sync@mercurio.dev',
    'password' => 'mercurio-sync-dev-secret',
]);

echo "Status: " . $response->status() . PHP_EOL;
echo "Body: " . $response->body() . PHP_EOL;