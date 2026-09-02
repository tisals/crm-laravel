<?php
// Check Laravel request parsing
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/api/v1/auth/login', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
], '{"email":"admin@tecnoinnsoft.dev","password":"password"}');
echo 'Request input: ';
var_export($request->all());
echo PHP_EOL;
echo 'json: ';
var_export($request->json()->all());
echo PHP_EOL;
echo 'isJson: ' . ($request->isJson() ? 'YES' : 'NO') . PHP_EOL;
