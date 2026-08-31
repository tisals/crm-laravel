<?php

/*
|--------------------------------------------------------------------------
| Bootstrap The Test Environment — replaced by prepend_and_bootstrap.php
|--------------------------------------------------------------------------
|
| This file is kept as a fallback / documentation of the 2026-08-18 incident
| where tests ran against the production `minerva` DB.
|
| Active bootstrap is `prepend_and_bootstrap.php` (see phpunit.xml).
| That file forces test DB env vars BEFORE vendor/autoload.php runs Laravel's
| LoadEnvironmentVariables bootstrapper, which is the only way to override
| the immutable Dotenv reader that reads .env's `DB_DATABASE=minerva`.
|
*/

$testEnv = [
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => 'mariadb',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'crm_testing',
    'DB_USERNAME' => 'root',
    'DB_PASSWORD' => 'sailus_root_dev',
];

foreach ($testEnv as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

require __DIR__.'/../vendor/autoload.php';
