<?php

declare(strict_types=1);

/**
 * Route table — Phase 1 + Phase 3 authentication.
 *
 * Authentication is deliberately limited to a login form and POST logout in
 * this phase. Admin/dashboard/content routes belong to later phases.
 */

function define_routes(Router $router): void
{
    $renderLogin = static function (
        string $email = '',
        string $redirectTarget = '/',
        string $error = ''
    ): void {
        view('login', [
            'title' => 'ورود',
            'metaDescription' => 'ورود امن به حساب کاربری',
            'loginEmail' => $email,
            'redirectTarget' => $redirectTarget,
            'loginError' => $error,
        ]);
    };

    $router->get('/', static function (): void {
        view('home', [
            'title' => 'خانه',
            'metaDescription' => (string) Config::get('app.description'),
        ]);
    });

    $router->get('/login', static function () use ($renderLogin): void {
        if (isAuthenticated()) {
            redirect('/');
        }

        $redirectTarget = safe_redirect_path($_GET['redirect'] ?? null);
        $renderLogin('', $redirectTarget);
    });

    $router->add('POST', '/login', static function () use ($renderLogin): void {
        if (isAuthenticated()) {
            redirect('/');
        }

        $email = is_string($_POST['email'] ?? null) ? trim((string) $_POST['email']) : '';
        $password = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
        $redirectTarget = safe_redirect_path($_POST['redirect'] ?? null);
        $csrf = is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null;

        if (!csrf_verify($csrf)) {
            http_response_code(403);
            csrf_regenerate();
            $renderLogin($email, $redirectTarget, 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
            return;
        }

        $authenticated = false;
        if (strlen($email) <= 254 && strlen($password) <= 4096) {
            try {
                $authenticated = (new AuthService())->login($email, $password);
            } catch (Throwable $e) {
                // Do not echo database details or request credentials. The
                // class name is safe operational context and contains neither.
                log_error('Authentication attempt failed: ' . get_class($e));
                http_response_code(503);
                csrf_regenerate();
                $renderLogin($email, $redirectTarget, 'ورود موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.');
                return;
            }
        }

        if (!$authenticated) {
            http_response_code(422);
            csrf_regenerate();
            $renderLogin($email, $redirectTarget, 'ایمیل یا گذرواژه نادرست است.');
            return;
        }

        redirect($redirectTarget, 303);
    });

    $router->get('/admin', static function (): void {
        if (!isAuthenticated()) {
            redirect('/login?redirect=/admin');
        }
        if (!requireRole('admin')) {
            http_response_code(403);
            echo 'دسترسی مجاز نیست.';
            return;
        }

        $contents = new ContentRepository();
        admin_view('dashboard', [
            'title' => 'داشبورد',
            'stats' => [
                'total' => $contents->count(),
                'published' => $contents->count(['status' => 'published']),
                'draft' => $contents->count(['status' => 'draft']),
                'article' => $contents->count(['content_type' => 'article']),
                'news' => $contents->count(['content_type' => 'news']),
                'event' => $contents->count(['content_type' => 'event']),
                'report' => $contents->count(['content_type' => 'report']),
                'media' => (new MediaRepository())->count(),
                'topics' => (new TopicRepository())->count(['is_active' => 1]),
            ],
            'typeLabels' => [
                'article' => 'مقالات',
                'news' => 'خبرها',
                'event' => 'رویدادها',
                'report' => 'گزارش‌ها',
            ],
        ]);
    });

    $router->add('POST', '/logout', static function (): void {
        $csrf = is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null;
        if (!csrf_verify($csrf)) {
            http_response_code(403);
            echo 'درخواست نامعتبر است.';
            return;
        }

        (new AuthService())->logout();
        redirect('/', 303);
    });

    $router->notFound(static function (): void {
        http_response_code(404);
        view('404', [
            'title' => 'صفحه پیدا نشد',
            'metaDescription' => '',
        ]);
    });
}
