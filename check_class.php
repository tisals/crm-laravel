<?php
require '/var/www/html/vendor/autoload.php';
require '/var/www/html/app/Http/Controllers/API/SnapshotController.php';
var_dump(class_exists('App\Http\Controllers\API\SnapshotController'));
var_dump(method_exists('App\Http\Controllers\API\SnapshotController', 'users'));
// Try to instantiate
try {
    $instance = new App\Http\Controllers\API\SnapshotController(app(App\Application\UseCases\Usuario\ListUsersForSnapshotUseCase::class));
    echo "Instantiated OK\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}