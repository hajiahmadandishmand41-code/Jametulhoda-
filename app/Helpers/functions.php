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

if (!function_exists('site_base_path')) {
    /**
     * Return the URL path at which the application is installed.
     *
     * A configured app.url wins. Otherwise, on a real web request we derive
     * the directory that contains index.php. This makes the same build work
     * both at the domain root and in a shared-hosting subdirectory such as
     * /php or /site without requiring a hard-coded path in the repository.
     */
    function site_base_path(): string
    {
        $configured = Config::get('app.url');
        if (is_string($configured) && trim($configured) !== '') {
            $configuredPath = parse_url($configured, PHP_URL_PATH);
            if (is_string($configuredPath) && $configuredPath !== '') {
                $normalized = '/' . trim((string) (preg_replace('#/+#', '/', $configuredPath) ?? ''), '/');
                return $normalized === '' ? '/' : $normalized;
            }

            return '/';
        }

        // CLI tests intentionally behave like a domain-root installation.
        if (PHP_SAPI !== 'cli') {
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
            if ($script !== '') {
                $directory = str_replace('\\', '/', dirname($script));
                if ($directory !== '.' && $directory !== '/' && $directory !== DIRECTORY_SEPARATOR) {
                    return '/' . trim($directory, '/');
                }
            }
        }

        return '/';
    }
}

if (!function_exists('site_prefix')) {
    /**
     * Public URL prefix.
     *
     * When app.url is configured, preserve the configured origin/path.
     * Otherwise return the auto-detected installation path when the app is
     * hosted in a subdirectory, or empty string at the domain root.
     */
    function site_prefix(): string
    {
        $configured = Config::get('app.url');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim($configured, '/');
        }

        $basePath = site_base_path();
        return $basePath === '/' ? '' : $basePath;
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

if (!function_exists('content_url')) {
    /**
     * Public URL for a content item, mapping the internal content type to its
     * SEO-friendly URL segment. Slugs are URL-encoded so Persian slugs stay
     * valid inside href attributes.
     */
    function content_url(string $type, string $slug): string
    {
        $map = [
            'article'  => 'articles',
            'news'     => 'news',
            'report'   => 'reports',
            'event'    => 'events',
            'book'     => 'books',
            'lesson'   => 'lessons',
            'research' => 'research',
        ];
        $segment = $map[$type] ?? 'news';

        return url('/' . $segment . '/' . rawurlencode($slug));
    }
}

if (!function_exists('listing_url')) {
    /**
     * Public URL for a content-type listing page.
     */
    function listing_url(string $type): string
    {
        $map = [
            'article'  => '/articles',
            'news'     => '/news',
            'report'   => '/reports',
            'event'    => '/events',
            'book'     => '/books',
            'lesson'   => '/lessons',
            'research' => '/research',
        ];

        return url($map[$type] ?? '/news');
    }
}

if (!function_exists('content_type_label')) {
    /**
     * Persian display label for any internal content type (Phase 6 keeps
     * the label map in one place instead of repeating it in every view).
     */
    function content_type_label(string $type): string
    {
        $map = [
            'article'  => 'مقاله',
            'news'     => 'خبر',
            'report'   => 'گزارش',
            'event'    => 'رویداد',
            'book'     => 'کتاب',
            'lesson'   => 'درس',
            'research' => 'پژوهش',
        ];

        return $map[$type] ?? $type;
    }
}

if (!function_exists('content_type_plural_label')) {
    /**
     * Persian plural label for a content type (listings, admin tables).
     */
    function content_type_plural_label(string $type): string
    {
        $map = [
            'article'  => 'مقالات',
            'news'     => 'خبرها',
            'report'   => 'گزارش‌ها',
            'event'    => 'رویدادها',
            'book'     => 'کتاب‌ها',
            'lesson'   => 'درس‌ها',
            'research' => 'پژوهش‌ها',
        ];

        return $map[$type] ?? $type;
    }
}

if (!function_exists('media_url')) {
    /**
     * Safe public URL for an uploaded media file.
     *
     * Only paths that live under uploads/ are ever returned; anything that
     * tries to escape that directory (path traversal) yields an empty string,
     * so a bad row can never link to an arbitrary file on disk.
     */
    function media_url(string $diskPath): string
    {
        $clean = ltrim(trim($diskPath), '/');
        if ($clean === '' || !str_starts_with($clean, 'uploads/')) {
            return '';
        }
        if (str_contains($clean, '..') || str_contains($clean, "\0") || str_contains($clean, '\\')) {
            return '';
        }

        return url('/' . $clean);
    }
}

if (!function_exists('excerpt')) {
    /**
     * A plain-text excerpt of arbitrary content: tags stripped, whitespace
     * collapsed, trimmed to a length on a whole-word boundary. Safe to pass
     * through e() afterwards.
     */
    function excerpt(?string $text, int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');
        if ($text === '' || mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length, 'UTF-8');
        $lastSpace = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace, 'UTF-8');
        }

        return rtrim($cut) . '…';
    }
}

if (!function_exists('fa_digits')) {
    /**
     * Convert ASCII digits in a string to Persian digits.
     */
    function fa_digits(string $value): string
    {
        return strtr($value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}

if (!function_exists('gregorian_to_jalali')) {
    /**
     * Convert a Gregorian date to the Jalali (Solar Hijri) calendar.
     *
     * Standard, deterministic algorithm (jdf). Returns [year, month, day].
     *
     * @return array{0:int,1:int,2:int}
     */
    function gregorian_to_jalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $gDaysInMonth[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }
}

if (!function_exists('format_date_fa')) {
    /**
     * Format a Y-m-d[ H:i:s] datetime as a readable Persian (Jalali) date.
     * Invalid input yields an empty string.
     */
    function format_date_fa(?string $datetime): string
    {
        $datetime = trim((string) $datetime);
        if ($datetime === '') {
            return '';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '';
        }
        [$jy, $jm, $jd] = gregorian_to_jalali(
            (int) date('Y', $ts),
            (int) date('n', $ts),
            (int) date('j', $ts)
        );
        $months = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
            5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
            9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ];

        return fa_digits((string) $jd) . ' ' . ($months[$jm] ?? '') . ' ' . fa_digits((string) $jy);
    }
}

if (!function_exists('normalize_request_path')) {
    /**
     * Normalize an incoming request and remove the installation prefix.
     *
     * Router patterns are always written from the application root:
     * /news, /books, /admin, ... even when the site is deployed under /php.
     */
    function normalize_request_path(string $uri, ?string $basePath = null): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode((string) $path);
        $path = '/' . trim((string) (preg_replace('#/+#', '/', $path) ?? ''), '/');

        $basePath = $basePath ?? site_base_path();
        $basePath = '/' . trim((string) (preg_replace('#/+#', '/', $basePath) ?? ''), '/');
        if ($basePath === '') {
            $basePath = '/';
        }

        if ($basePath !== '/' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath)) ?: '/';
            $path = '/' . trim($path, '/');
        }

        if ($path === '/index.php') {
            $path = '/';
        }

        return $path === '' ? '/' : $path;
    }
}

if (!function_exists('current_path')) {
    /**
     * The current request path inside the application (no query string).
     * Used to mark active navigation and build canonical URLs.
     */
    function current_path(): string
    {
        return normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? '/'));
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
