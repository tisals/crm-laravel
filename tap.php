<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';

// Add a tap to log request BEFORE handling
\Illuminate\Support\Facades\Route::matched(function ($event) {
    $req = $event->request;
    \Illuminate\Support\Facades\Log::info('REQUEST_TAP', [
        'method' => $req->method(),
        'content_type' => $req->header('Content-Type'),
        'content_length' => $req->header('Content-Length'),
        'php_input' => file_get_contents('php://input'),
        'all' => $req->all(),
    ]);
});

// Run a real kernel request
$body = '{"email":"admin@tecnoinnsoft.dev","password":"password"}';
$request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_LENGTH' => strlen($body),
], $body);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo "STATUS: " . $response->getStatusCode() . PHP_EOL;