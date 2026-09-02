<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Simulate real curl request
$request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'REMOTE_ADDR' => '127.0.0.1',
], '{"email":"admin@tecnoinnsoft.dev","password":"password"}');

try {
    $response = $kernel->handle($request);
    echo "STATUS: " . $response->getStatusCode() . PHP_EOL;
    echo "BODY: " . $response->getContent() . PHP_EOL;
} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo "CLASS: " . get_class($e) . PHP_EOL;
}
