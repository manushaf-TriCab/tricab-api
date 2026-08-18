<?php

declare(strict_types=1);

/**
 * PHP built-in server router (`php -S … public/router.php`).
 *
 * Without this, a last path segment that contains a dot (e.g. PRODUCT.READ)
 * is treated as a missing static file. OPTIONS/DELETE never reach Slim, so
 * CORS preflight returns 404 and the Roles matrix cannot unassign permissions.
 */
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($uriPath) && $uriPath !== '' ? $uriPath : '/';

if (!str_contains($path, '..')) {
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require __DIR__ . '/index.php';
