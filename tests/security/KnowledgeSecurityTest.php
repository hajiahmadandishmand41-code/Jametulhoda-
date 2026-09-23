<?php

declare(strict_types=1);

/**
 * Phase 6 — security regression for the knowledge & multimedia surface.
 *
 * Covers the Phase 6 checklist items that live in this layer:
 *   * XSS — every dynamic value on the new pages is HTML-escaped;
 *   * SQL injection — hostile slugs/queries are inert, matched as data;
 *   * CSRF — every knowledge admin mutation rejects a missing/foreign token;
 *   * Authorization — guests, plain users and editors cannot touch the
 *     knowledge admin surface; IDOR-style probes get 404, never data;
 *   * Access control — a login-required lesson withholds its body/media
 *     server-side (crafted request parameters change nothing);
 *   * Published filtering — drafts stay unreachable from public URLs.
 */
final class KnowledgeSecurityTest extends TestCase
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
        auth_session_logout();
        http_response_code(200);
    }

    private function dispatch(string $method, string $path, array $post = []): array
    {
        $router = new Router();
        define_routes($router);

        $parts = parse_url($path);
        $routePath = (string) ($parts['path'] ?? '/');
        $_SERVER['QUERY_STRING'] = (string) ($parts['query'] ?? '');
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = [];
        if ($_SERVER['QUERY_STRING'] !== '') {
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        }
        $_POST = $post;

        http_response_code(200);
        ob_start();
        $router->dispatch($method, $routePath);
        $body = (string) ob_get_clean();
        $code = (int) http_response_code();
        http_response_code(200);

        return [$code, $body];
    }

    private function loginAs(string $role): void
    {
        SessionManager::authenticate([
            'id' => $role === 'admin' ? 1 : 42,
            'name' => 'کاربر آزمون',
            'email' => $role . '@example.test',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /* -----------------------------------------------------------------
     | XSS
     * ----------------------------------------------------------------- */

    public function testKnowledgeListingEscapesHostileTitlesAndAuthors(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        (new BookRepository())->create([
            'slug' => 'xss-book', 'title' => 'کتاب ' . $payload,
            'author' => 'نویسنده ' . $payload, 'status' => 'published',
        ]);

        [, $body] = $this->dispatch('GET', '/books');
        $this->assertNotContains('<img src=x', $body);
        $this->assertContains('&lt;img', $body);
    }

    public function testKnowledgeDetailEscapesHostileBodyAndSummary(): void
    {
        $payload = '<script>alert(1)</script>';
        (new ResearchRepository())->create([
            'slug' => 'xss-research', 'title' => 'پژوهش امن',
            'summary' => 'چکیده ' . $payload, 'body' => 'متن ' . $payload,
            'author' => 'الف', 'status' => 'published',
        ]);

        [, $body] = $this->dispatch('GET', '/research/xss-research');
        $this->assertNotContains('<script>alert(1)</script>', $body);
        $this->assertContains('&lt;script&gt;', $body);
    }

    public function testLessonsListingEscapesHostileTitles(): void
    {
        $payload = '<svg onload=alert(1)>';
        (new LessonRepository())->create([
            'slug' => 'xss-lesson', 'title' => 'درس ' . $payload, 'status' => 'published',
        ]);

        [, $body] = $this->dispatch('GET', '/lessons');
        $this->assertNotContains('<svg onload', $body);
        $this->assertContains('&lt;svg', $body);
    }

    public function testSearchQueryOnBooksIsEscaped(): void
    {
        [$code, $body] = $this->dispatch('GET', '/books?q=' . rawurlencode('"><script>x</script>'));
        $this->assertSame(200, $code);
        $this->assertNotContains('<script>x</script>', $body);
    }

    /* -----------------------------------------------------------------
     | SQL injection
     * ----------------------------------------------------------------- */

    public function testHostileKnowledgeSlugsStayInert(): void
    {
        (new BookRepository())->create(['slug' => 'safe-book', 'title' => 'کتاب سالم', 'status' => 'published']);

        foreach (["' OR '1'='1", "'; DROP TABLE contents; --", "x' UNION SELECT 1--"] as $payload) {
            [$code] = $this->dispatch('GET', '/books/' . rawurlencode($payload));
            $this->assertSame(404, $code, 'hostile slug must simply not match');

            [$code] = $this->dispatch('GET', '/lessons/' . rawurlencode($payload));
            $this->assertSame(404, $code);

            [$code] = $this->dispatch('GET', '/research/' . rawurlencode($payload));
            $this->assertSame(404, $code);
        }

        $this->assertSame(1, (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0), 'data survived untouched');
        $row = (new BookRepository())->findPublishedBySlug('safe-book');
        $this->assertTrue($row !== null, 'legitimate rows still resolve');
    }

    public function testHostileSearchQueriesAreTreatedAsData(): void
    {
        (new BookRepository())->create(['slug' => 'b-ok', 'title' => 'ماندگار', 'status' => 'published']);

        foreach (["' OR '1'='1", "'; DROP TABLE books; --", "100%' OR '1'='1"] as $payload) {
            [$code] = $this->dispatch('GET', '/books?q=' . rawurlencode($payload));
            $this->assertSame(200, $code);
        }

        $this->assertSame(1, (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0));
        $this->assertSame(1, (int) db_value('SELECT COUNT(*) FROM `books`', [], 0));
    }

    public function testLikeWildcardsInKnowledgeSearchAreLiteral(): void
    {
        (new BookRepository())->create(['slug' => 'b-plain', 'title' => 'کتاب ساده', 'status' => 'published']);

        $this->assertSame(0, (new BookRepository())->publicCount(['q' => '%%%']));
        $this->assertSame(1, (new BookRepository())->publicCount(['q' => 'کتاب ساده']));
    }

    public function testHubTypeFilterRejectsInjection(): void
    {
        $raised = false;
        try {
            (new MediaRepository())->publicHubList("video' OR '1'='1", false);
        } catch (InvalidArgumentException) {
            $raised = true;
        }
        $this->assertTrue($raised);
    }

    /* -----------------------------------------------------------------
     | CSRF on the knowledge admin surface
     * ----------------------------------------------------------------- */

    public function testKnowledgeCreateWithoutCsrfIsRejected(): void
    {
        $this->loginAs('admin');

        foreach (['books', 'lessons', 'research'] as $section) {
            [$code] = $this->dispatch('POST', '/admin/' . $section . '/new', [
                'title' => 'بدون توکن',
                'slug' => 'no-csrf-' . $section,
                'status' => 'draft',
            ]);
            $this->assertSame(403, $code, 'POST /admin/' . $section . '/new without a CSRF token must be rejected');
        }

        $this->assertSame(0, (int) db_value("SELECT COUNT(*) FROM `contents` WHERE `slug` LIKE 'no-csrf-%'", [], 0), 'nothing was written');
    }

    public function testKnowledgeDeleteWithoutCsrfIsRejected(): void
    {
        $this->loginAs('admin');
        $id = (new BookRepository())->create(['slug' => 'victim-book', 'title' => 'کتاب', 'status' => 'published']);

        [$code] = $this->dispatch('POST', '/admin/books/' . $id . '/delete', []);
        $this->assertSame(403, $code);
        $this->assertTrue((new BookRepository())->find($id) !== null, 'the row survived');
    }

    /* -----------------------------------------------------------------
     | Authorization + IDOR
     * ----------------------------------------------------------------- */

    public function testGuestCannotMutateKnowledgeContent(): void
    {
        [$code] = $this->dispatch('POST', '/admin/books/new', ['title' => 'مهمان']);
        $this->assertSame(403, $code);
    }

    public function testEditorAndUserRoleCannotMutateKnowledgeContent(): void
    {
        foreach (['user', 'editor'] as $role) {
            $this->loginAs($role);

            [$code] = $this->dispatch('POST', '/admin/books/new', ['title' => 'غیرمجاز']);
            $this->assertSame(403, $code, $role . ' must be forbidden');

            [$code] = $this->dispatch('POST', '/admin/lessons/1/delete', []);
            $this->assertSame(403, $code, $role . ' must be forbidden from deletes');
        }

        $this->assertSame(0, (int) db_value("SELECT COUNT(*) FROM `contents` WHERE `title` IN ('غیرمجاز','مهمان')", [], 0));
    }

    public function testAdminEditOfForeignContentTypeIs404(): void
    {
        $this->loginAs('admin');
        // A news id reached through the BOOKS section must not resolve.
        $newsId = (new ContentRepository())->create(['content_type' => 'news', 'slug' => 'plain-news', 'title' => 'خبر', 'status' => 'published']);

        [$code] = $this->dispatch('GET', '/admin/books/edit/' . $newsId);
        $this->assertSame(404, $code, 'IDOR: a book route must never render a non-book row');

        [$code] = $this->dispatch('GET', '/admin/lessons/edit/' . $newsId);
        $this->assertSame(404, $code);
    }

    public function testAdminDeleteOfForeignContentTypeIs404(): void
    {
        $this->loginAs('admin');
        $newsId = (new ContentRepository())->create(['content_type' => 'news', 'slug' => 'victim-news', 'title' => 'خبر', 'status' => 'published']);
        $csrf = csrf_token();

        [$code] = $this->dispatch('POST', '/admin/research/' . $newsId . '/delete', [Csrf::FIELD => $csrf]);
        $this->assertSame(404, $code, 'IDOR: research delete must never touch a non-research row');
        $this->assertTrue((new ContentRepository())->find($newsId) !== null, 'the foreign row survived');
    }

    /* -----------------------------------------------------------------
     | Access control: locked lessons
     * ----------------------------------------------------------------- */

    public function testLockedLessonNeverLeaksBodyOrAttachmentsToGuests(): void
    {
        $lessons = new LessonRepository();
        $media = new MediaRepository();
        $lessonId = $lessons->create([
            'slug' => 'locked-no-leak', 'title' => 'درس محافظت‌شده',
            'summary' => 'خلاصهٔ آزاد', 'body' => 'متنِ محافظت‌شدهٔ درس',
            'status' => 'published', 'requires_login' => true,
        ]);
        $audioId = $media->create(['media_type' => 'audio', 'disk_path' => 'uploads/audio/locked-leak.mp3', 'mime_type' => 'audio/mpeg']);
        $media->attachToContent($lessonId, $audioId, 'audio', 0);

        // Crafted parameters must not change the outcome either.
        foreach (['?page=1', '?download=1', '?preview=1&full=1'] as $suffix) {
            [$code, $body] = $this->dispatch('GET', '/lessons/locked-no-leak' . $suffix);
            $this->assertSame(200, $code);
            $this->assertNotContains('متنِ محافظت‌شدهٔ درس', $body);
            $this->assertNotContains('locked-leak.mp3', $body);
            $this->assertNotContains('<audio', $body);
        }

        // And the hub agrees with the lesson page for guests.
        [, $hubBody] = $this->dispatch('GET', '/media');
        $this->assertNotContains('locked-leak.mp3', $hubBody);
    }

    /* -----------------------------------------------------------------
     | Published filtering
     * ----------------------------------------------------------------- */

    public function testArchivedAndDraftKnowledgeAreUnreachable(): void
    {
        $id = (new BookRepository())->create(['slug' => 'arc-book', 'title' => 'بایگانی', 'status' => 'published']);
        (new ContentRepository())->update($id, ['status' => 'archived']);
        (new BookRepository())->create(['slug' => 'drf-book', 'title' => 'پیش‌نویس', 'status' => 'draft']);

        $this->assertSame(404, ($this->dispatch('GET', '/books/arc-book'))[0]);
        $this->assertSame(404, ($this->dispatch('GET', '/books/drf-book'))[0]);
        $this->assertSame(0, (new BookRepository())->publicCount());
    }
}
