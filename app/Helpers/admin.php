<?php

declare(strict_types=1);

/**
 * Admin presentation helpers. Views remain presentation-only; route handlers
 * supply data and these helpers own the reusable RTL admin shell and safe
 * status pages.
 */
if (!function_exists('admin_view')) {
    /** @param array<string,mixed> $data */
    function admin_view(string $page, array $data = []): void
    {
        $basePath = (string) Config::get('app.base_path');
        $viewFile = $basePath . '/pages/admin/' . $page . '.php';
        if (!is_file($viewFile)) {
            throw new RuntimeException('Admin view not found: ' . $page);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        $layoutFile = $basePath . '/views/layouts/admin.php';
        if (!is_file($layoutFile)) {
            throw new RuntimeException('Admin layout not found');
        }
        require $layoutFile;
    }
}

if (!function_exists('admin_status')) {
    /**
     * Render an actionable, localized admin error instead of leaking a raw
     * string or a blank response. The current authenticated user (when any)
     * remains in the normal admin shell.
     */
    function admin_status(int $status, string $title, string $message, string $backPath = '/admin'): void
    {
        http_response_code($status);
        admin_view('status', [
            'title' => $title,
            'statusCode' => (string) $status,
            'message' => $message,
            'backPath' => $backPath,
        ]);
    }
}

if (!function_exists('admin_forbidden')) {
    function admin_forbidden(string $message = 'این عملیات برای نقش کاربری شما در دسترس نیست.'): void
    {
        admin_status(403, 'دسترسی غیرمجاز', $message);
    }
}

if (!function_exists('admin_not_found')) {
    function admin_not_found(string $message = 'رکورد مورد نظر پیدا نشد.'): void
    {
        admin_status(404, 'رکورد پیدا نشد', $message);
    }
}

if (!function_exists('admin_validation_error')) {
    function admin_validation_error(string $message): void
    {
        admin_status(422, 'اطلاعات نامعتبر است', $message);
    }
}
