<?php

/*
 * Configuration template. The web installer writes config/config.php for you; copy this file only
 * for a manual setup. config/config.php holds secrets (database password, cron token): never commit it.
 */
return [
    'debug' => false,

    'db' => [
        'driver' => 'sqlite',                         // sqlite (zero setup) | mysql
        'sqlite_path' => 'storage/database.sqlite',   // relative to the app folder
        'mysql' => [
            'host' => 'localhost',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
    ],

    'market' => [
        'timeout' => 15,            // seconds per request to tsetmc / tse.ir
        'max_retries' => 2,
        'transport' => 'curl',      // curl | demo (synthetic market data, for trying the app outside Iran)
    ],

    'live' => [
        'interval' => 45,           // seconds between monitoring cycles during market hours
    ],

    // Random secret for https://your-site/cron?token=... (generate with: php -r "echo bin2hex(random_bytes(16));")
    'cron_token' => '',
];
