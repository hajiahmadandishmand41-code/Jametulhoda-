<?php

declare(strict_types=1);

/**
 * Phase 6 — public + admin routes for the knowledge sections.
 *
 * Dispatches through the real route table against a fresh sandbox schema:
 *   * listing/detail 200 and 404 behaviour for /books, /lessons, /research;
 *   * published-only visibility (drafts are unreachable by URL);
 *   * title search, topic filter and pagination;
 *   * the lesson access rule for guests vs authenticated users;
 *   * admin registry/creation routes are guarded (admin-only).
 */
final class KnowledgeRoutesTest extends TestCase
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

    private function dispatch(string $method, string $path): array
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

        http_response_code(200);
        ob_start();
        $router->dispatch($method, $routePath);
        $body = (string) ob_get_clean();
        $code = (int) http_response_code();
        http_response_code(200);

        return [$code, $body];
    }

    private function publishBook(array $overrides = []): int
    {
        $data = array_merge([
            'slug' => 'kitab-' . bin2hex(random_bytes(3)),
            'title' => 'کتاب آزمایشی',
            'summary' => 'خلاصهٔ کتاب آزمایشی',
            'body' => 'متن کتاب آزمایشی',
            'author' => 'نویسندهٔ آزمایشی',
            'status' => 'published',
        ], $overrides);

        return (new BookRepository())->create($data);
    }

    private function publishLesson(array $overrides = []): int
    {
        $data = array_merge([
            'slug' => 'dars-' . bin2hex(random_bytes(3)),
            'title' => 'درس آزمایشی',
            'summary' => 'خلاصهٔ درس آزمایشی',
            'body' => 'متن کامل درس آزمایشی',
            'status' => 'published',
            'sort_order' => 10,
        ], $overrides);

        return (new LessonRepository())->create($data);
    }

    private function publishResearch(array $overrides = []): int
    {
        $data = array_merge([
            'slug' => 'pazhuhesh-' . bin2hex(random_bytes(3)),
            'title' => 'پژوهش آزمایشی',
            'summary' => 'چکیدهٔ پژوهش آزمایشی',
            'body' => 'متن کامل پژوهش آزمایشی',
            'author' => 'پژوهشگر آزمایشی',
            'status' => 'published',
        ], $overrides);

        return (new ResearchRepository())->create($data);
    }

    /* -----------------------------------------------------------------
     | /books
     * ----------------------------------------------------------------- */

    public function testBooksListingIsEmptyStateWithoutContent(): void
    {
        [$code, $body] = $this->dispatch('GET', '/books');
        $this->assertSame(200, $code);
        $this->assertContains('empty-state', $body);
    }

    public function testBooksListingShowsPublishedBooksWithAuthor(): void
    {
        $this->publishBook(['title' => 'کتاب نمایشی', 'author' => 'مولف نمایشی']);

        [$code, $body] = $this->dispatch('GET', '/books');
        $this->assertSame(200, $code);
        $this->assertContains('کتاب نمایشی', $body);
        $this->assertContains('مولف نمایشی', $body);
    }

    public function testDraftBookIs404OnPublicDetail(): void
    {
        $this->publishBook(['slug' => 'draft-kitab', 'status' => 'draft']);

        [$code] = $this->dispatch('GET', '/books/draft-kitab');
        $this->assertSame(404, $code);
    }

    public function testUnknownBookSlugIs404(): void
    {
        [$code, $body] = $this->dispatch('GET', '/books/ناموجود-همیشه');
        $this->assertSame(404, $code);
        $this->assertContains('صفحه پیدا نشد', $body);
    }

    public function testBookDetailShowsBodyAndStructuredData(): void
    {
        $this->publishBook(['slug' => 'kitab-seo', 'title' => 'کتاب سئو', 'body' => 'متن کامل کتاب سئو']);

        [$code, $body] = $this->dispatch('GET', '/books/kitab-seo');
        $this->assertSame(200, $code);
        $this->assertContains('متن کامل کتاب سئو', $body);
        $this->assertContains('"@type":"Book"', $body);
        $this->assertContains('rel="canonical"', $body);
        $this->assertContains('og:title', $body);
    }

    public function testBooksSearchFilter(): void
    {
        $this->publishBook(['slug' => 'b-hit', 'title' => 'عبارةالمعراف']);
        $this->publishBook(['slug' => 'b-miss', 'title' => 'دیگر کتاب']);

        [$code, $body] = $this->dispatch('GET', '/books?q=' . rawurlencode('عبارة'));
        $this->assertSame(200, $code);
        $this->assertContains('عبارةالمعراف', $body);
        $this->assertNotContains('دیگر کتاب', $body);
    }

    public function testBooksPagination(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->publishBook(['slug' => 'pag-book-' . $i, 'title' => 'کتاب شمارهٔ ' . fa_digits((string) $i)]);
        }

        // Newest first: page 2 holds the OLDEST row (شمارهٔ ۱).
        [$code, $body] = $this->dispatch('GET', '/books?page=2');
        $this->assertSame(200, $code);
        $this->assertContains('کتاب شمارهٔ ۱<', $body);
        $this->assertNotContains('کتاب شمارهٔ ۲', $body);
    }

    public function testBooksTopicFilter(): void
    {
        $topicId = (new TopicRepository())->create(['slug' => 'tarikh', 'title' => 'تاریخ']);
        $this->publishBook(['slug' => 'b-top', 'title' => 'کتاب تاریخی', 'topic_id' => $topicId]);
        $this->publishBook(['slug' => 'b-notop', 'title' => 'کتاب بی‌موضوع']);

        [$code, $body] = $this->dispatch('GET', '/books?topic=tarikh');
        $this->assertSame(200, $code);
        $this->assertContains('کتاب تاریخی', $body);
        $this->assertNotContains('کتاب بی‌موضوع', $body);
    }

    /* -----------------------------------------------------------------
     | /lessons
     * ----------------------------------------------------------------- */

    public function testLessonsListingRendersInOrder(): void
    {
        (new LessonRepository())->create(['slug' => 'l-a', 'title' => 'درس الف', 'status' => 'published', 'sort_order' => 2]);
        (new LessonRepository())->create(['slug' => 'l-b', 'title' => 'درس ب', 'status' => 'published', 'sort_order' => 1]);

        [$code, $body] = $this->dispatch('GET', '/lessons');
        $this->assertSame(200, $code);
        $this->assertContains('lesson-list', $body);
        $this->assertTrue(mb_strpos($body, 'درس ب', 0, 'UTF-8') < mb_strpos($body, 'درس الف', 0, 'UTF-8'), 'curriculum order = sort_order');
    }

    public function testLessonDetailRenders(): void
    {
        $this->publishLesson(['slug' => 'dars-detail', 'title' => 'درس جزئیات']);

        [$code, $body] = $this->dispatch('GET', '/lessons/dars-detail');
        $this->assertSame(200, $code);
        $this->assertContains('درس جزئیات', $body);
        $this->assertContains('متن کامل درس آزمایشی', $body);
        $this->assertContains('"@type":"LearningResource"', $body);
    }

    public function testLockedLessonWithholdsBodyAndMediaFromGuests(): void
    {
        $this->publishLesson(['slug' => 'dars-locked', 'requires_login' => true]);

        [$code, $body] = $this->dispatch('GET', '/lessons/dars-locked');
        $this->assertSame(200, $code);
        $this->assertContains('lock-panel', $body, 'guest sees the access notice');
        $this->assertContains('خلاصهٔ درس آزمایشی', $body, 'the public teaser stays visible');
        $this->assertNotContains('متن کامل درس آزمایشی', $body, 'the body must never reach a guest');
        $this->assertContains('/login?redirect=', $body);
    }

    public function testLockedLessonIsFullyVisibleToAuthenticatedUsers(): void
    {
        $this->publishLesson(['slug' => 'dars-open-for-user', 'requires_login' => true]);
        SessionManager::authenticate([
            'id' => 9, 'name' => 'کاربر عادی', 'email' => 'user@example.test',
            'role' => 'user', 'is_active' => true,
        ]);

        [$code, $body] = $this->dispatch('GET', '/lessons/dars-open-for-user');
        $this->assertSame(200, $code);
        $this->assertContains('متن کامل درس آزمایشی', $body);
        $this->assertNotContains('lock-panel', $body);
    }

    /* -----------------------------------------------------------------
     | /research
     * ----------------------------------------------------------------- */

    public function testResearchListingAndDetail(): void
    {
        $this->publishResearch(['slug' => 'p-detail', 'title' => 'پژوهش نمایشی']);

        [$code, $body] = $this->dispatch('GET', '/research');
        $this->assertSame(200, $code);
        $this->assertContains('پژوهش نمایشی', $body);

        [$code, $body] = $this->dispatch('GET', '/research/p-detail');
        $this->assertSame(200, $code);
        $this->assertContains('"@type":"ScholarlyArticle"', $body);
        $this->assertContains('is-reading', $body, 'long-form reading layout');
    }

    public function testResearchSearchFilter(): void
    {
        $this->publishResearch(['slug' => 'p-hit', 'title' => 'فقه العبادات']);
        $this->publishResearch(['slug' => 'p-miss', 'title' => 'موضوع دیگر']);

        [$code, $body] = $this->dispatch('GET', '/research?q=' . rawurlencode('فقه'));
        $this->assertSame(200, $code);
        $this->assertContains('فقه العبادات', $body);
        $this->assertNotContains('موضوع دیگر', $body);
    }

    /* -----------------------------------------------------------------
     | Sitemap integration
     * ----------------------------------------------------------------- */

    public function testSitemapIncludesKnowledgeSectionsAndItems(): void
    {
        $this->publishBook(['slug' => 'sm-book']);
        $this->publishLesson(['slug' => 'sm-lesson']);
        $this->publishResearch(['slug' => 'sm-research']);

        [$code, $body] = $this->dispatch('GET', '/sitemap.xml');
        $this->assertSame(200, $code);
        foreach (['/books', '/lessons', '/research', '/media', '/books/sm-book', '/lessons/sm-lesson', '/research/sm-research'] as $loc) {
            $this->assertContains(e($loc), $body);
        }
    }

    /* -----------------------------------------------------------------
     | Admin guards for the knowledge sections
     * ----------------------------------------------------------------- */

    public function testAdminKnowledgeSectionsRejectGuestsWithoutDisclosure(): void
    {
        // Same contract as the Phase 4 registry routes: an anonymous request
        // is answered with 403 (no redirect that could act as a probe).
        foreach (['/admin/books', '/admin/lessons', '/admin/research'] as $path) {
            [$code] = $this->dispatch('GET', $path);
            $this->assertSame(403, $code, $path);
        }
    }

    public function testAdminKnowledgeSectionsAreForbiddenForNonAdmins(): void
    {
        SessionManager::authenticate([
            'id' => 5, 'name' => 'کاربر', 'email' => 'plain@example.test',
            'role' => 'user', 'is_active' => true,
        ]);

        foreach (['/admin/books', '/admin/lessons', '/admin/research'] as $path) {
            [$code] = $this->dispatch('GET', $path);
            $this->assertSame(403, $code, $path . ' must be admin-only');
        }
    }

    public function testAdminKnowledgeRegistryRendersForAdmin(): void
    {
        SessionManager::authenticate([
            'id' => 1, 'name' => 'مدیر', 'email' => 'admin@example.test',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->publishBook(['slug' => 'adm-book', 'title' => 'کتاب مدیریتی']);

        [$code, $body] = $this->dispatch('GET', '/admin/books');
        $this->assertSame(200, $code);
        $this->assertContains('کتاب مدیریتی', $body);

        [$code, $body] = $this->dispatch('GET', '/admin/books/new');
        $this->assertSame(200, $code);
        $this->assertContains('csrf', $body, 'the create form carries the CSRF field');
    }

    public function testAdminEditUnknownIdIs404(): void
    {
        SessionManager::authenticate([
            'id' => 1, 'name' => 'مدیر', 'email' => 'admin@example.test',
            'role' => 'admin', 'is_active' => true,
        ]);

        [$code] = $this->dispatch('GET', '/admin/books/edit/424242');
        $this->assertSame(404, $code);
    }

    private function dispatchPost(string $path, array $post): array
    {
        $parts = parse_url($path);
        $_SERVER['QUERY_STRING'] = (string) ($parts['query'] ?? '');
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = [];
        parse_str($_SERVER['QUERY_STRING'], $_GET);
        $_POST = $post;

        $router = new Router();
        define_routes($router);
        http_response_code(200);
        ob_start();
        try {
            $router->dispatch('POST', (string) ($parts['path'] ?? '/'));
        } catch (RedirectException $e) {
            // bootstrap.php replaces redirect() with a throwing double so a
            // successful mutation surfaces as an exception, not an exit().
            ob_end_clean();
            return [$e->status, $e->path];
        }
        $body = (string) ob_get_clean();
        $code = (int) http_response_code();
        http_response_code(200);

        return [$code, $body];
    }

    /**
     * The full admin create round trip through the real route with a VALID
     * CSRF token — this is the only path that executes the POST closure's
     * validation closure end to end (the CSRF-403 tests reject earlier).
     */
    public function testAdminCreateBookViaPostPersistsAndRedirects(): void
    {
        SessionManager::authenticate([
            'id' => 1, 'name' => 'مدیر', 'email' => 'admin@example.test',
            'role' => 'admin', 'is_active' => true,
        ]);

        [$code, $body] = $this->dispatchPost('/admin/books/new', [
            Csrf::FIELD => csrf_token(),
            'slug' => 'post-created-book',
            'title' => 'کتاب ساخته‌شده با فرم',
            'summary' => 'خلاصهٔ فرم',
            'body' => 'متن کامل کتاب ساخته‌شده با فرم',
            'status' => 'published',
            'author' => 'مؤلف فرمی',
            'published_at' => '2026-01-15T10:00',
        ]);
        $this->assertSame(303, $code, 'valid create must redirect: ' . $body);

        $item = (new BookRepository())->findPublishedBySlug('post-created-book');
        $this->assertTrue($item !== [], 'the created row must exist');
        $this->assertSame('کتاب ساخته‌شده با فرم', $item['title']);
        $this->assertSame('مؤلف فرمی', $item['author']);
        $this->assertSame('published', $item['status']);
    }

    /** Same round trip for lessons, covering sort order + login lock + edit sync. */
    public function testAdminCreateAndEditLessonViaPostPersistsAndRedirects(): void
    {
        SessionManager::authenticate([
            'id' => 1, 'name' => 'مدیر', 'email' => 'admin@example.test',
            'role' => 'admin', 'is_active' => true,
        ]);

        [$code, $body] = $this->dispatchPost('/admin/lessons/new', [
            Csrf::FIELD => csrf_token(),
            'slug' => 'post-created-lesson',
            'title' => 'درس ساخته‌شده با فرم',
            'summary' => 'خلاصهٔ درس فرمی',
            'body' => 'متن درس',
            'status' => 'published',
            'sort_order' => '7',
            'requires_login' => '1',
        ]);
        $this->assertSame(303, $code, 'valid create must redirect: ' . $body);

        $lessonRepo = new LessonRepository();
        $item = $lessonRepo->findPublishedBySlug('post-created-lesson');
        $this->assertTrue($item !== []);
        $this->assertSame(7, (int) $item['sort_order']);
        $this->assertSame(1, (int) $item['requires_login']);

        // Edit round trip: change title, order and unlock.
        $id = (int) $item['id'];
        [$code, $body] = $this->dispatchPost('/admin/lessons/edit/' . $id, [
            Csrf::FIELD => csrf_token(),
            'slug' => 'post-created-lesson',
            'title' => 'درس ویرایش‌شده',
            'summary' => 'خلاصهٔ ویرایش‌شده',
            'body' => 'متن ویرایش‌شده',
            'status' => 'published',
            'sort_order' => '3',
        ]);
        $this->assertSame(303, $code, 'valid edit must redirect: ' . $body);

        $updated = $lessonRepo->find($id);
        $this->assertSame('درس ویرایش‌شده', $updated['title']);
        $this->assertSame(3, (int) $updated['sort_order']);
        $this->assertSame(0, (int) $updated['requires_login'], 'unchecking the lock must clear it');
    }

    /** A duplicate slug must re-render the form with the Persian error, not crash. */
    public function testAdminCreateWithDuplicateSlugReRendersFormWithErrors(): void
    {
        SessionManager::authenticate([
            'id' => 1, 'name' => 'مدیر', 'email' => 'admin@example.test',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->publishBook(['slug' => 'dup-slug', 'title' => 'کتاب موجود']);

        [$code, $body] = $this->dispatchPost('/admin/books/new', [
            Csrf::FIELD => csrf_token(),
            'slug' => 'dup-slug',
            'title' => 'کتاب تکراری',
            'summary' => '',
            'body' => 'متن',
            'status' => 'published',
            'author' => '',
        ]);
        $this->assertSame(200, $code, 'validation failure must re-render the form');
        $this->assertContains('این نامک قبلاً استفاده شده است.', $body, 'the slug error must be shown in Persian');
        $this->assertContains('کتاب تکراری', $body, 'the submitted title must be redisplayed');
    }
}
