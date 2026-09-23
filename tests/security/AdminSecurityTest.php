<?php

declare(strict_types=1);

/** Phase 4.1 regression coverage for the admin boundary and presentation layer. */
final class AdminSecurityTest extends TestCase
{
    public function testAdminRouteIsExplicitAndDashboardOnly(): void
    {
        $source = (string) file_get_contents(BASE_PATH . '/router.php');
        $this->assertContains("\$router->get('/admin'", $source);
        $this->assertContains("if (!isAuthenticated())", $source);
        $this->assertContains("if (!requireRole('admin'))", $source);
        $this->assertContains("new ContentRepository()", $source);
        $this->assertNotContains("\$router->get('/admin/news'", $source);
    }

    public function testAdminRouteDoesNotCreateASecondAuthenticationSystem(): void
    {
        $route = (string) file_get_contents(BASE_PATH . '/router.php');
        $layout = (string) file_get_contents(BASE_PATH . '/views/layouts/admin.php');
        $this->assertNotContains('session_start(', $route);
        $this->assertNotContains('session_start(', $layout);
        $this->assertContains("action=\"<?= e(url('/logout')) ?>\"", $layout);
        $this->assertContains('method="post"', $layout);
        $this->assertContains('csrf_field()', $layout);
    }

    public function testAdminViewsEscapeDynamicValuesAndKeepSqlOutOfTemplates(): void
    {
        $dashboard = (string) file_get_contents(BASE_PATH . '/pages/admin/dashboard.php');
        $layout = (string) file_get_contents(BASE_PATH . '/views/layouts/admin.php');
        $this->assertContains('e($stats', $dashboard);
        $this->assertContains('e($pageTitle)', $layout);
        $this->assertNotContains('SELECT ', $dashboard);
        $this->assertNotContains('SELECT ', $layout);
        $this->assertNotContains('$_GET', $dashboard);
        $this->assertNotContains('$_POST', $dashboard);
    }

    public function testResponsiveAdminFoundationHasMobileBreakpointsAndNoFramework(): void
    {
        $css = (string) file_get_contents(BASE_PATH . '/assets/css/main.css');
        $this->assertContains('.admin-sidebar', $css);
        $this->assertContains('@media (max-width: 640px)', $css);
        $this->assertNotContains('bootstrap', strtolower($css));
        $this->assertNotContains('tailwind', strtolower($css));
    }

    public function testAdminMenuHasNoPretendFutureLinks(): void
    {
        $layout = (string) file_get_contents(BASE_PATH . '/views/layouts/admin.php');
        $this->assertContains('is-disabled', $layout);
        $this->assertNotContains("url('/admin/news')", $layout);
        $this->assertNotContains("url('/admin/articles')", $layout);
    }
}
