<?php

declare(strict_types=1);

/** Phase 4.1: admin route policy and real dashboard statistic sources. */
final class AdminFoundationTest extends TestCase
{
    private PDO $pdo;

    public function setUp(): void
    {
        auth_session_logout();
        $this->pdo = SchemaSandbox::fresh();
        SchemaSandbox::clear($this->pdo);
        db_set_connection($this->pdo);
    }

    public function tearDown(): void
    {
        auth_session_logout();
        db_set_connection(null);
    }

    public function testAdminRouteIsRegisteredWithoutFutureCrudRoutes(): void
    {
        $router = new Router();
        define_routes($router);
        $this->assertTrue($router->match('GET', '/admin') !== null);
        $this->assertNull($router->match('GET', '/admin/news'));
        $this->assertNull($router->match('GET', '/admin/articles'));
        $this->assertNull($router->match('POST', '/admin'));
    }

    public function testOnlyAdminRoleCanPassTheAdminGuard(): void
    {
        SessionManager::authenticate([
            'id' => 1, 'name' => 'کاربر', 'email' => 'user@example.test',
            'role' => 'user', 'is_active' => true,
        ]);
        $this->assertFalse(requireRole('admin'));
        auth_session_logout();

        SessionManager::authenticate([
            'id' => 2, 'name' => 'ویراستار', 'email' => 'editor@example.test',
            'role' => 'editor', 'is_active' => true,
        ]);
        $this->assertFalse(requireRole('admin'));
        auth_session_logout();

        SessionManager::authenticate([
            'id' => 3, 'name' => 'مدیر', 'email' => 'admin@example.test',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->assertTrue(requireRole('admin'));
    }

    public function testDashboardStatisticsUseExistingRepositoryData(): void
    {
        $contents = new ContentRepository();
        $contents->create(['content_type' => 'article', 'slug' => 'a', 'title' => 'الف', 'status' => 'published']);
        $contents->create(['content_type' => 'news', 'slug' => 'n', 'title' => 'خبر', 'status' => 'draft']);
        (new TopicRepository())->create(['slug' => 'topic', 'title' => 'موضوع']);
        (new MediaRepository())->create(['media_type' => 'image', 'disk_path' => 'dev/dashboard.jpg']);

        $this->assertSame(2, $contents->count());
        $this->assertSame(1, $contents->count(['status' => 'published']));
        $this->assertSame(1, $contents->count(['status' => 'draft']));
        $this->assertSame(1, $contents->count(['content_type' => 'article']));
        $this->assertSame(1, (new MediaRepository())->count());
        $this->assertSame(1, (new TopicRepository())->count(['is_active' => 1]));
    }
}
