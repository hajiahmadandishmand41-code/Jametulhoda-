<?php

declare(strict_types=1);

/**
 * Admin presentation helper. Views remain presentation-only; route handlers
 * supply the data and this helper owns the reusable RTL admin shell.
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
