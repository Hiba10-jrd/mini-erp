<?php

// Explicit opt-in runner: ignore real DB environment and any cached project config.
foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'APP_CONFIG_CACHE' => __DIR__.'/__isolated_config_not_cached.php',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__).'/vendor/autoload.php';
