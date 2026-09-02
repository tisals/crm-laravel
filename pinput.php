<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';

// Get the actual php://input content during request handling
// by adding a console test that uses the request capture
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$ref = new ReflectionMethod($kernel, 'handle');
$ref->setAccessible(true);

// Simulate a real request
$body = '{"email":"admin@tecnoinnsoft.dev","password":"password"}';
$request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_LENGTH' => strlen($body),
], $body);

// Check what php://input gives BEFORE handle
echo "BEFORE handle:" . PHP_EOL;
echo "  php://input: " . file_get_contents('php://input') . PHP_EOL;
echo "  request->all(): " . json_encode($request->all()) . PHP_EOL;

echo "DONE" . PHP_EOL;
