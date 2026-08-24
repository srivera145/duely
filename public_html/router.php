<?php

/**
 * Router for PHP's built-in server.
 *
 * `php -S host:port -t public_html public_html/router.php`
 *
 * Mirrors the .htaccess rule: a request that names a real file is served as
 * that file, anything else goes through the front controller. Without this,
 * `php -S ... index.php` sends every request — stylesheets, scripts, favicons —
 * into the router, which 404s them. That makes a local Lighthouse run measure
 * a page with no CSS and no JavaScript, and score it beautifully.
 *
 * Development and measurement only. Apache serves the real thing.
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file) && !str_starts_with(basename($file), '.')) {
    // Returning false hands the file back to the built-in server, which sets
    // the content type itself.
    return false;
}

require __DIR__ . '/index.php';
