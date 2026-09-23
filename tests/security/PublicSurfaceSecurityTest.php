<?php

declare(strict_types=1);

/**
 * Phase 5 — public surface security regression.
 *
 * Verifies the guarantees the public site must never lose:
 *   * only published content is reachable (no draft/archived leakage);
 *   * user input is HTML-escaped on output (XSS);
 *   * the search query is matched as data, not executed (SQL injection),
 *     and LIKE wildcards in the query are treated literally;
 *   * media_url() refuses to link outside uploads/ (path traversal).
 */
final class PublicSurfaceSecurityTest extends TestCase
{
    private PDO $pdo;

    private ContentRepository $contents;

    public function setUp(): void
    {
        $this->pdo = SchemaSandbox::fresh();
        SchemaSandbox::clear($this->pdo);
        db_set_connection($this->pdo);
        $this->contents = new ContentRepository();
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
     * @return array{0:int, 1:string}
     */
    private function dispatch(string $method, string $path): array
    {
        $router = new Router();
        define_routes($router);
        http_response_code(200);
        ob_start();
        $router->dispatch($method, $path);
        $body = (string) ob_get_clean();
        $code = (int) http_response_code();
        http_response_code(200);

        return [$code, $body];
    }

    public function testDraftAndArchivedNeverAppearInListing(): void
    {
        $this->contents->create(['content_type' => 'news', 'slug' => 'pub', 'title' => 'منتشرشده', 'body' => 'x', 'status' => 'published']);
        $draftId = $this->contents->create(['content_type' => 'news', 'slug' => 'draft', 'title' => 'پیش‌نویس محرمانه', 'body' => 'x', 'status' => 'draft']);
        $this->contents->update($draftId, ['status' => 'draft']);
        $arcId = $this->contents->create(['content_type' => 'news', 'slug' => 'arc', 'title' => 'بایگانی محرمانه', 'body' => 'x', 'status' => 'published']);
        $this->contents->update($arcId, ['status' => 'archived']);

        [$code, $body] = $this->dispatch('GET', '/news');
        $this->assertSame(200, $code);
        $this->assertContains('منتشرشده', $body);
        $this->assertNotContains('پیش‌نویس محرمانه', $body);
        $this->assertNotContains('بایگانی محرمانه', $body);
    }

    public function testDraftDetailIs404(): void
    {
        $this->contents->create(['content_type' => 'article', 'slug' => 'secret', 'title' => 'محرمانه', 'body' => 'x', 'status' => 'draft']);
        [$code] = $this->dispatch('GET', '/articles/secret');
        $this->assertSame(404, $code);
    }

    public function testSearchOutputEscapesHtml(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        [, $body] = $this->dispatch('GET', '/search?q=' . rawurlencode($payload));
        $this->assertNotContains('<img src=x onerror=alert(1)>', $body);
        $this->assertContains('&lt;img', $body);
    }

    public function testSearchTreatsSqlPayloadAsData(): void
    {
        $this->contents->create(['content_type' => 'news', 'slug' => 'keep', 'title' => 'ماندگار', 'body' => 'x', 'status' => 'published']);
        $payloads = ["' OR '1'='1", "'; DROP TABLE contents; --", '100%'];
        foreach ($payloads as $payload) {
            [$code] = $this->dispatch('GET', '/search?q=' . rawurlencode($payload));
            $this->assertSame(200, $code);
        }
        // The table and its row survive.
        $this->assertSame(1, (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0));
    }

    public function testSearchWildcardIsLiteral(): void
    {
        // A published row whose title does NOT contain a literal '%' must not
        // be returned when the user searches for '%': the wildcard is escaped.
        $this->contents->create(['content_type' => 'news', 'slug' => 'plain', 'title' => 'خبر ساده', 'body' => 'متن', 'status' => 'published']);
        $rows = $this->contents->publicSearch('%%%', 12, 0);
        $this->assertSame(0, count($rows), 'LIKE wildcards in the query must be matched literally');
    }

    public function testMediaUrlRejectsPathTraversal(): void
    {
        $this->assertSame('', media_url('../../etc/passwd'));
        $this->assertSame('', media_url('/etc/passwd'));
        $this->assertSame('', media_url('uploads/../config/local.php'));
        $this->assertSame('', media_url('uploads\\..\\secret'));
        $this->assertSame('', media_url(''));
    }

    public function testMediaUrlAllowsCleanUploadPath(): void
    {
        $this->assertContains('/uploads/media/abc.jpg', media_url('uploads/media/abc.jpg'));
    }
}
