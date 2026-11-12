<?php

declare(strict_types=1);

$config = [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'database' => getenv('DB_DATABASE') ?: 'arcadis_rh',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') !== false && getenv('DB_PASSWORD') !== ''
        ? (string) getenv('DB_PASSWORD')
        : 'root',
    'charset' => 'utf8mb4',
];

$local = __DIR__ . '/database.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_merge($config, $override);
    }
}

return $config;
