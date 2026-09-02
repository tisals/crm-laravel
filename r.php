<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r = new ReflectionClass('Tests\Feature\API\AuthLoginPerformanceTest');
echo 'Public methods:' . PHP_EOL;
foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    if (strpos($m->name, 'test') === 0) {
        echo '  - ' . $m->name . PHP_EOL;
    }
}