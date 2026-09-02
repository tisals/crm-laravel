<?php
require '/var/www/html/vendor/autoload.php';
$loader = require '/var/www/html/vendor/composer/autoload_static.php';
// Scan tests directory and add to autoloader
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/var/www/html/tests'));
$map = [];
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        $contents = file_get_contents($file);
        if (preg_match('/namespace\s+([^;]+);.*class\s+(\w+)/s', $contents, $m)) {
            $class = trim($m[1]) . '\\' . trim($m[2]);
            $map[$class] = $file->getPathname();
        }
    }
}
$loader->addClassMap($map);
echo 'Added ' . count($map) . ' classes' . PHP_EOL;
