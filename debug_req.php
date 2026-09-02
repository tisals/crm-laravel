<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Exact reproduction of what nginx→php-fpm sends for JSON POST
$body = '{"email":"admin@tecnoinnsoft.dev","password":"password"}';

$request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'REMOTE_ADDR' => '127.0.0.1',
    'REQUEST_METHOD' => 'POST',
], $body);

echo "isJson: " . ($request->isJson() ? 'YES' : 'NO') . PHP_EOL;
echo "all(): " . json_encode($request->all()) . PHP_EOL;
echo "input('email'): " . var_export($request->input('email'), true) . PHP_EOL;
echo "json('email'): " . var_export($request->json('email'), true) . PHP_EOL;
echo "raw php://input: " . file_get_contents('php://input') . PHP_EOL;