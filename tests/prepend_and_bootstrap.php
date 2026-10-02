<?php

/*
|--------------------------------------------------------------------------
| Bootstrap The Test Environment
|--------------------------------------------------------------------------
|
| Forces test DB env vars BEFORE vendor/autoload triggers Laravel's bootstrap.
| This is the only reliable way to override Laravel's immutable Dotenv reader,
| which would otherwise load .env's `DB_DATABASE=minerva` and break tests.
|
| Originally tests/bootstrap.php was the bootstrap file, but the load order
| meant Laravel's LoadEnvironmentVariables ran before our putenv() calls could
| take effect. Replacing the phpunit.xml bootstrap attribute with THIS file
| (loaded as the first thing phpunit executes) is what made the override stick.
|
| See verify-report-v3 for the catastrophic 2026-08-18 regression this fix
| prevents.
|
*/

$testEnv = [
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => 'mariadb',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'crm_testing',
    'DB_USERNAME' => 'root',
    'DB_PASSWORD' => 'sailus_root_dev',
    'APP_ENV' => 'testing',
];

foreach ($testEnv as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

// Now load composer autoload + Laravel bootstrap.
require __DIR__.'/../vendor/autoload.php';