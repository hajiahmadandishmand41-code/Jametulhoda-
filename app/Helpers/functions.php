<?php

declare(strict_types=1);

/**
 * Global helper functions — the single place for shared utilities (Phase 1).
 *
 * Conventions:
 *  - every helper is guarded with function_exists (no redeclaration errors)
 *  - all dynamic output is escaped with e()
 *  - URLs are always built with url()/asset() (one source of truth)
 */

if (!function_exists('e')) {
    /**
     * Escape a value for safe HTML output (XSS baseline).
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('site_prefix')) {
    /**
     * URL prefix of the site (no trailing slash).
     *
     * Empty when the site runs on the domain root (the normal shared-hosting
     * setup, e.g. an InfinityFree subdomain). Set app.url in config to run
     * the site in a subdirectory, e.g. 'https://example.com/site'.
     */
    function site_prefix(): string
    {
        $configured = Config::get('app.url');

        return is_string($configured) && $configured !== '' ? rtrim($configured, '/') : '';
    }
}

if (!function_exists('url')) {
    /**
     * Public URL for a path: url('/news') -> /news
     * Root-relative by default; honours the app.url prefix.
     */
    function url(string $path = ''): string
    {
        return site_prefix() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * Public URL for a file under /assets: asset('css/main.css')
     */
    function asset(string $path): string
    {
        return url('/assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('redirect')) {
    /**
     * Send a redirect and stop execution.
     */
    function redirect(string $path, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . url($path));
        exit;
    }
}

if (!function_exists('log_error')) {
    /**
     * Append a line to logs/error.log (private directory, web-blocked).
     */
    function log_error(string $message): void
    {
        $dir = (string) Config::get('app.base_path') . '/logs';
        @mkdir($dir, 0775, true);
        @file_put_contents(
            $dir . '/error.log',
            date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}

if (!function_exists('view')) {
    /**
     * Render a page view inside the main layout.
     *
     * @param string $page view name under pages/ (without .php)
     * @param array  $data variables available in the view ($title, $metaDescription, ...)
     */
    function view(string $page, array $data = []): void
    {
        $basePath = (string) Config::get('app.base_path');
        $viewFile = $basePath . '/pages/' . $page . '.php';

        if (!is_file($viewFile)) {
            throw new RuntimeException('View not found: ' . $page);
        }

        // Controlled: $data only comes from route handlers in router.php
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        $layoutFile = $basePath . '/views/layouts/main.php';
        if (!is_file($layoutFile)) {
            throw new RuntimeException('Main layout not found');
        }
        require $layoutFile;
    }
}

if (!function_exists('slugify')) {
    /** Make a predictable URL slug while preserving Persian letters. */
    function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
        return trim($value, '-');
    }
}

if (!function_exists('safe_redirect_path')) {
    /**
     * Accept only a local absolute path for an authentication redirect.
     * Reject scheme-relative URLs, hosts, backslashes and control characters.
     */
    function safe_redirect_path(mixed $candidate, string $fallback = '/'): string
    {
        if (!is_string($candidate) || $candidate === '' || strlen($candidate) > 2048) {
            return $fallback;
        }
        if (strpbrk($candidate, "\\\r\n") !== false
            || !str_starts_with($candidate, '/')
            || str_starts_with($candidate, '//')) {
            return $fallback;
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'])) {
            return $fallback;
        }

        return $candidate;
    }
}
