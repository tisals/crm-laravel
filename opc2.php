<?php
$s = opcache_get_status(true);
echo "opcache_enabled: " . ($s['opcache_enabled'] ? 'YES' : 'NO') . PHP_EOL;
echo "validate_timestamps: " . ($s['directives']['opcache.validate_timestamps'] ? 'YES' : 'NO') . PHP_EOL;
echo "revalidate_freq: " . $s['directives']['opcache.revalidate_freq'] . PHP_EOL;
echo "memory_consumption: " . $s['memory_usage']['used_memory'] . ' / ' . $s['memory_usage']['free_memory'] . PHP_EOL;
$controller = '/var/www/html/app/Http/Controllers/API/AuthController.php';
echo "AuthController in cache: " . (isset($s['scripts'][$controller]) ? 'YES' : 'NO') . PHP_EOL;
if (isset($s['scripts'][$controller])) {
    echo "  hits: " . $s['scripts'][$controller]['hits'] . PHP_EOL;
    echo "  timestamp: " . date('Y-m-d H:i:s', $s['scripts'][$controller]['timestamp']) . PHP_EOL;
}
