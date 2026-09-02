<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Create some users in the DB so snapshot has data
\Illuminate\Support\Facades\DB::table('roles')->updateOrInsert(
    ['id' => 2],
    ['nombre' => 'Comercial', 'estado' => 'Activo', 'updated_at' => now(), 'created_at' => now()]
);

\App\Models\Usuario::updateOrCreate(
    ['email' => 'alpha@example.com'],
    ['nombre' => 'Test User Alpha', 'password_hash' => bcrypt('password'), 'rol_id' => 1, 'estado' => 'Activo']
);

\App\Models\Usuario::updateOrCreate(
    ['email' => 'beta@example.com'],
    ['nombre' => 'Test User Beta', 'password_hash' => bcrypt('password'), 'rol_id' => 2, 'estado' => 'Activo']
);

// 2. Get a Sanctum token for the mercurio-sync user
$user = \App\Models\Usuario::where('email', 'mercurio-sync@mercurio.dev')->first();
$token = $user->createToken('mercurio-sync-test')->plainTextToken;
echo "Token: $token" . PHP_EOL;

// 3. Test the endpoint via local HTTP
$ch = curl_init('http://localhost:80/api/v1/admin/users/snapshot');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        "Authorization: Bearer $token",
        'Host: localhost:8001',
    ],
    CURLOPT_TIMEOUT => 5,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
echo "HTTP: $httpCode" . PHP_EOL;
echo "Curl error: $err" . PHP_EOL;
echo "Response: " . $response . PHP_EOL;