<?php

declare(strict_types=1);

/**
 * Framework-free authentication and authorization guards.
 *
 * Guards return booleans so a route can choose a safe response (redirect,
 * 403 page, or JSON) without coupling this foundation to a framework.
 * A protected route must check the guard on the server before doing work.
 */
final class AuthGuards
{
    /** @var array<string,int> */
    private const ROLE_LEVELS = [
        'user' => 10,
        'editor' => 20,
        'admin' => 30,
    ];

    /** @var array<string,string> */
    private const PERMISSION_ROLES = [
        'authenticated' => 'user',
        'content.view_drafts' => 'editor',
        'content.create' => 'editor',
        'content.edit' => 'editor',
        'content.publish' => 'editor',
        'users.manage' => 'admin',
        'admin.access' => 'admin',
    ];

    public static function currentUser(): ?array
    {
        return (new AuthService())->currentUser();
    }

    public static function isAuthenticated(): bool
    {
        return (new AuthService())->isAuthenticated();
    }

    public static function isGuest(): bool
    {
        return !self::isAuthenticated();
    }

    public static function hasRole(string $requiredRole): bool
    {
        $user = self::currentUser();
        if ($user === null || !$user['is_active']) {
            return false;
        }

        $required = self::ROLE_LEVELS[$requiredRole] ?? null;
        $actual = self::ROLE_LEVELS[(string) $user['role']] ?? null;

        return $required !== null && $actual !== null && $actual >= $required;
    }

    /** @param list<string> $roles */
    public static function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if (self::hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public static function can(string $permission): bool
    {
        $role = self::PERMISSION_ROLES[$permission] ?? null;

        return $role !== null && self::hasRole($role);
    }
}

if (!function_exists('currentUser')) {
    /** @return array{id:int,name:string,email:string,role:string,is_active:bool}|null */
    function currentUser(): ?array
    {
        return AuthGuards::currentUser();
    }
}

if (!function_exists('isAuthenticated')) {
    function isAuthenticated(): bool
    {
        return AuthGuards::isAuthenticated();
    }
}

if (!function_exists('isGuest')) {
    function isGuest(): bool
    {
        return AuthGuards::isGuest();
    }
}

if (!function_exists('hasRole')) {
    function hasRole(string $role): bool
    {
        return AuthGuards::hasRole($role);
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth(): bool
    {
        return isAuthenticated();
    }
}

if (!function_exists('requireGuest')) {
    function requireGuest(): bool
    {
        return isGuest();
    }
}

if (!function_exists('requireRole')) {
    function requireRole(string $role): bool
    {
        return hasRole($role);
    }
}

if (!function_exists('authorize')) {
    function authorize(string $permission): bool
    {
        return AuthGuards::can($permission);
    }
}
