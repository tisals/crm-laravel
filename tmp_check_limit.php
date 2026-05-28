<?php
$composer = json_decode(file_get_contents('/var/www/html/composer.json'), true);
echo 'laravel: ' . ($composer['require']['laravel/framework'] ?? '?') . PHP_EOL;

$classes = [
    'Illuminate\Cache\RateLimiter\Limit',
    'Illuminate\Routing\Middleware\Limit',
    'Illuminate\Cache\RateLimiting\Limit',
];
foreach ($classes as $c) {
    echo $c . ': ' . (class_exists($c) ? 'EXISTS' : 'NOT FOUND') . PHP_EOL;
}

// Check the real signature of the for method
$ref = new ReflectionMethod('Illuminate\Support\Facades\RateLimiter', 'for');
echo "\nRateLimiter::for signature:\n";
echo $ref->getFileName() . ':' . $ref->getStartLine() . PHP_EOL;
$params = $ref->getParameters();
foreach ($params as $p) {
    echo "  Param: {$p->getName()} - Type: " . ($p->getType() ? $p->getType()->getName() : 'none') . PHP_EOL;
}

// Check the composer autoload for the Limit class
echo "\nComposer loading Limit:\n";
$files = glob('/var/www/html/vendor/laravel/framework/src/Illuminate/**/Limit.php');
foreach ($files as $f) {
    echo "  $f\n";
}
