<?php

declare(strict_types=1);

/**
 * Integration tests: real dispatch through the registered route table,
 * capturing the rendered HTML and the response code.
 *
 * Phase 5: the public pages read from the database, so every test runs
 * against a fresh sandbox schema (MySQL when configured, SQLite otherwise).
 */

final class RoutesTest extends TestCase
{
    private PDO $pdo;

    public function setUp(): void
    {
        $this->pdo = SchemaSandbox::fresh();
        SchemaSandbox::clear($this->pdo);
        db_set_connection($this->pdo);
    }

    public function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        db_set_connection(null);
        http_response_code(200);
    }

    /**
     * Seed one published news item and return its slug.
     */
    private function seedPublishedNews(string $slug = 'test-khabar'): string
    {
        $contents = new ContentRepository();
        $id = $contents->create([
            'content_type' => 'news',
            'slug' => $slug,
            'title' => 'خبر آزمایشی منتشرشده',
            'summary' => 'خلاصهٔ خبر آزمایشی.',
            'body' => 'متن کامل خبر آزمایشی برای تست مسیرها.',
            'status' => 'published',
        ]);
        $contents->publish($id);

        return $slug;
    }

    /**
     * @return array{0:int, 1:string} [status code, body]
     */
    private function dispatch(string $method, string $path): array
    {
        $router = new Router();
        define_routes($router);

        // A real request splits the query string off before routing and
        // fills $_GET with it — mirror that here so "/search?q=x" reaches
        // the route table as path "/search" plus $_GET['q'].
        $parts = parse_url($path);
        $routePath = (string) ($parts['path'] ?? '/');
        $_SERVER['QUERY_STRING'] = (string) ($parts['query'] ?? '');
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = [];
        if ($_SERVER['QUERY_STRING'] !== '') {
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        }

        http_response_code(200); // explicit default: CLI has no implicit 200
        ob_start();
        $router->dispatch($method, $routePath);
        $body = (string) ob_get_clean();
        $code = (int) http_response_code();
        http_response_code(200); // reset for other tests

        return [$code, $body];
    }

    public function testHomeRendersLayout(): void
    {
        [$code, $body] = $this->dispatch('GET', '/');

        $this->assertSame(200, $code);
        $this->assertContains('<!DOCTYPE html>', $body);
        $this->assertContains('<html lang="fa" dir="rtl">', $body);
        $this->assertContains('main.css', $body);
        $this->assertContains('main.js', $body);
        $this->assertContains(e((string) Config::get('app.name')), $body);
    }

    public function testHomeShowsEmptyStateWithoutContent(): void
    {
        [$code, $body] = $this->dispatch('GET', '/');
        $this->assertSame(200, $code);
        $this->assertContains('empty-state', $body);
    }

    public function testHomeShowsPublishedNews(): void
    {
        $slug = $this->seedPublishedNews();
        [$code, $body] = $this->dispatch('GET', '/');
        $this->assertSame(200, $code);
        $this->assertContains('خبر آزمایشی منتشرشده', $body);
        $this->assertContains('/news/' . $slug, $body);
    }

    public function testHomeHasNoInlineScriptsOrStyles(): void
    {
        [, $body] = $this->dispatch('GET', '/');
        // Strict CSP (see .htaccess) forbids inline handlers/styles.
        // JSON-LD (type=application/ld+json) is data, not executable script,
        // and is not emitted on the empty home page anyway.
        $this->assertNotContains('<style>', $body);
        $this->assertNotContains('onclick=', $body);
    }

    public function testNewsListingRenders(): void
    {
        $this->seedPublishedNews();
        [$code, $body] = $this->dispatch('GET', '/news');
        $this->assertSame(200, $code);
        $this->assertContains('خبر آزمایشی منتشرشده', $body);
    }

    public function testNewsDetailRenders(): void
    {
        $slug = $this->seedPublishedNews();
        [$code, $body] = $this->dispatch('GET', '/news/' . $slug);
        $this->assertSame(200, $code);
        $this->assertContains('public-detail', $body);
        $this->assertContains('خبر آزمایشی منتشرشده', $body);
        $this->assertContains('application/ld+json', $body);
    }

    public function testDraftIsNotPubliclyVisible(): void
    {
        (new ContentRepository())->create([
            'content_type' => 'news',
            'slug' => 'test-draft',
            'title' => 'خبر پیش‌نویس',
            'body' => 'متن پیش‌نویس',
            'status' => 'draft',
        ]);
        [$code, ] = $this->dispatch('GET', '/news/test-draft');
        $this->assertSame(404, $code);
    }

    public function testUnknownSlugReturns404(): void
    {
        [$code, $body] = $this->dispatch('GET', '/news/does-not-exist');
        $this->assertSame(404, $code);
        $this->assertContains('صفحه پیدا نشد', $body);
    }

    public function testSearchTooShortShowsNotice(): void
    {
        [$code, $body] = $this->dispatch('GET', '/search?q=a');
        $this->assertSame(200, $code);
        $this->assertContains('حداقل', $body);
    }

    public function testSearchEscapesQuery(): void
    {
        [, $body] = $this->dispatch('GET', '/search?q=' . rawurlencode('<script>x</script>'));
        $this->assertNotContains('<script>x</script>', $body);
    }

    public function testSitemapListsPublishedContent(): void
    {
        $slug = $this->seedPublishedNews();
        [$code, $body] = $this->dispatch('GET', '/sitemap.xml');
        $this->assertSame(200, $code);
        $this->assertContains('<urlset', $body);
        $this->assertContains('/news/' . $slug, $body);
    }

    public function testRobotsBlocksAdmin(): void
    {
        [$code, $body] = $this->dispatch('GET', '/robots.txt');
        $this->assertSame(200, $code);
        $this->assertContains('Disallow: /admin', $body);
        $this->assertContains('Sitemap:', $body);
    }

    public function testUnknownPathRenders404Page(): void
    {
        [$code, $body] = $this->dispatch('GET', '/this-page-does-not-exist');

        $this->assertSame(404, $code);
        $this->assertContains('صفحه پیدا نشد', $body);
        $this->assertContains('not-found', $body);
    }

    public function test404PageLinksToHome(): void
    {
        [, $body] = $this->dispatch('GET', '/nope');
        $this->assertContains('href="/"', $body);
    }

    public function testPostToKnownPathIs405(): void
    {
        [$code, $body] = $this->dispatch('POST', '/');
        $this->assertSame(405, $code);
        $this->assertContains('Method Not Allowed', $body);
    }

    public function test404TitleIsSet(): void
    {
        [, $body] = $this->dispatch('GET', '/missing');
        $this->assertContains('<title>صفحه پیدا نشد |', $body);
    }
}
