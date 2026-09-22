<?php

declare(strict_types=1);

/**
 * Phase 1 — Check 5: public link audit.
 *
 * 1. Renders every public page through the real router (home + 404)
 * 2. Extracts href/src from the rendered HTML
 * 3. Internal links must either be:
 *      - a real file under /assets (must exist on disk)
 *      - a route registered in router.php
 * 4. Also scans view/layout PHP sources for hardcoded href/src
 *    (catches links on dead code paths).
 *
 * Usage: php tests/audit/check_links.php
 */

require dirname(__DIR__) . '/bootstrap.php';

$router = new Router();
define_routes($router);

// Buffer everything so http_response_code() keeps working in CLI
ob_start();

/**
 * @return array{0:int, 1:string}
 */
function render_page(Router $router, string $path): array
{
    http_response_code(200); // explicit default: CLI has no implicit 200
    ob_start();
    $router->dispatch('GET', $path);
    $body = (string) ob_get_clean();
    $code = (int) http_response_code();
    http_response_code(200);

    return [$code, $body];
}

$checked = 0;
$broken = 0;
$external = 0;

/**
 * Verify one href/src value found in a page.
 */
function check_link(
    string $href,
    Router $router,
    string $base,
    string $sourceLabel,
    int &$checked,
    int &$broken,
    int &$external
): void {
    $href = trim($href);
    if ($href === '' || str_starts_with($href, '#') || preg_match('#^(?:mailto:|tel:|javascript:)#i', $href)) {
        return; // fragment / non-HTTP link
    }
    if (str_contains($href, '<?')) {
        return; // dynamic PHP echo — covered by the rendered-output scan
    }

    if (preg_match('#^https?://#i', $href)) {
        $external++;
        echo "[SKIP]    external: {$href}  ({$sourceLabel})\n";
        return;
    }

    $path = (string) (parse_url($href, PHP_URL_PATH) ?: '/');
    $checked++;

    if (str_starts_with($path, '/assets/') || str_starts_with($path, '/uploads/')) {
        if (is_file($base . $path)) {
            echo "[OK]      {$path}  (file exists)  ({$sourceLabel})\n";
        } else {
            $broken++;
            echo "[FAIL]    {$path}  (file missing)  ({$sourceLabel})\n";
        }
        return;
    }

    if ($router->match('GET', $path) !== null) {
        echo "[OK]      {$path}  (route exists)  ({$sourceLabel})\n";
    } else {
        $broken++;
        echo "[FAIL]    {$path}  (no route, no file)  ({$sourceLabel})\n";
    }
}

/**
 * Scan one HTML/PHP source for href/src attributes.
 */
function scan_source(string $html, Router $router, string $base, string $label, int &$checked, int &$broken, int &$external): void
{
    if (preg_match_all('/(?:href|src)=["\']([^"\']+)["\']/', $html, $m) === 0) {
        return;
    }
    foreach ($m[1] as $href) {
        check_link($href, $router, $base, $label, $checked, $broken, $external);
    }
}

echo "== Link check: rendered pages ==\n";
[$code, $body] = render_page($router, '/');
if ($code !== 200) {
    echo "FATAL: home page did not render (HTTP {$code})\n";
    exit(2);
}
scan_source($body, $router, $base = (string) Config::get('app.base_path'), 'rendered home', $checked, $broken, $external);

[$code, $body404] = render_page($router, '/__no_such_page__');
if ($code !== 404) {
    echo "FATAL: 404 page did not render (HTTP {$code})\n";
    exit(2);
}
scan_source($body404, $router, $base, 'rendered 404', $checked, $broken, $external);

echo "\n== Link check: view/layout sources ==\n";
$viewFiles = [
    'views/layouts/main.php' => (string) file_get_contents($base . '/views/layouts/main.php'),
];
foreach (glob($base . '/pages/*.php') ?: [] as $pageFile) {
    $viewFiles['pages/' . basename($pageFile)] = (string) file_get_contents($pageFile);
}
foreach ($viewFiles as $label => $content) {
    scan_source($content, $router, $base, $label, $checked, $broken, $external);
}

$output = (string) ob_get_clean();
$verdict = $broken > 0 ? 'Link check: FAILURES FOUND' : 'Link check: ALL OK';
fwrite(STDOUT, $output . "\n"
    . "Links checked: {$checked} | broken: {$broken} | external (skipped): {$external}\n\n"
    . "{$verdict}\n");
exit($broken > 0 ? 1 : 0);
