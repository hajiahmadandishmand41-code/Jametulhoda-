<?php

declare(strict_types=1);

/**
 * Front Controller — single entry point of the public site (Phase 1).
 *
 * Request flow:
 *   Browser -> .htaccess (mod_rewrite) -> index.php
 *     1. load helpers + config (+ database layer)
 *     2. hand over allowed static files to the built-in server (php -S only;
 *        on Apache, .htaccess serves real files directly)
 *     3. dispatch the URL against the route table (router.php)
 *
 * NOTE: Router::dispatch() MUST stay the last statement of this file.
 */

require __DIR__ . '/app/Helpers/functions.php';
require __DIR__ . '/config/config.php';
require __DIR__ . '/app/Router.php';
require __DIR__ . '/config/database.php';

/* ---------------------------------------------------------------------------
 | Error handling baseline
 | - production: errors are logged, never shown to the user
 | - development: uncaught exceptions are printed (local work only)
 * ------------------------------------------------------------------------- */
if (!Config::isDebug()) {
    ini_set('display_errors', '0');
}
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e): void {
    log_error(sprintf(
        'Uncaught %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (http_response_code() === 200) {
        http_response_code(500);
    }

    if (Config::isDebug()) {
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    }

    exit('Internal Server Error');
});

/* ---------------------------------------------------------------------------
 | Resolve the request path
 * ------------------------------------------------------------------------- */
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH) ?? '/';
$path = rawurldecode((string) $path);

// Normalize: collapse duplicate slashes, strip the trailing slash (root keeps '/')
$path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');

// The front controller addressed directly is the home route
// (on Apache, /index.php is served as a real file and behaves the same)
if ($path === '/index.php') {
    $path = '/';
}

/* ---------------------------------------------------------------------------
 | Static files
 | On Apache, .htaccess serves real files, so they never reach this point.
 | This branch exists so `php -S` (local dev + automated HTTP tests) behaves
 | the same way. Only files under /assets or /uploads are ever handed over —
 | config/, app/, pages/ ... can NEVER be served from PHP (path-traversal
 | attempts fall through to the router and get a 404).
 * ------------------------------------------------------------------------- */
if ($path !== '/' && is_file(__DIR__ . $path)) {
    $resolved = realpath(__DIR__ . $path) ?: '';
    $insideAssets = $resolved !== ''
        && str_starts_with($resolved, realpath(__DIR__ . '/assets') . DIRECTORY_SEPARATOR);
    $insideUploads = $resolved !== ''
        && str_starts_with($resolved, realpath(__DIR__ . '/uploads') . DIRECTORY_SEPARATOR);

    if (($insideAssets || $insideUploads) && PHP_SAPI === 'cli-server') {
        return false; // let the built-in server serve the static file
    }
}

/* ---------------------------------------------------------------------------
 | Dispatch (keep this the last statement)
 * ------------------------------------------------------------------------- */
$router = new Router();
require __DIR__ . '/router.php';
define_routes($router);

$router->dispatch((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path);
