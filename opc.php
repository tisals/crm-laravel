<?php
$s = opcache_get_status();
echo "enabled: " . ($s['opcache_enabled'] ? 'YES' : 'NO') . PHP_EOL;
