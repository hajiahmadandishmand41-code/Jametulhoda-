<?php

declare(strict_types=1);

/**
 * Route table — Phase 1 (core).
 *
 * Registered routes:
 *   GET /   -> pages/home.php   (200)
 *   *       -> pages/404.php    (404)
 *
 * New public routes (articles, news, events, reports, search, ...) are
 * registered here in their own phase. This file is included by index.php only.
 */

function define_routes(Router $router): void
{
    $router->get('/', static function (): void {
        view('home', [
            'title' => 'خانه',
            'metaDescription' => (string) Config::get('app.description'),
        ]);
    });

    $router->notFound(static function (): void {
        http_response_code(404);
        view('404', [
            'title' => 'صفحه پیدا نشد',
            'metaDescription' => '',
        ]);
    });
}
