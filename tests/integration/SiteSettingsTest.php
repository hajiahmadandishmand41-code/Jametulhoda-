<?php

declare(strict_types=1);

final class SiteSettingsTest extends TestCase
{
    private PDO $pdo;
    private array $created = [];
    private array $server;

    public function setUp(): void
    {
        $this->server = $_SERVER;
        $this->pdo = SchemaSandbox::fresh();
        SchemaSandbox::clear($this->pdo);
        db_set_connection($this->pdo);
        SiteSettings::resetCache();
        auth_session_logout();
        $_POST = $_FILES = $_GET = [];
    }

    public function tearDown(): void
    {
        foreach ($this->created as $file) { if (is_file($file)) { unlink($file); } }
        SiteSettings::resetCache();
        auth_session_logout();
        db_set_connection(null);
        $_SERVER = $this->server;
        $_POST = $_FILES = $_GET = [];
        http_response_code(200);
    }

    private function login(string $role): int
    {
        $id = (new UserRepository())->create([
            'name' => 'Test', 'email' => $role . '@example.test', 'role' => $role,
            'password' => bin2hex(random_bytes(16)), 'is_active' => true,
        ]);
        SessionManager::authenticate(['id' => $id, 'name' => 'Test', 'email' => $role . '@example.test', 'role' => $role, 'is_active' => true]);
        return $id;
    }

    private function dispatch(string $method, string $path): array
    {
        $_SERVER['REQUEST_URI'] = $path;
        $router = new Router();
        define_routes($router);
        http_response_code(200);
        ob_start();
        try {
            $router->dispatch($method, $path);
            return [(int) http_response_code(), (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    private function image(): string
    {
        $path = 'uploads/site/' . bin2hex(random_bytes(24)) . '.png';
        $file = BASE_PATH . '/' . $path;
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII='));
        $this->created[] = $file;
        return $path;
    }

    public function testGuestRedirectsAndRolesCannotReadOrWriteSettings(): void
    {
        foreach (['GET', 'POST'] as $method) {
            try {
                $this->dispatch($method, '/admin/settings');
                $this->assertTrue(false, 'Guest must redirect');
            } catch (RedirectException $e) {
                $this->assertContains('/login?redirect=', $e->path);
            }
        }
        foreach (['user', 'editor'] as $role) {
            $this->login($role);
            foreach (['GET', 'POST'] as $method) {
                [$code, $body] = $this->dispatch($method, '/admin/settings');
                $this->assertSame(403, $code);
                $this->assertNotContains('href="' . url('/admin/settings') . '"', $body);
            }
            [$code] = $this->dispatch('POST', '/admin/settings/upgrade');
            $this->assertSame(403, $code);
            auth_session_logout();
        }
    }

    public function testAdminFormCsrfAndSave(): void
    {
        $this->login('admin');
        [$code, $body] = $this->dispatch('GET', '/admin/settings');
        $this->assertSame(200, $code);
        $this->assertContains('multipart/form-data', $body);
        $this->assertContains('data-branding-preview', $body);
        $_POST = ['name' => 'نام جدید', 'description' => 'توضیح جدید'];
        [$code] = $this->dispatch('POST', '/admin/settings');
        $this->assertSame(403, $code);
        [$code] = $this->dispatch('POST', '/admin/settings/upgrade');
        $this->assertSame(403, $code);
        $_POST[Csrf::FIELD] = csrf_token();
        try {
            $this->dispatch('POST', '/admin/settings');
            $this->assertTrue(false, 'Successful save redirects');
        } catch (RedirectException $e) {
            $this->assertSame(303, $e->status);
        }
        $this->assertSame('نام جدید', site_setting('name'));
        SiteSettings::save(['name' => 'دوباره', 'description' => ''], []);
        $this->assertSame(2, (int) db_value('SELECT COUNT(*) FROM site_settings'));
    }

    public function testRevokedAdminCannotSaveWithOldSession(): void
    {
        $id = $this->login('admin');
        db_run('UPDATE users SET role = ? WHERE id = ?', ['user', $id]);
        $_POST = ['name' => 'Forbidden', 'description' => '', Csrf::FIELD => csrf_token()];
        [$code] = $this->dispatch('POST', '/admin/settings');
        $this->assertSame(403, $code);
        $this->assertSame(0, (int) db_value('SELECT COUNT(*) FROM site_settings'));
    }

    public function testImagesRenderInHeaderFooterFaviconOgAndFallbackAfterReset(): void
    {
        $path = $this->image();
        foreach (array_keys(SiteSettings::IMAGES) as $key) {
            db_run('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)', [$key, $path]);
        }
        SiteSettings::resetCache();
        [$code, $body] = $this->dispatch('GET', '/');
        $this->assertSame(200, $code);
        $this->assertTrue(substr_count($body, e(site_image('logo'))) >= 2);
        $this->assertContains('rel="icon" href="' . e(site_image('favicon')) . '"', $body);
        $this->assertContains('property="og:image" content="' . e(absolute_url('/' . $path)) . '"', $body);
        // Use separate files in normal UI; the test deliberately shares a path.
        SiteSettings::save(['name' => 'سایت', 'description' => '', 'reset_logo' => '1', 'reset_favicon' => '1', 'reset_og_image' => '1'], []);
        $this->assertSame('/assets/img/logo.svg', SiteSettings::imagePath('logo'));
        $this->assertSame('/assets/img/favicon.svg', SiteSettings::imagePath('favicon'));
        $this->assertSame('/assets/img/og-logo.svg', SiteSettings::imagePath('og_image'));
        $this->assertFalse(is_file(BASE_PATH . '/' . $path));
    }

    public function testCorruptMissingAndTraversalPathsFallback(): void
    {
        $path = $this->image();
        file_put_contents(BASE_PATH . '/' . $path, 'not an image');
        foreach ([$path, 'uploads/site/' . str_repeat('a', 48) . '.png', '../../config/local.php', 'https://evil.example/logo.png'] as $value) {
            db_run('DELETE FROM site_settings');
            db_run('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)', ['logo', $value]);
            SiteSettings::resetCache();
            $this->assertSame('/assets/img/logo.svg', SiteSettings::imagePath('logo'));
        }
    }

    public function testRequestCacheAndSubdirectoryUrls(): void
    {
        db_run('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)', ['name', 'اول']);
        $this->assertSame('اول', site_setting('name'));
        db_run('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?', ['دوم', 'name']);
        $this->assertSame('اول', site_setting('name'));
        SiteSettings::resetCache();
        $this->assertSame('دوم', site_setting('name'));
        $property = new ReflectionProperty(Config::class, 'values');
        $original = $property->getValue();
        $values = $original;
        $values['app']['url'] = 'https://jametulhoda.gt.tc/php';
        $property->setValue(null, $values);
        try {
            $this->assertSame('https://jametulhoda.gt.tc/php/assets/img/logo.svg', site_image('logo'));
            $this->assertSame('https://jametulhoda.gt.tc/php/assets/img/og-logo.svg', absolute_url(SiteSettings::imagePath('og_image')));
            $this->assertSame('/admin/settings', normalize_request_path('/php/admin/settings'));
        } finally {
            $property->setValue(null, $original);
        }
    }

    public function testStoredTextIsEscapedAndMalformedInputDoesNotPersist(): void
    {
        SiteSettings::save(['name' => '<script>alert(1)</script>', 'description' => '" onload="bad'], []);
        [, $body] = $this->dispatch('GET', '/');
        $this->assertContains('&lt;script&gt;', $body);
        $this->assertNotContains('<script>alert(1)</script>', $body);
        try {
            SiteSettings::save(['name' => ['bad'], 'description' => ''], []);
            $this->assertTrue(false, 'Array must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('<script>alert(1)</script>', site_setting('name'));
        }
    }

    public function testEveryRegisteredAdminRouteDeniesOrdinaryUserAndEveryMutationRequiresCsrf(): void
    {
        $router = new Router();
        define_routes($router);
        $property = new ReflectionProperty(Router::class, 'routes');
        $routes = $property->getValue($router);
        $this->login('user');
        foreach ($routes as $route) {
            if (!str_starts_with($route['regex'], '#^/admin')) { continue; }
            $path = str_replace('([^/]+)', '1', substr($route['regex'], 2, -2));
            [$code] = $this->dispatch($route['method'], $path);
            $this->assertSame(403, $code, $route['method'] . ' ' . $path);
        }
        auth_session_logout();
        $this->login('admin');
        foreach ($routes as $route) {
            if ($route['method'] !== 'POST' || !str_starts_with($route['regex'], '#^/admin')) { continue; }
            $path = str_replace('([^/]+)', '1', substr($route['regex'], 2, -2));
            [$code] = $this->dispatch('POST', $path);
            $this->assertSame(403, $code, 'CSRF: ' . $path);
        }
    }

    public function testGenericRegistryOpensTheCorrectKnowledgeEditor(): void
    {
        $this->login('admin');
        foreach (['book' => 'books', 'lesson' => 'lessons', 'research' => 'research'] as $type => $section) {
            $id = (new ContentRepository())->create(['content_type' => $type, 'slug' => 'test-' . $type, 'title' => 'Test', 'status' => 'draft']);
            try {
                $this->dispatch('GET', '/admin/content/edit/' . $id);
                $this->assertTrue(false, 'Knowledge item must use its editor');
            } catch (RedirectException $e) {
                $this->assertSame('/admin/' . $section . '/edit/' . $id, $e->path);
            }
            $_GET['type'] = $type;
            try {
                $this->dispatch('GET', '/admin/content/new');
                $this->assertTrue(false, 'Knowledge creation must use its form');
            } catch (RedirectException $e) {
                $this->assertSame('/admin/' . $section . '/new', $e->path);
            }
        }
    }

    public function testLocalFileCannotPretendToBeHttpUpload(): void
    {
        $path = $this->image();
        try {
            SiteSettings::save(['name' => 'نام', 'description' => ''], ['logo' => [
                'name' => 'logo.png', 'tmp_name' => BASE_PATH . '/' . $path, 'error' => UPLOAD_ERR_OK,
            ]]);
            $this->assertTrue(false, 'is_uploaded_file must be enforced');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, (int) db_value('SELECT COUNT(*) FROM site_settings'));
        }
    }
}
