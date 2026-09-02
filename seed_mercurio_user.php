<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Create rol 1 (Admin) if doesn't exist
$adminRol = \Illuminate\Support\Facades\DB::table('roles')->where('id', 1)->first();
if (! $adminRol) {
    \Illuminate\Support\Facades\DB::table('roles')->insert([
        'id' => 1,
        'nombre' => 'Admin',
        'estado' => 'Activo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo "Created Admin rol with id=1" . PHP_EOL;
} else {
    echo "Admin rol already exists with id=1" . PHP_EOL;
}

// Create mercurio service-account user
$user = \App\Models\Usuario::firstOrCreate(
    ['email' => 'mercurio-sync@mercurio.dev'],
    [
        'nombre' => 'Mercurio Sync Service',
        'password_hash' => bcrypt('mercurio-sync-dev-secret'),
        'rol_id' => 1,
        'estado' => 'Activo',
    ]
);
echo "Service user: id={$user->id} email={$user->email} rol_id={$user->rol_id}" . PHP_EOL;