<?php

declare(strict_types=1);

/**
 * Central session boundary for authentication (Phase 3).
 *
 * The application never starts sessions from templates or individual pages.
 * These helpers use PHP's standard file/session handler, which is available
 * on Apache shared hosting and does not require Redis, Memcached or Node.js.
 */
final class SessionManager
{
    private const AUTH_KEY = '_auth_user';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // These settings are repeated here rather than assumed from php.ini:
        // shared-hosting defaults are not consistent between providers.
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', self::isHttps() ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');

        $configuredName = (string) Config::get('auth.session_name', 'jametulhoda_session');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $configuredName) !== 1) {
            $configuredName = 'jametulhoda_session';
        }
        session_name($configuredName);

        // The array form is supported by PHP 8.1+ and makes SameSite
        // explicit even when the host's php.ini omits it.
        $cookiePath = site_base_path();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath === '/' ? '/' : $cookiePath . '/',
            'domain' => '',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!headers_sent()) {
            session_start();
            return;
        }

        // Continuing without a session would silently turn authentication into
        // a non-working feature, so fail closed instead of creating state that
        // cannot be sent to the browser.
        throw new RuntimeException('Authentication session could not be started.');
    }

    public static function regenerate(): void
    {
        self::start();
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Authentication session could not be regenerated.');
        }
    }

    /**
     * Store only the non-sensitive user snapshot required by guards.
     * Password hashes never enter the session.
     *
     * @param array{id:int,name:string,email:string,role:string,is_active:bool} $user
     */
    public static function authenticate(array $user): void
    {
        self::start();
        $_SESSION[self::AUTH_KEY] = [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'is_active' => (bool) $user['is_active'],
        ];
        $_SESSION['authenticated_at'] = time();
    }

    /**
     * @return array{id:int,name:string,email:string,role:string,is_active:bool}|null
     */
    public static function authenticatedUser(): ?array
    {
        self::start();
        $user = $_SESSION[self::AUTH_KEY] ?? null;
        if (!is_array($user) || !isset($user['id'], $user['email'], $user['role'])) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'is_active' => (bool) ($user['is_active'] ?? false),
        ];
    }

    public static function forgetAuthentication(): void
    {
        self::start();
        unset($_SESSION[self::AUTH_KEY], $_SESSION['authenticated_at']);
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        $params = session_get_cookie_params();
        if ((bool) ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => (string) ($params['path'] ?? '/'),
                'domain' => (string) ($params['domain'] ?? ''),
                'secure' => (bool) ($params['secure'] ?? self::isHttps()),
                'httponly' => true,
                'samesite' => (string) ($params['samesite'] ?? 'Lax'),
            ]);
        }
        session_destroy();
    }

    public static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        // This is useful on hosts that terminate TLS before PHP and have the
        // application URL configured. No client-controlled header is trusted.
        $appUrl = (string) Config::get('app.url', '');
        return str_starts_with(strtolower($appUrl), 'https://');
    }
}

if (!function_exists('auth_session_start')) {
    function auth_session_start(): void
    {
        SessionManager::start();
    }
}

if (!function_exists('auth_session_regenerate')) {
    function auth_session_regenerate(): void
    {
        SessionManager::regenerate();
    }
}

if (!function_exists('auth_session_logout')) {
    function auth_session_logout(): void
    {
        SessionManager::destroy();
    }
}
