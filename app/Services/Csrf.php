<?php

declare(strict_types=1);

/**
 * Session-backed CSRF protection for state-changing requests.
 *
 * The token is independent of the session ID, is generated with
 * cryptographically secure random bytes, and is compared in constant time.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';
    public const FIELD = '_csrf_token';

    public static function token(): string
    {
        auth_session_start();
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || strlen($token) !== 64
            || preg_match('/\A[0-9a-f]{64}\z/D', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function regenerate(): string
    {
        auth_session_start();
        unset($_SESSION[self::SESSION_KEY]);

        return self::token();
    }

    public static function verify(?string $submitted): bool
    {
        auth_session_start();
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || $expected === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . e(self::FIELD) . '" value="' . e(self::token()) . '">';
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $submitted): bool
    {
        return Csrf::verify($submitted);
    }
}

if (!function_exists('csrf_regenerate')) {
    function csrf_regenerate(): string
    {
        return Csrf::regenerate();
    }
}
