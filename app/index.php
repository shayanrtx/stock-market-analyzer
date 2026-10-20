<?php

declare(strict_types=1);

// PHP's built-in server (php -S localhost:8000 index.php): serve the public assets directly.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (str_starts_with($path, '/assets/') && is_file(__DIR__ . $path) && !str_contains($path, '..')) {
        return false;
    }
}

// Front controller: every request is rewritten here by .htaccess.
require __DIR__ . '/src/bootstrap.php';

Sabadgardan\App::create(__DIR__)->run();
