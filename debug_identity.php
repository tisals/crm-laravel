<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Check user 2 directly
$conn = Illuminate\Support\Facades\DB::connection('mysql');
$user = $conn->table('usuarios')
    ->join('roles', 'usuarios.rol_id', '=', 'roles.id')
    ->where('usuarios.id', 2)
    ->whereNull('usuarios.deleted_at')
    ->select('usuarios.id', 'usuarios.email', 'usuarios.rol_id', 'roles.nombre as rol_nombre')
    ->first();
echo "user found: " . ($user ? 'YES' : 'NO') . PHP_EOL;
if ($user) {
    echo "user_id={$user->id} email={$user->email} rol={$user->rol_nombre}" . PHP_EOL;
}

// Now invoke the use case
$useCase = new App\Application\UseCases\Usuario\GetUserIdentityUseCase();
$result = $useCase->execute(2, 2);
echo "use case result: " . var_export($result, true) . PHP_EOL;