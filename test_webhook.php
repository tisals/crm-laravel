<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Override with Mercurio's real secret from .env
config([
    'webhook.mercurio.url' => 'http://mercurio-fastapi-dev:8000/api/v1/webhook/user-updated',
    'webhook.mercurio.secret' => '111acdf02fe5f8500b34e6e542968a6a83c340c43909b7c1302a94b357ad634a',
]);

// Build FLAT payload (what Mercurio expects)
$payload = [
    'event' => 'user.updated',
    'timestamp' => now()->toIso8601String(),
    'user_id' => 2,
    'email' => 'admin@tecnoinnsoft.dev',
    'nombre' => 'Test Flat',
    'estado' => 'Activo',
];

$body = json_encode($payload);
$secret = config('webhook.mercurio.secret');
$sig = 'sha256=' . hash_hmac('sha256', $body, $secret);

echo "Body: $body" . PHP_EOL;
echo "Signature: $sig" . PHP_EOL;

// Send to Mercurio
$ch = curl_init(config('webhook.mercurio.url'));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "X-CRM-Signature: $sig",
        'Host: localhost:8000',
    ],
    CURLOPT_TIMEOUT => 5,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP: $httpCode" . PHP_EOL;
echo "Response: $response" . PHP_EOL;