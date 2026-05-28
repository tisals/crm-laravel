<?php
// Bootstrap Laravel minimally
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Create a test request
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$request = new Request([
    'per_page' => 50,
    'page' => 1,
]);
$request->server->set('REQUEST_URI', '/api/v1/contacto');
$request->server->set('REQUEST_METHOD', 'GET');

try {
    $user = App\Models\Usuario::find(1);
    Auth::login($user);
    
    $ctrl = $app->make(App\Http\Controllers\API\ContactoController::class);
    $res = $ctrl->index($request);
    $status = $res->getStatusCode();
    $content = $res->getContent();
    echo "Status: $status\n";
    $data = json_decode($content, true);
    echo "Total contacts: " . ($data['data']['total'] ?? '?') . "\n";
    echo "Page count: " . count($data['data']['data'] ?? []) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
