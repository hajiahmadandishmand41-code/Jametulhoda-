<?php

declare(strict_types=1);

/**
 * Application configuration (Phase 1).
 *
 * Values are loaded in two layers:
 *   1. defaults (this file) — safe production values, committed to VCS
 *   2. config/local.php (optional) — environment overrides, git-ignored.
 *      Copy config/local.example.php to config/local.php to use it.
 */

final class Config
{
    private static array $values = [];

    /**
     * Load defaults + local overrides. Called automatically on include.
     */
    public static function load(): void
    {
        $defaults = [
            'app' => [
                'name' => 'جامة‌الهدی',
                'description' => 'پایگاه خبری و محتوایی مذهبی؛ اخبار، مقالات، گزارش‌ها و رویدادهای دینی و فرهنگی.',
                'environment' => 'production', // production | development
                'timezone' => 'Asia/Kabul',
                'url' => null, // null = auto-detect from the request
                'charset' => 'UTF-8',
                'base_path' => dirname(__DIR__),
            ],
            'db' => [
                'host' => 'localhost',
                'port' => '3306',
                'name' => 'site',
                'user' => '',
                'password' => '',
                'charset' => 'utf8mb4',
            ],
            'auth' => [
                'session_name' => 'jametulhoda_session',
                'max_login_attempts' => 5,
                'login_window_seconds' => 900,
                'lockout_seconds' => 900,
            ],
        ];

        $localFile = __DIR__ . '/local.php';
        if (is_file($localFile)) {
            $local = require $localFile;
            if (is_array($local)) {
                $defaults = self::merge($defaults, $local);
            }
        }

        self::$values = $defaults;
        date_default_timezone_set((string) self::$values['app']['timezone']);
    }

    /**
     * Read a value using dot notation: Config::get('db.host')
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * True when running in development mode (config/local.php).
     */
    public static function isDebug(): bool
    {
        return strtolower((string) self::get('app.environment')) === 'development';
    }

    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}

Config::load();
