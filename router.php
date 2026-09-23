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
        // The home page is the site's entry point: a transient database
        // problem should degrade to a professional empty state, never a hard
        // 500 for every visitor. Detail/listing pages still surface errors.
        $news = $articles = $reports = $events = $topics = $books = $lessons = $research = $mediaItems = [];
        try {
            $repo = new ContentRepository();
            $news = $repo->publicList('news', 7, 0);
            $articles = $repo->publicList('article', 4, 0);
            $reports = $repo->publicList('report', 4, 0);
            $events = $repo->publicList('event', 4, 0);
            $books = (new BookRepository())->publicList([], 4, 0);
            $lessons = (new LessonRepository())->publicList([], 4, 0);
            $research = (new ResearchRepository())->publicList([], 4, 0);
            $mediaItems = (new MediaRepository())->publicHubList(null, false, 3, 0);
            $topics = (new TopicRepository())->allActive();
        } catch (Throwable $e) {
            log_error('Home page data load failed: ' . get_class($e));
        }
        view('home', [
            'title' => '',
            'metaDescription' => (string) site_setting('description'),
            'featured' => $news[0] ?? null,
            'secondary' => array_slice($news, 1, 4),
            'latest' => array_slice($news, 1, 6),
            'articles' => $articles,
            'reports' => $reports,
            'events' => $events,
            'books' => $books,
            'lessons' => $lessons,
            'research' => $research,
            'mediaItems' => $mediaItems,
            'topics' => $topics,
            'isHome' => true,
            'jsonLd' => organization_json_ld(),
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
        if (!requireRole('editor')) {
            admin_forbidden();
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
                'book' => $contents->count(['content_type' => 'book']),
                'lesson' => $contents->count(['content_type' => 'lesson']),
                'research' => $contents->count(['content_type' => 'research']),
                'media' => (new MediaRepository())->count(),
                'topics' => (new TopicRepository())->count(['is_active' => 1]),
            ],
            'latest' => $contents->adminList(null, null, '', 8, 0),
            'typeLabels' => [
                'article' => 'مقالات',
                'news' => 'خبرها',
                'event' => 'رویدادها',
                'report' => 'گزارش‌ها',
                'book' => 'کتاب‌ها',
                'lesson' => 'درس‌ها',
                'research' => 'پژوهش‌ها',
            ],
        ]);
    });

    // -----------------------------------------------------------------
    // Public Phase 5 content surface.
    // Only 'published' rows (published_at <= now) are ever returned; the
    // repositories enforce that at the SQL layer, so drafts/archived rows
    // can never be reached from a public URL.
    // -----------------------------------------------------------------
    $publicSections = [
        'news'     => ['type' => 'news',    'label' => 'خبرها',    'intro' => 'تازه‌ترین خبرهای دینی و فرهنگی'],
        'articles' => ['type' => 'article', 'label' => 'مقالات',   'intro' => 'مقالات و یادداشت‌های تحلیلی'],
        'reports'  => ['type' => 'report',  'label' => 'گزارش‌ها', 'intro' => 'گزارش‌های میدانی و مصور'],
        'events'   => ['type' => 'event',   'label' => 'رویدادها', 'intro' => 'مناسبت‌ها و رویدادهای پیشِ‌رو'],
    ];
    foreach ($publicSections as $publicPath => $section) {
        $type = $section['type'];
        $label = $section['label'];
        $intro = $section['intro'];

        $router->get('/' . $publicPath, static function () use ($type, $label, $intro, $publicPath): void {
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $limit = 12;
            $items = [];
            $total = 0;
            $pages = 1;
            try {
                $repo = new ContentRepository();
                $total = $repo->publicCount($type);
                $pages = max(1, (int) ceil($total / $limit));
                $page = min($page, $pages);
                $items = $repo->publicList($type, $limit, ($page - 1) * $limit);
            } catch (Throwable $e) {
                log_error('Public listing data load failed: ' . get_class($e));
            }
            view('public_listing', [
                'title' => $label,
                'metaDescription' => $intro,
                'heading' => $label,
                'intro' => $intro,
                'type' => $type,
                'items' => $items,
                'page' => $page,
                'pages' => $pages,
                'total' => $total,
                'path' => '/' . $publicPath,
            ]);
        });

        $router->get('/' . $publicPath . '/{slug}', static function (array $params) use ($type): void {
            $slug = rawurldecode($params['slug']);
            $repo = new ContentRepository();
            $item = $repo->findPublishedDetail($type, $slug);
            if (!$item) {
                http_response_code(404);
                view('404', ['title' => 'صفحه پیدا نشد', 'metaDescription' => '', 'noindex' => true]);
                return;
            }

            $id = (int) $item['id'];
            $media = (new MediaRepository())->forContent($id);
            $gallery = $type === 'report' ? (new ReportRepository())->images($id) : [];
            if ($type === 'event') {
                $eventExtra = (new EventRepository())->find($id);
                if ($eventExtra) {
                    $item['starts_at'] = $eventExtra['starts_at'] ?? null;
                    $item['ends_at'] = $eventExtra['ends_at'] ?? null;
                    $item['location'] = $eventExtra['location'] ?? null;
                }
            }
            if ($type === 'report') {
                $reportExtra = (new ReportRepository())->find($id);
                if ($reportExtra) {
                    $item['event_date'] = $reportExtra['event_date'] ?? null;
                    $item['location'] = $reportExtra['location'] ?? null;
                }
            }

            view('public_detail', [
                'title' => (string) $item['title'],
                'metaDescription' => excerpt((string) ($item['summary'] ?? $item['body'] ?? ''), 160),
                'item' => $item,
                'type' => $type,
                'related' => $repo->relatedPublished($id),
                'media' => $media,
                'gallery' => $gallery,
                'jsonLd' => content_json_ld($item, $type),
            ]);
        });
    }

    $router->get('/search', static function (): void {
        $q = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
        $q = mb_substr($q, 0, 120, 'UTF-8');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 12;
        $minLen = 2;
        $repo = new ContentRepository();

        $tooShort = $q !== '' && mb_strlen($q, 'UTF-8') < $minLen;
        $items = [];
        $total = 0;
        $pages = 1;
        if ($q !== '' && !$tooShort) {
            try {
                $items = $repo->publicSearch($q, $limit, ($page - 1) * $limit);
                $total = $repo->publicCount(null, null, $q);
                $pages = max(1, (int) ceil($total / $limit));
                $page = min($page, $pages);
            } catch (Throwable $e) {
                log_error('Search data load failed: ' . get_class($e));
            }
        }

        view('search', [
            'title' => $q !== '' ? ('جستجو: ' . $q) : 'جستجو',
            'metaDescription' => 'جستجو در محتوای منتشرشده',
            'query' => $q,
            'tooShort' => $tooShort,
            'minLen' => $minLen,
            'items' => $items,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'noindex' => true,
        ]);
    });

    $router->get('/topics/{slug}', static function (array $params): void {
        try {
            $topic = (new TopicRepository())->findBySlug(rawurldecode($params['slug']));
        } catch (Throwable $e) {
            log_error('Topic data load failed: ' . get_class($e));
            $topic = null;
        }
        if (!$topic) {
            http_response_code(404);
            view('404', ['title' => 'صفحه پیدا نشد', 'metaDescription' => '', 'noindex' => true]);
            return;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 12;
        $items = [];
        $total = 0;
        $pages = 1;
        try {
            $repo = new ContentRepository();
            $total = $repo->publicCount(null, (int) $topic['id']);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            $items = $repo->publicByTopic((int) $topic['id'], $limit, ($page - 1) * $limit);
        } catch (Throwable $e) {
            log_error('Topic content load failed: ' . get_class($e));
        }
        view('topic', [
            'title' => (string) $topic['title'],
            'metaDescription' => excerpt((string) ($topic['description'] ?? ('آخرین محتوای مرتبط با ' . $topic['title'])), 160),
            'topic' => $topic,
            'items' => $items,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'path' => '/topics/' . rawurlencode((string) $topic['slug']),
        ]);
    });

    // -----------------------------------------------------------------
    // Phase 6 — knowledge & multimedia surface.
    // Same publication rule as Phase 5: only 'published' rows with
    // published_at <= now ever leave the repository; the filter lives in
    // the SQL layer, so draft/archived rows are unreachable by URL.
    // -----------------------------------------------------------------

    $router->get('/books', static function (): void {
        // Like the home page, a listing is the section's entry point: a
        // transient database problem degrades to an empty state, not a 500.
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 12;
        $q = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
        $q = mb_substr($q, 0, 120, 'UTF-8');
        $topicSlug = is_string($_GET['topic'] ?? null) ? trim((string) $_GET['topic']) : '';
        $items = [];
        $total = 0;
        $pages = 1;
        $topics = [];
        try {
            $topics = (new TopicRepository())->allActive();
            $topic = null;
            foreach ($topics as $t) {
                if ((string) $t['slug'] === $topicSlug) {
                    $topic = $t;
                    break;
                }
            }

            $repo = new BookRepository();
            $filters = ['q' => $q, 'topic' => $topic !== null ? (int) $topic['id'] : null];
            $total = $repo->publicCount($filters);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            $items = $repo->publicList($filters, $limit, ($page - 1) * $limit);
        } catch (Throwable $e) {
            log_error('Books listing data load failed: ' . get_class($e));
            $items = [];
            $total = 0;
            $pages = 1;
        }

        view('knowledge_listing', [
            'title' => 'کتاب‌ها',
            'metaDescription' => 'کتاب‌های دینی و آموزشی منتشرشده؛ جستجو بر اساس عنوان یا نویسنده و موضوع.',
            'heading' => 'کتاب‌ها',
            'intro' => 'کتاب‌خانهٔ مذهبی پورتال؛ برای مطالعه کتابی را برگزینید.',
            'sectionPath' => '/books',
            'sectionLabel' => 'کتاب',
            'items' => $items,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'query' => $q,
            'topics' => $topics,
            'activeTopicSlug' => $topicSlug,
            'showAuthor' => true,
        ]);
    });

    $router->get('/books/{slug}', static function (array $params): void {
        $slug = rawurldecode($params['slug']);
        $repo = new BookRepository();
        $item = $repo->findPublishedBySlug($slug);
        if (!$item) {
            http_response_code(404);
            view('404', ['title' => 'صفحه پیدا نشد', 'metaDescription' => '', 'noindex' => true]);
            return;
        }

        $id = (int) $item['id'];
        $related = (new ContentRepository())->relatedPublished($id);
        if ($related === [] && !empty($item['topic_id'])) {
            $related = array_slice($repo->publicList(
                ['topic' => (int) $item['topic_id']],
                5,
                0
            ), 0, 4);
            $related = array_values(array_filter($related, static fn (array $r): bool => (int) $r['id'] !== $id));
        }

        view('public_detail', [
            'title' => (string) $item['title'],
            'metaDescription' => excerpt((string) ($item['summary'] ?? $item['body'] ?? ''), 160),
            'item' => $item,
            'type' => 'book',
            'related' => $related,
            'media' => (new MediaRepository())->forContent($id),
            'jsonLd' => content_json_ld($item, 'book'),
        ]);
    });

    $router->get('/lessons', static function (): void {
        // Same graceful degradation as the home page (see /books above).
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 12;
        $topicSlug = is_string($_GET['topic'] ?? null) ? trim((string) $_GET['topic']) : '';
        $items = [];
        $total = 0;
        $pages = 1;
        $topics = [];
        try {
            $topics = (new TopicRepository())->allActive();
            $topic = null;
            foreach ($topics as $t) {
                if ((string) $t['slug'] === $topicSlug) {
                    $topic = $t;
                    break;
                }
            }

            $repo = new LessonRepository();
            $filters = ['topic' => $topic !== null ? (int) $topic['id'] : null];
            $total = $repo->publicCount($filters);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            $items = $repo->publicList($filters, $limit, ($page - 1) * $limit);
        } catch (Throwable $e) {
            log_error('Lessons listing data load failed: ' . get_class($e));
        }

        view('lessons', [
            'title' => 'درس‌ها',
            'metaDescription' => 'درس‌ها و دوره‌های آموزش مذهبی به ترتیب آموزشی.',
            'heading' => 'درس‌ها',
            'intro' => 'دوره‌های آموزشی به ترتیب آموزشی؛ از مقدماتی تا پیشرفته.',
            'sectionPath' => '/lessons',
            'items' => $items,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'topics' => $topics,
            'activeTopicSlug' => $topicSlug,
        ]);
    });

    $router->get('/lessons/{slug}', static function (array $params): void {
        $slug = rawurldecode($params['slug']);
        $repo = new LessonRepository();
        $item = $repo->findPublishedBySlug($slug);
        if (!$item) {
            http_response_code(404);
            view('404', ['title' => 'صفحه پیدا نشد', 'metaDescription' => '', 'noindex' => true]);
            return;
        }

        $id = (int) $item['id'];
        // Access rule (server-side; the view can only render what it gets):
        // a login-required lesson shows its public teaser to guests, while
        // the body and attached media are withheld entirely.
        $locked = !empty($item['requires_login']) && !isAuthenticated();

        $related = (new ContentRepository())->relatedPublished($id);
        if ($related === []) {
            $related = $repo->relatedPublished($id, !empty($item['topic_id']) ? (int) $item['topic_id'] : null);
        }

        if ($locked) {
            $item['body'] = null;
            $media = [];
        } else {
            $media = (new MediaRepository())->forContent($id);
        }

        view('public_detail', [
            'title' => (string) $item['title'],
            'metaDescription' => excerpt((string) ($item['summary'] ?? ''), 160),
            'item' => $item,
            'type' => 'lesson',
            'related' => $related,
            'media' => $media,
            'locked' => $locked,
            'jsonLd' => content_json_ld($item, 'lesson'),
        ]);
    });

    $router->get('/research', static function (): void {
        // Same graceful degradation as the home page (see /books above).
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 12;
        $q = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
        $q = mb_substr($q, 0, 120, 'UTF-8');
        $topicSlug = is_string($_GET['topic'] ?? null) ? trim((string) $_GET['topic']) : '';
        $items = [];
        $total = 0;
        $pages = 1;
        $topics = [];
        try {
            $topics = (new TopicRepository())->allActive();
            $topic = null;
            foreach ($topics as $t) {
                if ((string) $t['slug'] === $topicSlug) {
                    $topic = $t;
                    break;
                }
            }

            $repo = new ResearchRepository();
            $filters = ['q' => $q, 'topic' => $topic !== null ? (int) $topic['id'] : null];
            $total = $repo->publicCount($filters);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            $items = $repo->publicList($filters, $limit, ($page - 1) * $limit);
        } catch (Throwable $e) {
            log_error('Research listing data load failed: ' . get_class($e));
        }

        view('knowledge_listing', [
            'title' => 'پژوهش‌ها',
            'metaDescription' => 'پژوهش‌های دینی و مذهبی؛ مطالعهٔ متن کامل پژوهش‌ها.',
            'heading' => 'پژوهش‌ها',
            'intro' => 'مطالعات و پژوهش‌های علمی حوزوی و دینی.',
            'sectionPath' => '/research',
            'sectionLabel' => 'پژوهش',
            'items' => $items,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'query' => $q,
            'topics' => $topics,
            'activeTopicSlug' => $topicSlug,
            'showAuthor' => true,
        ]);
    });

    $router->get('/research/{slug}', static function (array $params): void {
        $slug = rawurldecode($params['slug']);
        $repo = new ResearchRepository();
        $item = $repo->findPublishedBySlug($slug);
        if (!$item) {
            http_response_code(404);
            view('404', ['title' => 'صفحه پیدا نشد', 'metaDescription' => '', 'noindex' => true]);
            return;
        }

        $id = (int) $item['id'];
        $related = (new ContentRepository())->relatedPublished($id);
        if ($related === []) {
            $related = $repo->relatedPublished($id, !empty($item['topic_id']) ? (int) $item['topic_id'] : null);
        }

        view('public_detail', [
            'title' => (string) $item['title'],
            'metaDescription' => excerpt((string) ($item['summary'] ?? $item['body'] ?? ''), 160),
            'item' => $item,
            'type' => 'research',
            'related' => $related,
            'media' => (new MediaRepository())->forContent($id),
            'jsonLd' => content_json_ld($item, 'research'),
        ]);
    });

    $router->get('/media', static function (): void {
        $type = in_array($_GET['type'] ?? '', ['video', 'audio'], true) ? (string) $_GET['type'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 12;
        $viewerAuthenticated = isAuthenticated();
        $items = [];
        $total = 0;
        $pages = 1;
        $relatedByMedia = [];

        // One page query + one count + one batched related-content query —
        // no per-card extra round trips. A transient database problem
        // degrades to the empty state instead of a 500.
        try {
            $repo = new MediaRepository();
            $total = $repo->publicHubCount($type, $viewerAuthenticated);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            $items = $repo->publicHubList($type, $viewerAuthenticated, $limit, ($page - 1) * $limit);
            $relatedByMedia = $repo->publishedContentsForMedia(
                array_map(static fn (array $m): int => (int) $m['id'], $items),
                $viewerAuthenticated
            );
        } catch (Throwable $e) {
            log_error('Media hub data load failed: ' . get_class($e));
        }

        view('media_hub', [
            'title' => 'رسانه',
            'metaDescription' => 'مرکز چندرسانه‌ای؛ ویدیوها و فایل‌های صوتی آموزشی و مذهبی.',
            'heading' => 'مرکز رسانه',
            'intro' => 'ویدیوها و فایل‌های صوتی منتشرشده در یک نگاه.',
            'items' => $items,
            'relatedByMedia' => $relatedByMedia,
            'activeType' => $type,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    });

    $router->get('/sitemap.xml', static function (): void {
        header('Content-Type: application/xml; charset=UTF-8');
        $now = date('Y-m-d\TH:i:sP');
        $entries = [['loc' => absolute_url('/'), 'lastmod' => $now]];
        foreach (['/news', '/articles', '/reports', '/events', '/books', '/lessons', '/research', '/media'] as $listing) {
            $entries[] = ['loc' => absolute_url($listing), 'lastmod' => $now];
        }
        // A transient database problem must not break the whole sitemap:
        // it degrades to the static section list (crawlers retry later).
        try {
            $repo = new ContentRepository();
            foreach (['news', 'article', 'report', 'event', 'book', 'lesson', 'research'] as $type) {
                foreach ($repo->publicList($type, 50, 0) as $item) {
                    $entries[] = [
                        'loc' => absolute_url(content_url($type, (string) $item['slug'])),
                        'lastmod' => !empty($item['updated_at']) ? date('Y-m-d\TH:i:sP', strtotime((string) $item['updated_at'])) : $now,
                    ];
                }
            }
            foreach ((new TopicRepository())->allActive() as $topic) {
                $entries[] = ['loc' => absolute_url('/topics/' . rawurlencode((string) $topic['slug'])), 'lastmod' => $now];
            }
        } catch (Throwable $e) {
            log_error('Sitemap data load failed: ' . get_class($e));
        }
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            echo '  <url><loc>' . e($entry['loc']) . '</loc><lastmod>' . e($entry['lastmod']) . '</lastmod></url>' . "\n";
        }
        echo '</urlset>' . "\n";
    });

    $router->get('/robots.txt', static function (): void {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /admin\n";
        echo "Disallow: /login\n";
        echo "Disallow: /search\n";
        echo 'Sitemap: ' . absolute_url('/sitemap.xml') . "\n";
    });

    // Phase 4 newsroom: all mutations are admin-only and CSRF protected.
    $adminOnly = static function (): bool {
        if (!isAuthenticated()) {
            redirect('/login?redirect=' . rawurlencode(current_path()));
        }

        return authorize('admin.access');
    };
    $superAdminOnly = static function (): bool {
        if (!isAuthenticated()) {
            redirect('/login?redirect=' . rawurlencode(current_path()));
        }

        return requireRole('admin');
    };
    $router->get('/admin/settings', static function () use ($superAdminOnly): void {
        if (!$superAdminOnly()) { admin_forbidden(); return; }
        admin_view('settings', ['title' => 'تنظیمات سایت', 'settings' => [
            'name' => site_setting('name'), 'description' => site_setting('description'),
        ], 'saved' => ($_GET['saved'] ?? '') === '1', 'errors' => []]);
    });

    $router->add('POST', '/admin/settings', static function () use ($superAdminOnly): void {
        if (!$superAdminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) {
            admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست یا حجم درخواست بیش از حد مجاز هاست است. صفحه را تازه‌سازی کنید.', '/admin/settings');
            return;
        }
        try {
            SiteSettings::save($_POST, $_FILES);
            redirect('/admin/settings?saved=1', 303);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            $errors = [$e->getMessage()];
        } catch (Throwable $e) {
            log_error('Site settings save failed: ' . get_class($e));
            http_response_code(503);
            $errors = ['ذخیره انجام نشد. اتصال دیتابیس، اجرای migration تنظیمات و دسترسی نوشتن uploads/site را بررسی کنید.'];
        }
        admin_view('settings', ['title' => 'تنظیمات سایت', 'settings' => [
            'name' => is_string($_POST['name'] ?? null) ? $_POST['name'] : site_setting('name'),
            'description' => is_string($_POST['description'] ?? null) ? $_POST['description'] : site_setting('description'),
        ], 'saved' => false, 'errors' => $errors]);
    });

    foreach (['news'=>'خبرها','article'=>'مقالات','report'=>'گزارش‌ها','event'=>'رویدادها'] as $contentAlias => $contentAliasLabel) {
        // Correct plural section path per alias (bug fix: news used to
        // register the broken URL /admin/newss).
        $adminSectionPath = ['news' => 'news', 'article' => 'articles', 'report' => 'reports', 'event' => 'events'][$contentAlias];
        $router->get('/admin/' . $adminSectionPath, static function () use ($adminOnly, $contentAlias, $contentAliasLabel, $adminSectionPath): void {
            if (!$adminOnly()) { admin_forbidden(); return; }
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $limit = 20;
            $status = in_array($_GET['status'] ?? '', ContentRepository::STATUSES, true) ? (string) $_GET['status'] : null;
            $q = is_string($_GET['q'] ?? null) ? mb_substr(trim((string) $_GET['q']), 0, 120, 'UTF-8') : '';
            $repo = new ContentRepository();
            $total = $repo->adminCount($contentAlias, $status, $q);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            admin_view('content_registry', [
                'title' => $contentAliasLabel,
                'heading' => $contentAliasLabel,
                'registryPath' => '/admin/' . $adminSectionPath,
                'rows' => $repo->adminList($contentAlias, $status, $q, $limit, ($page - 1) * $limit),
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'filters' => ['type' => $contentAlias, 'status' => $status ?? '', 'q' => $q],
            ]);
        });
    }
    $contentTypeLabels = [
        'news' => 'خبر',
        'article' => 'مقاله',
        'report' => 'گزارش',
        'event' => 'رویداد',
        'book' => 'کتاب',
        'lesson' => 'درس',
        'research' => 'پژوهش',
    ];
    $statusLabels = ['draft' => 'پیش‌نویس', 'published' => 'منتشرشده', 'archived' => 'بایگانی'];

    $contentMediaIdsFromPost = static function (string $field): array {
        $raw = $_POST[$field] ?? [];
        if (!is_array($raw)) {
            $raw = $raw === '' || $raw === null ? [] : [$raw];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    };

    $contentFormData = static function (array $item = []): array {
        $mediaRepo = new MediaRepository();
        $contentRepo = new ContentRepository();
        $id = !empty($item['id']) ? (int) $item['id'] : null;

        return [
            'topics' => (new TopicRepository())->allActive(),
            'coverMedia' => $mediaRepo->listByType('image', 200, 0),
            'mediaByRole' => [
                'video' => $mediaRepo->listByType('video', 200, 0),
                'audio' => $mediaRepo->listByType('audio', 200, 0),
                'document' => $mediaRepo->listByType('document', 200, 0),
            ],
            'attachedMedia' => $id ? $mediaRepo->forContent($id) : [],
            'relatedItems' => $id ? $contentRepo->relatedAll($id) : [],
            'relationOptions' => $contentRepo->adminList(null, null, '', 200, 0),
            'gallery' => ($id && ($item['content_type'] ?? '') === 'report') ? (new ReportRepository())->images($id) : [],
        ];
    };

    $validateContentPost = static function (ContentRepository $repo, ?int $ignoreId = null) use ($contentMediaIdsFromPost): array {
        $errors = [];
        $type = (string) ($_POST['content_type'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = slugify((string) ($_POST['slug'] ?? ''));
        if ($slug === '') {
            $slug = slugify($title);
        }
        $status = (string) ($_POST['status'] ?? 'draft');
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $publishedRaw = trim((string) ($_POST['published_at'] ?? ''));

        if (!in_array($type, ['news', 'article', 'report', 'event'], true)) {
            $errors[] = 'نوع محتوا برای این فرم نامعتبر است.';
        }
        if ($title === '' || mb_strlen($title, 'UTF-8') > 250) {
            $errors[] = 'عنوان الزامی است و حداکثر ۲۵۰ نویسه است.';
        }
        if ($slug === '' || mb_strlen($slug, 'UTF-8') > 190) {
            $errors[] = 'نامک (Slug) نامعتبر است.';
        } elseif (in_array($type, ContentRepository::TYPES, true) && $repo->slugExists($type, $slug, $ignoreId)) {
            $errors[] = 'این Slug قبلاً برای همین نوع محتوا استفاده شده است.';
        }
        if (!in_array($status, ContentRepository::STATUSES, true)) {
            $errors[] = 'وضعیت نامعتبر است.';
        }
        if (mb_strlen($summary, 'UTF-8') > 500) {
            $errors[] = 'خلاصه حداکثر ۵۰۰ نویسه است.';
        }

        $topicId = null;
        $topicRaw = trim((string) ($_POST['topic_id'] ?? ''));
        if ($topicRaw !== '') {
            $topicId = is_numeric($topicRaw) ? (int) $topicRaw : 0;
            if ($topicId <= 0 || !(new TopicRepository())->find($topicId)) {
                $errors[] = 'موضوع انتخابی معتبر نیست.';
                $topicId = null;
            }
        }

        $coverId = null;
        $coverRaw = trim((string) ($_POST['cover_media_id'] ?? ''));
        if ($coverRaw !== '') {
            $coverId = is_numeric($coverRaw) ? (int) $coverRaw : 0;
            $cover = $coverId > 0 ? (new MediaRepository())->find($coverId) : null;
            if (!$cover || (string) $cover['media_type'] !== 'image') {
                $errors[] = 'تصویر شاخص معتبر نیست.';
                $coverId = null;
            }
        }

        $publishedAt = null;
        if ($publishedRaw !== '') {
            $candidate = str_replace('T', ' ', $publishedRaw);
            if (strlen($candidate) === 16) { $candidate .= ':00'; }
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $candidate);
            $publishedAt = $dt ? $dt->format('Y-m-d H:i:s') : null;
            if ($publishedAt === null) { $errors[] = 'تاریخ انتشار معتبر نیست.'; }
        }

        $extra = [];
        if ($type === 'event') {
            $startsAtRaw = trim((string) ($_POST['starts_at'] ?? ''));
            $startsAt = null;
            if ($startsAtRaw !== '') {
                $candidate = str_replace('T', ' ', $startsAtRaw);
                if (strlen($candidate) === 16) { $candidate .= ':00'; }
                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $candidate);
                $startsAt = $dt ? $dt->format('Y-m-d H:i:s') : null;
            }
            if ($startsAt === null) {
                $errors[] = 'زمان شروع رویداد الزامی و باید معتبر باشد.';
            }
            $endsAt = null;
            $endsAtRaw = trim((string) ($_POST['ends_at'] ?? ''));
            if ($endsAtRaw !== '') {
                $candidate = str_replace('T', ' ', $endsAtRaw);
                if (strlen($candidate) === 16) { $candidate .= ':00'; }
                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $candidate);
                $endsAt = $dt ? $dt->format('Y-m-d H:i:s') : null;
                if ($endsAt === null) { $errors[] = 'زمان پایان رویداد معتبر نیست.'; }
            }
            if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
                $errors[] = 'زمان پایان نمی‌تواند قبل از شروع باشد.';
            }
            $extra = ['starts_at' => $startsAt ?? '', 'ends_at' => $endsAt, 'location' => trim((string) ($_POST['event_location'] ?? ''))];
        } elseif ($type === 'report') {
            $eventDate = trim((string) ($_POST['event_date'] ?? ''));
            if ($eventDate !== '' && DateTime::createFromFormat('Y-m-d', $eventDate) === false) {
                $errors[] = 'تاریخ گزارش معتبر نیست.';
                $eventDate = '';
            }
            $extra = ['event_date' => $eventDate !== '' ? $eventDate : null, 'location' => trim((string) ($_POST['report_location'] ?? ''))];
        }

        $attachments = [];
        $mediaRepo = new MediaRepository();
        foreach (['video', 'audio', 'document'] as $role) {
            $ids = $contentMediaIdsFromPost('media_' . $role);
            foreach ($ids as $mediaId) {
                $row = $mediaRepo->find($mediaId);
                if (!$row || (string) $row['media_type'] !== $role) {
                    $errors[] = 'رسانه انتخابی برای ' . $role . ' معتبر نیست.';
                    $ids = [];
                    break;
                }
            }
            $attachments[$role] = $ids;
        }

        $relations = $contentMediaIdsFromPost('related_content_id');
        $relations = array_values(array_filter($relations, static fn (int $id): bool => $ignoreId === null || $id !== $ignoreId));

        $gallery = [];
        if ($type === 'report') {
            foreach ($contentMediaIdsFromPost('gallery_media_id') as $i => $mediaId) {
                $row = $mediaRepo->find($mediaId);
                if (!$row || (string) $row['media_type'] !== 'image') {
                    $errors[] = 'تصویر گالری معتبر نیست.';
                    $gallery = [];
                    break;
                }
                $gallery[] = ['media_id' => $mediaId, 'caption' => null, 'sort_order' => $i + 1];
            }
        }

        return [$errors, [
            'content_type' => $type,
            'slug' => $slug,
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'topic_id' => $topicId,
            'cover_media_id' => $coverId,
            'status' => $status,
            'published_at' => $publishedAt,
        ], $extra, $attachments, $relations, $gallery];
    };

    $syncContentLinks = static function (int $contentId, array $attachments, array $relations, array $gallery, string $type): void {
        $mediaRepo = new MediaRepository();
        foreach ($mediaRepo->forContent($contentId) as $row) {
            if (in_array((string) ($row['role'] ?? ''), ['video', 'audio', 'document'], true)) {
                $mediaRepo->detachFromContent($contentId, (int) $row['id']);
            }
        }
        foreach ($attachments as $role => $ids) {
            foreach ($ids as $i => $mediaId) {
                $mediaRepo->attachToContent($contentId, (int) $mediaId, (string) $role, $i);
            }
        }

        db_delete('content_relations', ['content_id' => $contentId]);
        $contentRepo = new ContentRepository();
        foreach ($relations as $i => $relatedId) {
            try { $contentRepo->relate($contentId, (int) $relatedId, $i); } catch (Throwable $e) { /* ignored: validation already filtered */ }
        }

        if ($type === 'report') {
            (new ReportRepository())->syncImages($contentId, $gallery);
        }
    };

    $router->get('/admin/content', static function () use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 20;
        $type = in_array($_GET['type'] ?? '', ContentRepository::TYPES, true) ? (string) $_GET['type'] : null;
        $status = in_array($_GET['status'] ?? '', ContentRepository::STATUSES, true) ? (string) $_GET['status'] : null;
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim((string) $_GET['q']), 0, 120, 'UTF-8') : '';
        $repo = new ContentRepository();
        $total = $repo->adminCount($type, $status, $q);
        admin_view('content_registry', [
            'title' => 'مخزن محتوا',
            'rows' => $repo->adminList($type, $status, $q, $limit, ($page - 1) * $limit),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $limit)),
            'filters' => ['type' => $type ?? '', 'status' => $status ?? '', 'q' => $q],
        ]);
    });

    $router->get('/admin/content/new', static function () use ($adminOnly, $contentFormData): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        $type = in_array($_GET['type'] ?? '', ['news', 'article', 'report', 'event'], true) ? (string) $_GET['type'] : 'news';
        admin_view('content_form', $contentFormData(['content_type' => $type, 'status' => 'draft']) + [
            'title' => 'محتوای جدید',
            'action' => url('/admin/content/new'),
            'item' => ['content_type' => $type, 'status' => 'draft'],
        ]);
    });

    $router->add('POST', '/admin/content/new', static function () use ($adminOnly, $contentFormData, $validateContentPost, $syncContentLinks): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $repo = new ContentRepository();
        [$errors, $data, $extra, $attachments, $relations, $gallery] = $validateContentPost($repo, null);
        if ($errors !== []) {
            admin_view('content_form', $contentFormData($data) + ['title' => 'محتوای جدید', 'action' => url('/admin/content/new'), 'item' => array_merge($data, $_POST), 'errors' => $errors, 'postedMedia' => $attachments, 'postedRelations' => $relations, 'postedGallery' => $gallery]);
            return;
        }
        $id = db_transaction(static function () use ($repo, $data, $extra, $attachments, $relations, $gallery, $syncContentLinks): int {
            $id = $repo->create($data);
            if ($data['content_type'] === 'event') { (new EventRepository())->save($id, $extra); }
            if ($data['content_type'] === 'report') { (new ReportRepository())->save($id, $extra); }
            $syncContentLinks($id, $attachments, $relations, $gallery, (string) $data['content_type']);
            return $id;
        });
        redirect('/admin/content/edit/' . $id, 303);
    });

    $router->get('/admin/content/edit/{id}', static function (array $params) use ($adminOnly, $contentFormData): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        $repo = new ContentRepository();
        $item = $repo->find((int) $params['id']);
        if (!$item) { admin_not_found(); return; }
        if ($item['content_type'] === 'event') { $item = (new EventRepository())->find((int) $item['id']) ?? $item; }
        if ($item['content_type'] === 'report') { $item = (new ReportRepository())->find((int) $item['id']) ?? $item; }
        admin_view('content_form', $contentFormData($item) + ['title' => 'ویرایش محتوا', 'action' => url('/admin/content/edit/' . (int) $item['id']), 'item' => $item]);
    });

    $router->add('POST', '/admin/content/edit/{id}', static function (array $params) use ($adminOnly, $contentFormData, $validateContentPost, $syncContentLinks): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $id = (int) $params['id'];
        $repo = new ContentRepository();
        $old = $repo->find($id);
        if (!$old) { admin_not_found(); return; }
        $_POST['content_type'] = (string) $old['content_type'];
        [$errors, $data, $extra, $attachments, $relations, $gallery] = $validateContentPost($repo, $id);
        if ($errors !== []) {
            admin_view('content_form', $contentFormData($old) + ['title' => 'ویرایش محتوا', 'action' => url('/admin/content/edit/' . $id), 'item' => array_merge($old, $_POST, ['id' => $id]), 'errors' => $errors, 'postedMedia' => $attachments, 'postedRelations' => $relations, 'postedGallery' => $gallery]);
            return;
        }
        db_transaction(static function () use ($repo, $id, $data, $extra, $attachments, $relations, $gallery, $syncContentLinks): void {
            $repo->update($id, $data);
            if ($data['content_type'] === 'event') { (new EventRepository())->save($id, $extra); }
            if ($data['content_type'] === 'report') { (new ReportRepository())->save($id, $extra); }
            $syncContentLinks($id, $attachments, $relations, $gallery, (string) $data['content_type']);
        });
        redirect('/admin/content', 303);
    });

    $router->get('/admin/content/{id}', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        redirect('/admin/content/edit/' . (int) $params['id']);
    });

    $router->add('POST', '/admin/content/{id}/publish', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $repo = new ContentRepository();
        $id = (int) ($params['id'] ?? 0);
        $item = $id > 0 ? $repo->find($id) : null;
        if (!$item) { admin_not_found('محتوای مورد نظر وجود ندارد.'); return; }
        if (trim((string) $item['title']) === '' || trim((string) $item['body']) === '') { admin_validation_error('محتوای ناقص قابل انتشار نیست؛ عنوان و متن را تکمیل کنید.'); return; }
        $repo->publish($id);
        redirect('/admin/content', 303);
    });

    $router->add('POST', '/admin/content/{id}/unpublish', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $repo = new ContentRepository();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0 || !$repo->find($id)) { admin_not_found('محتوای مورد نظر وجود ندارد.'); return; }
        $repo->unpublish($id);
        redirect('/admin/content', 303);
    });

    $router->add('POST', '/admin/content/{id}/archive', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $repo = new ContentRepository();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0 || !$repo->find($id)) { admin_not_found('محتوای مورد نظر وجود ندارد.'); return; }
        $repo->update($id, ['status' => 'archived']);
        redirect('/admin/content', 303);
    });

    $router->add('POST', '/admin/content/{id}/delete', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $repo = new ContentRepository();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0 || !$repo->find($id)) { admin_not_found('محتوای مورد نظر وجود ندارد.'); return; }
        $repo->delete($id);
        redirect('/admin/content', 303);
    });

    $router->get('/admin/media', static function () use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        $type = in_array($_GET['type'] ?? '', MediaRepository::TYPES, true) ? (string) $_GET['type'] : '';
        $where = $type === '' ? '' : ' WHERE `media_type` = ?';
        $params = $type === '' ? [] : [$type];
        $media = db_all(
            'SELECT m.*, (SELECT COUNT(*) FROM `content_media` cm WHERE cm.`media_id` = m.`id`) + (SELECT COUNT(*) FROM `report_images` ri WHERE ri.`media_id` = m.`id`) AS `usage_count`
             FROM `media` m' . $where . ' ORDER BY m.`created_at` DESC, m.`id` DESC LIMIT 200',
            $params
        );
        admin_view('media', ['title' => 'کتابخانه رسانه', 'media' => $media, 'activeType' => $type]);
    });

    $router->add('POST', '/admin/media/{id}/delete', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) {
            admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
            return;
        }
        $id = (int) ($params['id'] ?? 0);
        $repo = new MediaRepository();
        $media = $id > 0 ? $repo->find($id) : null;
        if (!$media) {
            admin_not_found('رسانه‌ای با این شناسه وجود ندارد.');
            return;
        }

        $diskPath = (string) ($media['disk_path'] ?? '');
        $repo->delete($id);
        // The database row is authoritative. Remove only a real file that is
        // inside uploads; a symlink or stale path is deliberately ignored.
        if (media_file_exists($diskPath)) {
            $absolute = realpath((string) Config::get('app.base_path') . '/' . ltrim($diskPath, '/'));
            if ($absolute !== false) {
                @unlink($absolute);
            }
        }
        redirect('/admin/media', 303);
    });

    $router->add('POST', '/admin/media', static function () use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $file = $_FILES['media'] ?? null;
        $allowed = [
            'image/jpeg' => ['jpg', 'image', 5 * 1024 * 1024],
            'image/png' => ['png', 'image', 5 * 1024 * 1024],
            'image/gif' => ['gif', 'image', 5 * 1024 * 1024],
            'image/webp' => ['webp', 'image', 5 * 1024 * 1024],
            'audio/mpeg' => ['mp3', 'audio', 25 * 1024 * 1024],
            'audio/ogg' => ['ogg', 'audio', 25 * 1024 * 1024],
            'video/mp4' => ['mp4', 'video', 80 * 1024 * 1024],
            'application/pdf' => ['pdf', 'document', 20 * 1024 * 1024],
        ];
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            admin_validation_error('فایل انتخاب‌شده کامل دریافت نشد. دوباره تلاش کنید.');
            return;
        }
        $original = (string) ($file['name'] ?? '');
        $originalExt = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!is_string($mime) || !isset($allowed[$mime])) {
            admin_validation_error('این نوع فایل پشتیبانی نمی‌شود. فقط تصویر، صوت، ویدیو MP4 یا PDF مجاز است.');
            return;
        }
        [$extension, $type, $maxSize] = $allowed[$mime];
        if ($originalExt !== $extension || (int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxSize) {
            admin_validation_error('پسوند فایل یا حجم آن با محدودیت این نوع رسانه سازگار نیست.');
            return;
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $altText = trim((string) ($_POST['alt_text'] ?? ''));
        if (mb_strlen($title, 'UTF-8') > 250 || mb_strlen($altText, 'UTF-8') > 250) {
            admin_validation_error('عنوان و متن جایگزین هرکدام حداکثر ۲۵۰ نویسه هستند.');
            return;
        }
        $name = bin2hex(random_bytes(18)) . '.' . $extension;
        $dir = (string) Config::get('app.base_path') . '/uploads/media';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            admin_status(500, 'ذخیرهٔ فایل ممکن نشد', 'پوشهٔ رسانه قابل نوشتن نیست. دسترسی پوشهٔ uploads را بررسی کنید.');
            return;
        }
        $target = $dir . '/' . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            admin_status(500, 'ذخیرهٔ فایل ممکن نشد', 'فایل روی سرور ذخیره نشد. دوباره تلاش کنید.');
            return;
        }
        try {
            (new MediaRepository())->create(['media_type' => $type, 'disk_path' => 'uploads/media/' . $name, 'original_name' => basename($original), 'mime_type' => $mime, 'file_size' => (int) $file['size'], 'title' => $title, 'alt_text' => $altText]);
        } catch (Throwable $e) {
            @unlink($target);
            throw $e;
        }
        redirect('/admin/media', 303);
    });

    $router->get('/admin/topics', static function () use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        $topics = db_all(
            'SELECT t.*, (SELECT COUNT(*) FROM `contents` c WHERE c.`topic_id` = t.`id`) AS `usage_count`
             FROM `topics` t ORDER BY t.`is_active` DESC, t.`sort_order` ASC, t.`title` ASC'
        );
        admin_view('topics', ['title' => 'موضوعات', 'topics' => $topics]);
    });

    $router->add('POST', '/admin/topics', static function () use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $r = new TopicRepository();
        $slug = slugify((string) ($_POST['slug'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($slug === '') { $slug = slugify($title); }
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($slug === '' || $title === '' || mb_strlen($title, 'UTF-8') > 160 || mb_strlen($slug, 'UTF-8') > 160 || mb_strlen($description, 'UTF-8') > 500 || $r->slugExists($slug)) {
            admin_validation_error('عنوان، نامک یا توضیح موضوع نامعتبر است؛ نامک باید یکتا باشد.');
            return;
        }
        $r->create(['slug' => $slug, 'title' => $title, 'description' => $description, 'sort_order' => max(0, (int) ($_POST['sort_order'] ?? 0)), 'is_active' => !empty($_POST['is_active'])]);
        redirect('/admin/topics', 303);
    });

    $router->add('POST', '/admin/topics/{id}/edit', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $id = (int) $params['id'];
        $r = new TopicRepository();
        $slug = slugify((string) ($_POST['slug'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($slug === '') { $slug = slugify($title); }
        $description = trim((string) ($_POST['description'] ?? ''));
        if (!$r->find($id)) {
            admin_not_found('موضوعی با این شناسه وجود ندارد.');
            return;
        }
        if ($id <= 0 || $slug === '' || $title === '' || mb_strlen($title, 'UTF-8') > 160 || mb_strlen($slug, 'UTF-8') > 160 || mb_strlen($description, 'UTF-8') > 500 || $r->slugExists($slug, $id)) {
            admin_validation_error('عنوان، نامک یا توضیح موضوع نامعتبر است؛ نامک باید یکتا باشد.');
            return;
        }
        $r->update($id, ['slug' => $slug, 'title' => $title, 'description' => $description, 'sort_order' => max(0, (int) ($_POST['sort_order'] ?? 0)), 'is_active' => !empty($_POST['is_active']) ? 1 : 0]);
        redirect('/admin/topics', 303);
    });

    $router->add('POST', '/admin/topics/{id}/delete', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $repo = new TopicRepository();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0 || !$repo->find($id)) {
            admin_not_found('موضوعی با این شناسه وجود ندارد.');
            return;
        }
        // The schema sets content.topic_id to NULL, so existing content is
        // preserved and simply becomes uncategorized.
        $repo->delete($id);
        redirect('/admin/topics', 303);
    });

    $router->add('POST', '/admin/content/{id}/relation', static function (array $params) use ($adminOnly): void { if (!$adminOnly()) { admin_forbidden(); return; } if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; } try { (new ContentRepository())->relate((int) $params['id'], (int) ($_POST['related_content_id'] ?? 0), (int) ($_POST['sort_order'] ?? 0)); } catch (Throwable $e) { admin_validation_error('رابط انتخابی معتبر نیست یا وجود ندارد.'); return; } redirect('/admin/content/edit/' . (int) $params['id'], 303); });

    $router->get('/admin/users', static function () use ($superAdminOnly): void {
        if (!$superAdminOnly()) { admin_forbidden(); return; }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 30;
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim((string) $_GET['q']), 0, 120, 'UTF-8') : '';
        $repo = new UserRepository();
        $total = $repo->adminCount($q);
        admin_view('users', ['title' => 'کاربران', 'users' => $repo->adminList($q, $limit, ($page - 1) * $limit), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit)), 'query' => $q]);
    });

    $renderUserForm = static function (array $item, string $action, array $errors = []): void {
        admin_view('user_form', ['title' => isset($item['id']) ? 'ویرایش کاربر' : 'کاربر جدید', 'item' => $item, 'action' => $action, 'errors' => $errors]);
    };

    $router->get('/admin/users/new', static function () use ($superAdminOnly, $renderUserForm): void { if (!$superAdminOnly()) { admin_forbidden(); return; } $renderUserForm(['role' => 'user', 'is_active' => 1], url('/admin/users/new')); });

    $router->add('POST', '/admin/users/new', static function () use ($superAdminOnly, $renderUserForm): void {
        if (!$superAdminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirmation'] ?? '');
        $errors = [];
        if (strlen($password) < 10 || $password !== $confirm) { $errors[] = 'رمز عبور باید حداقل ۱۰ نویسه باشد و با تکرار یکسان باشد.'; }
        if ($errors === []) {
            try { (new UserRepository())->create(['name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? '', 'role' => $_POST['role'] ?? 'user', 'is_active' => !empty($_POST['is_active']), 'password' => $password]); redirect('/admin/users', 303); }
            catch (Throwable $e) { $errors[] = $e instanceof DuplicateEmailException ? 'این ایمیل قبلاً ثبت شده است.' : 'اطلاعات کاربر معتبر نیست.'; }
        }
        $renderUserForm(array_merge($_POST, ['is_active' => !empty($_POST['is_active']) ? 1 : 0]), url('/admin/users/new'), $errors);
    });

    $router->get('/admin/users/edit/{id}', static function (array $params) use ($superAdminOnly, $renderUserForm): void { if (!$superAdminOnly()) { admin_forbidden(); return; } $item = (new UserRepository())->find((int) $params['id']); if (!$item) { admin_not_found(); return; } $renderUserForm($item, url('/admin/users/edit/' . (int) $item['id'])); });

    $router->add('POST', '/admin/users/edit/{id}', static function (array $params) use ($superAdminOnly, $renderUserForm): void {
        if (!$superAdminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $id = (int) $params['id'];
        $repo = new UserRepository();
        $old = $repo->find($id);
        if (!$old) { admin_not_found(); return; }
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirmation'] ?? '');
        $errors = [];
        if ($password !== '' && (strlen($password) < 10 || $password !== $confirm)) { $errors[] = 'رمز جدید معتبر نیست یا با تکرار یکسان نیست.'; }
        $newRole = (string) ($_POST['role'] ?? 'user');
        $newActive = !empty($_POST['is_active']);
        if ((string) $old['role'] === 'admin' && ((string) $newRole !== 'admin' || !$newActive) && $repo->countActiveAdmins($id) < 1) { $errors[] = 'حداقل یک مدیر فعال باید باقی بماند.'; }
        if ($errors === []) {
            try {
                $repo->updateUser($id, ['name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? '', 'role' => $newRole, 'is_active' => $newActive, 'password' => $password]);
                $current = currentUser();
                if ($current !== null && (int) $current['id'] === $id) {
                    if (!$newActive) {
                        auth_session_logout();
                        redirect('/login', 303);
                    }
                    // Keep the current session aligned with the DB after a
                    // self-edit; otherwise a demoted admin would retain the
                    // old privilege until the next login.
                    SessionManager::authenticate([
                        'id' => $id,
                        'name' => trim((string) ($_POST['name'] ?? '')),
                        'email' => UserRepository::normalizeEmail((string) ($_POST['email'] ?? '')),
                        'role' => $newRole,
                        'is_active' => true,
                    ]);
                    csrf_regenerate();
                }
                redirect($newRole === 'admin' && $newActive ? '/admin/users' : ($newRole === 'editor' && $newActive ? '/admin' : '/'), 303);
            } catch (Throwable $e) {
                $errors[] = $e instanceof DuplicateEmailException ? 'این ایمیل قبلاً ثبت شده است.' : 'اطلاعات کاربر معتبر نیست.';
            }
        }
        $renderUserForm(array_merge($old, $_POST, ['id' => $id, 'is_active' => $newActive ? 1 : 0]), url('/admin/users/edit/' . $id), $errors);
    });

    $router->add('POST', '/admin/users/{id}/delete', static function (array $params) use ($superAdminOnly): void {
        if (!$superAdminOnly()) { admin_forbidden(); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) { admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return; }
        $id = (int) $params['id'];
        $repo = new UserRepository();
        $user = $repo->find($id);
        $current = currentUser();
        if (!$user || ($current && (int) $current['id'] === $id)) { admin_validation_error('حذف این کاربر مجاز نیست؛ کاربر جاری یا شناسهٔ نامعتبر انتخاب شده است.'); return; }
        if ((string) $user['role'] === 'admin' && $repo->countActiveAdmins($id) < 1) { admin_validation_error('حداقل یک مدیر فعال باید باقی بماند.'); return; }
        $repo->delete($id);
        redirect('/admin/users', 303);
    });

    // -----------------------------------------------------------------
    // Phase 6 — knowledge admin (books, lessons, research).
    // One parameterized route family for the three sections; every route
    // is admin-only and every mutation is CSRF protected. Persistence goes
    // through ContentRepository + the section's extension repository; no
    // SQL lives in the route handlers.
    // -----------------------------------------------------------------
    $knowledgeSections = [
        'books' => [
            'type'       => 'book',
            'label'      => 'کتاب‌ها',
            'singular'   => 'کتاب',
            'intro'      => 'مدیریت کتاب‌خانهٔ پورتال',
            'hasAuthor'  => true,
            'hasOrder'   => false,
            'hasLock'    => false,
        ],
        'lessons' => [
            'type'       => 'lesson',
            'label'      => 'درس‌ها',
            'singular'   => 'درس',
            'intro'      => 'مدیریت درس‌ها و دوره‌های آموزشی',
            'hasAuthor'  => false,
            'hasOrder'   => true,
            'hasLock'    => true,
        ],
        'research' => [
            'type'       => 'research',
            'label'      => 'پژوهش‌ها',
            'singular'   => 'پژوهش',
            'intro'      => 'مدیریت پژوهش‌ها و مطالعات',
            'hasAuthor'  => true,
            'hasOrder'   => false,
            'hasLock'    => false,
        ],
    ];

    /** Normalized int-id list from a media multi-select. */
    $mediaIdsFromPost = static function (string $field): array {
        $raw = $_POST[$field] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    };

    /**
     * Validate one attachment list: every id must exist and match the role's
     * media type. Returns [ids, error|null].
     */
    $validatedAttachments = static function (array $ids, string $role, MediaRepository $mediaRepo) use ($mediaIdsFromPost): array {
        $ids = $mediaIdsFromPost('media_' . $role);
        foreach ($ids as $mediaId) {
            $row = $mediaRepo->find($mediaId);
            if (!$row || (string) $row['media_type'] !== $role) {
                return [[], 'رسانهٔ انتخابی برای ' . ($role === 'video' ? 'ویدیو' : ($role === 'audio' ? 'صوت' : 'پیوست')) . ' نامعتبر است.'];
            }
        }

        return [$ids, null];
    };

    /** Validate + normalize the shared content form fields. */
    $validateKnowledgePost = static function (ContentRepository $contents, string $type, ?int $ignoreId): array {
        $errors = [];
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = slugify((string) ($_POST['slug'] ?? ''));
        if ($slug === '') {
            $slug = slugify($title);
        }
        $status = (string) ($_POST['status'] ?? 'draft');
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $publishedAtRaw = trim((string) ($_POST['published_at'] ?? ''));

        if ($title === '' || mb_strlen($title, 'UTF-8') > 250) {
            $errors[] = 'عنوان الزامی است و حداکثر ۲۵۰ نویسه است.';
        }
        if ($slug === '' || mb_strlen($slug, 'UTF-8') > 190) {
            $errors[] = 'نامک (slug) نامعتبر است.';
        } elseif ($contents->slugExists($type, $slug, $ignoreId)) {
            $errors[] = 'این نامک قبلاً استفاده شده است.';
        }
        if (!in_array($status, ContentRepository::STATUSES, true)) {
            $errors[] = 'وضعیت نامعتبر است.';
        }
        if (mb_strlen($summary, 'UTF-8') > 500) {
            $errors[] = 'خلاصه حداکثر ۵۰۰ نویسه است.';
        }

        $topicId = null;
        $topicRaw = trim((string) ($_POST['topic_id'] ?? ''));
        if ($topicRaw !== '') {
            $topicId = is_numeric($topicRaw) ? (int) $topicRaw : 0;
            if ($topicId <= 0 || !(new TopicRepository())->find($topicId)) {
                $errors[] = 'موضوع انتخابی نامعتبر است.';
                $topicId = null;
            }
        }

        $coverId = null;
        $coverRaw = trim((string) ($_POST['cover_media_id'] ?? ''));
        if ($coverRaw !== '') {
            $coverId = is_numeric($coverRaw) ? (int) $coverRaw : 0;
            $cover = $coverId > 0 ? (new MediaRepository())->find($coverId) : null;
            if (!$cover || (string) $cover['media_type'] !== 'image') {
                $errors[] = 'تصویر جلد نامعتبر است.';
                $coverId = null;
            }
        }

        $publishedAt = null;
        if ($publishedAtRaw !== '') {
            $candidate = str_replace('T', ' ', $publishedAtRaw);
            if (strlen($candidate) === 16) {
                $candidate .= ':00';
            }
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $candidate);
            $publishedAt = $dt ? $dt->format('Y-m-d H:i:s') : null;
            if ($publishedAt === null) {
                $errors[] = 'تاریخ انتشار نامعتبر است.';
            }
        }

        return [$errors, [
            'title'        => $title,
            'slug'         => $slug,
            'summary'      => $summary,
            'body'         => $body,
            'status'       => $status,
            'topic_id'     => $topicId,
            'cover_media_id' => $coverId,
            'published_at' => $publishedAt,
        ]];
    };

    foreach ($knowledgeSections as $sectionPath => $section) {
        $type = $section['type'];
        $label = $section['label'];
        $singular = $section['singular'];

        // -------- registry (list) --------
        $router->get('/admin/' . $sectionPath, static function () use ($adminOnly, $sectionPath, $section, $type, $label): void {
            if (!$adminOnly()) { admin_forbidden(); return; }
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $limit = 20;
            $q = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
            $q = mb_substr($q, 0, 120, 'UTF-8');
            $status = in_array($_GET['status'] ?? '', ContentRepository::STATUSES, true) ? (string) $_GET['status'] : null;
            $repo = new ContentRepository();
            $total = $repo->adminCount($type, $status, $q);
            $rows = $repo->adminList($type, $status, $q, $limit, ($page - 1) * $limit);

            // Extension data (author / sort order) for the table, in one
            // batched query per section — no per-row round trips.
            $extra = [];
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $table = ['book' => 'books', 'lesson' => 'lessons', 'research' => 'research'][$type] ?? '';
            if ($ids !== [] && $table !== '') {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                foreach (db_all("SELECT * FROM `$table` WHERE `content_id` IN ($placeholders)", $ids) as $row) {
                    $extra[(int) $row['content_id']] = $row;
                }
            }

            admin_view('knowledge_registry', [
                'title' => $label,
                'sectionPath' => $sectionPath,
                'section' => $section,
                'rows' => $rows,
                'extra' => $extra,
                'total' => $total,
                'page' => $page,
                'pages' => max(1, (int) ceil($total / $limit)),
                'filters' => ['q' => $q, 'status' => $status ?? ''],
            ]);
        });

        // -------- create --------
        $renderForm = static function (string $sectionPath, array $section, array $viewData) use ($adminOnly): void {
            if (!$adminOnly()) { admin_forbidden(); return; }
            $viewData += [
                'sectionPath' => $sectionPath,
                'section' => $section,
                'topics' => (new TopicRepository())->allActive(),
                'mediaByRole' => [
                    'video' => (new MediaRepository())->listByType('video', 200, 0),
                    'audio' => (new MediaRepository())->listByType('audio', 200, 0),
                    'document' => (new MediaRepository())->listByType('document', 200, 0),
                ],
                'coverMedia' => (new MediaRepository())->listByType('image', 200, 0),
            ];
            admin_view('knowledge_form', $viewData);
        };

        $router->get('/admin/' . $sectionPath . '/new', static function () use ($renderForm, $sectionPath, $section, $type, $adminOnly): void {
            if (!$adminOnly()) { admin_forbidden(); return; }
            $item = ['content_type' => $type, 'status' => 'draft'];
            if (!empty($section['hasOrder'])) {
                $item['sort_order'] = (new LessonRepository())->nextSortOrder();
            }
            $renderForm($sectionPath, $section, [
                'title' => $section['singular'] . ' جدید',
                'action' => url('/admin/' . $sectionPath . '/new'),
                'item' => $item,
            ]);
        });

        $router->add('POST', '/admin/' . $sectionPath . '/new', static function () use ($adminOnly, $sectionPath, $section, $type, $renderForm, $validateKnowledgePost, $validatedAttachments, $mediaIdsFromPost): void {
            if (!$adminOnly()) { admin_forbidden(); return; }
            if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) {
                admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return;
            }

            $contents = new ContentRepository();
            [$errors, $data] = $validateKnowledgePost($contents, $type, null);

            $author = null;
            if (!empty($section['hasAuthor'])) {
                $author = trim((string) ($_POST['author'] ?? ''));
                if (mb_strlen($author, 'UTF-8') > 250) {
                    $errors[] = 'نام نویسنده حداکثر ۲۵۰ نویسه است.';
                }
            }
            $sortOrder = 0;
            if (!empty($section['hasOrder'])) {
                $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
            }
            $requiresLogin = !empty($section['hasLock']) && !empty($_POST['requires_login']);

            $mediaRepo = new MediaRepository();
            $attachments = [];
            foreach (['video', 'audio', 'document'] as $role) {
                [$ids, $attachError] = $validatedAttachments([], $role, $mediaRepo);
                if ($attachError !== null) { $errors[] = $attachError; }
                $attachments[$role] = $ids;
            }

            if ($errors !== []) {
                // Validation failed: re-render the form (HTTP 200) with the
                // Persian errors and the submitted values redisplayed.
                $renderForm($sectionPath, $section, [
                    'title' => $section['singular'] . ' جدید',
                    'action' => url('/admin/' . $sectionPath . '/new'),
                    'item' => array_merge($_POST, ['content_type' => $type]),
                    'errors' => $errors,
                    'postedMedia' => $attachments,
                ]);
                return;
            }

            $contentId = db_transaction(static function () use ($contents, $data, $type, $author, $sortOrder, $requiresLogin, $attachments, $mediaRepo): int {
                $id = $contents->create($data + ['content_type' => $type]);
                if ($type === 'book') {
                    (new BookRepository())->save($id, $author);
                } elseif ($type === 'research') {
                    (new ResearchRepository())->save($id, $author);
                } elseif ($type === 'lesson') {
                    (new LessonRepository())->save($id, $sortOrder, $requiresLogin);
                }
                foreach ($attachments as $role => $ids) {
                    foreach ($ids as $i => $mediaId) {
                        $mediaRepo->attachToContent($id, $mediaId, $role, $i);
                    }
                }
                return $id;
            });

            redirect('/admin/' . $sectionPath, 303);
        });

        // -------- edit --------
        $loadItem = static function (BaseRepository $repo, int $id) use ($adminOnly): ?array {
            if (!$adminOnly()) { admin_forbidden(); return null; }
            $item = $repo->find($id);
            if (!$item) {
                admin_not_found();
                return null;
            }
            return $item;
        };

        $router->get('/admin/' . $sectionPath . '/edit/{id}', static function (array $params) use ($renderForm, $sectionPath, $section, $adminOnly, $loadItem): void {
            $repo = match ((string) $section['type']) {
                'book' => new BookRepository(),
                'lesson' => new LessonRepository(),
                default => new ResearchRepository(),
            };
            $item = $loadItem($repo, (int) $params['id']);
            if (!$item) { return; }
            $renderForm($sectionPath, $section, [
                'title' => 'ویرایش ' . $section['singular'],
                'action' => url('/admin/' . $sectionPath . '/edit/' . (int) $item['id']),
                'item' => $item,
                'attachedMedia' => (new MediaRepository())->forContent((int) $item['id']),
            ]);
        });

        $router->add('POST', '/admin/' . $sectionPath . '/edit/{id}', static function (array $params) use ($adminOnly, $sectionPath, $section, $type, $renderForm, $validateKnowledgePost, $validatedAttachments, $loadItem): void {
            if (!$adminOnly()) { admin_forbidden(); return; }
            if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) {
                admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return;
            }

            $repo = match ($type) {
                'book' => new BookRepository(),
                'lesson' => new LessonRepository(),
                default => new ResearchRepository(),
            };
            $id = (int) $params['id'];
            $old = $repo->find($id);
            if (!$old) { admin_not_found(); return; }

            $contents = new ContentRepository();
            [$errors, $data] = $validateKnowledgePost($contents, $type, $id);

            $author = null;
            if (!empty($section['hasAuthor'])) {
                $author = trim((string) ($_POST['author'] ?? ''));
                if (mb_strlen($author, 'UTF-8') > 250) {
                    $errors[] = 'نام نویسنده حداکثر ۲۵۰ نویسه است.';
                }
            }
            $sortOrder = (int) ($old['sort_order'] ?? 0);
            if (!empty($section['hasOrder'])) {
                $sortOrder = max(0, (int) ($_POST['sort_order'] ?? $sortOrder));
            }
            $requiresLogin = !empty($section['hasLock']) && !empty($_POST['requires_login']);

            $mediaRepo = new MediaRepository();
            $attachments = [];
            foreach (['video', 'audio', 'document'] as $role) {
                [$ids, $attachError] = $validatedAttachments([], $role, $mediaRepo);
                if ($attachError !== null) { $errors[] = $attachError; }
                $attachments[$role] = $ids;
            }

            if ($errors !== []) {
                // Validation failed: re-render the edit form (HTTP 200) with
                // the Persian errors; submitted values win over stored ones.
                $renderForm($sectionPath, $section, [
                    'title' => 'ویرایش ' . $section['singular'],
                    'action' => url('/admin/' . $sectionPath . '/edit/' . $id),
                    'item' => array_merge($old, $_POST, ['id' => $id, 'content_type' => $type]),
                    'errors' => $errors,
                    'attachedMedia' => $mediaRepo->forContent($id),
                    'postedMedia' => $attachments,
                ]);
                return;
            }

            db_transaction(static function () use ($contents, $id, $data, $type, $author, $sortOrder, $requiresLogin, $attachments, $mediaRepo): void {
                $contents->update($id, $data);
                if ($type === 'book') {
                    (new BookRepository())->save($id, $author);
                } elseif ($type === 'research') {
                    (new ResearchRepository())->save($id, $author);
                } elseif ($type === 'lesson') {
                    (new LessonRepository())->save($id, $sortOrder, $requiresLogin);
                }
                // Sync role attachments: detach what was removed, attach new ids.
                $current = [];
                foreach ($mediaRepo->forContent($id) as $row) {
                    $current[(string) $row['role']][] = (int) $row['id'];
                }
                foreach (['video', 'audio', 'document'] as $role) {
                    $desired = $attachments[$role];
                    foreach ($current[$role] ?? [] as $existingId) {
                        if (!in_array($existingId, $desired, true)) {
                            $mediaRepo->detachFromContent($id, $existingId);
                        }
                    }
                    foreach ($desired as $i => $mediaId) {
                        $mediaRepo->attachToContent($id, $mediaId, $role, $i);
                    }
                }
            });

            redirect('/admin/' . $sectionPath, 303);
        });

        // Keep the short legacy URL useful without presenting an editable
        // form under a misleading "view" label.
        $router->get('/admin/' . $sectionPath . '/{id}', static function (array $params) use ($adminOnly, $sectionPath): void {
            if (!$adminOnly()) { admin_forbidden(); return; }
            $id = (int) ($params['id'] ?? 0);
            if ($id <= 0) { admin_not_found(); return; }
            redirect('/admin/' . $sectionPath . '/edit/' . $id);
        });

        // -------- publication state --------
        foreach (['publish', 'unpublish', 'archive'] as $stateAction) {
            $router->add('POST', '/admin/' . $sectionPath . '/{id}/' . $stateAction, static function (array $params) use ($adminOnly, $sectionPath, $type, $stateAction): void {
                if (!$adminOnly()) { admin_forbidden(); return; }
                if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) {
                    admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return;
                }
                $repo = new ContentRepository();
                $id = (int) ($params['id'] ?? 0);
                $item = $id > 0 ? $repo->find($id) : null;
                if (!$item || (string) $item['content_type'] !== $type) {
                    admin_not_found('محتوای این بخش پیدا نشد.'); return;
                }
                if ($stateAction === 'publish') {
                    if (trim((string) ($item['title'] ?? '')) === '' || trim((string) ($item['body'] ?? '')) === '') {
                        admin_validation_error('برای انتشار، عنوان و متن این مورد را تکمیل کنید.'); return;
                    }
                    $repo->publish($id);
                } elseif ($stateAction === 'unpublish') {
                    $repo->unpublish($id);
                } else {
                    $repo->update($id, ['status' => 'archived']);
                }
                redirect('/admin/' . $sectionPath, 303);
            });
        }

        // -------- delete --------
        $router->add('POST', '/admin/' . $sectionPath . '/{id}/delete', static function (array $params) use ($adminOnly, $sectionPath, $type): void {
            if (!$adminOnly()) { admin_forbidden(); return; }
            if (!csrf_verify(is_string($_POST[Csrf::FIELD] ?? null) ? $_POST[Csrf::FIELD] : null)) {
                admin_status(403, 'درخواست نامعتبر است', 'نشست امنیتی فرم معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'); return;
            }
            $repo = new ContentRepository();
            $item = $repo->find((int) $params['id']);
            if (!$item || (string) $item['content_type'] !== $type) {
                admin_not_found(); return;
            }
            // The schema cascades: books/lessons/research row, content_media
            // and content_relations are removed with the contents row.
            $repo->delete((int) $params['id']);
            redirect('/admin/' . $sectionPath, 303);
        });
    }

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
            'noindex' => true,
        ]);
    });
}
