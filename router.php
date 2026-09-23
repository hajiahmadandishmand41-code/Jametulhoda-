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
        $news = $articles = $reports = $events = $topics = [];
        try {
            $repo = new ContentRepository();
            $news = $repo->publicList('news', 7, 0);
            $articles = $repo->publicList('article', 4, 0);
            $reports = $repo->publicList('report', 4, 0);
            $events = $repo->publicList('event', 4, 0);
            $topics = (new TopicRepository())->allActive();
        } catch (Throwable $e) {
            log_error('Home page data load failed: ' . get_class($e));
        }
        view('home', [
            'title' => '',
            'metaDescription' => (string) Config::get('app.description'),
            'featured' => $news[0] ?? null,
            'secondary' => array_slice($news, 1, 4),
            'latest' => array_slice($news, 1, 6),
            'articles' => $articles,
            'reports' => $reports,
            'events' => $events,
            'topics' => $topics,
            'isHome' => true,
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
            'latest' => $contents->adminList(null, null, '', 8, 0),
            'typeLabels' => [
                'article' => 'مقالات',
                'news' => 'خبرها',
                'event' => 'رویدادها',
                'report' => 'گزارش‌ها',
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
            $repo = new ContentRepository();
            $total = $repo->publicCount($type);
            $pages = max(1, (int) ceil($total / $limit));
            $page = min($page, $pages);
            view('public_listing', [
                'title' => $label,
                'metaDescription' => $intro,
                'heading' => $label,
                'intro' => $intro,
                'type' => $type,
                'items' => $repo->publicList($type, $limit, ($page - 1) * $limit),
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
                view('404', ['title' => 'صفحه پیدا نشد', 'metaDescription' => '']);
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
        $items = ($q === '' || $tooShort) ? [] : $repo->publicSearch($q, $limit, ($page - 1) * $limit);
        $total = ($q === '' || $tooShort) ? 0 : $repo->publicCount(null, null, $q);
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);

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
        ]);
    });

    $router->get('/topics/{slug}', static function (array $params): void {
        $topic = (new TopicRepository())->findBySlug(rawurldecode($params['slug']));
        if (!$topic) {
            http_response_code(404);
            view('404', ['title' => 'صفحه پیدا نشد', 'metaDescription' => '']);
            return;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 12;
        $repo = new ContentRepository();
        $total = $repo->publicCount(null, (int) $topic['id']);
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        view('topic', [
            'title' => (string) $topic['title'],
            'metaDescription' => excerpt((string) ($topic['description'] ?? ('آخرین محتوای مرتبط با ' . $topic['title'])), 160),
            'topic' => $topic,
            'items' => $repo->publicByTopic((int) $topic['id'], $limit, ($page - 1) * $limit),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'path' => '/topics/' . rawurlencode((string) $topic['slug']),
        ]);
    });

    $router->get('/sitemap.xml', static function (): void {
        header('Content-Type: application/xml; charset=UTF-8');
        $repo = new ContentRepository();
        $now = date('Y-m-d\TH:i:sP');
        $entries = [['loc' => url('/'), 'lastmod' => $now]];
        foreach (['/news', '/articles', '/reports', '/events'] as $listing) {
            $entries[] = ['loc' => url($listing), 'lastmod' => $now];
        }
        foreach (['news', 'article', 'report', 'event'] as $type) {
            foreach ($repo->publicList($type, 50, 0) as $item) {
                $entries[] = [
                    'loc' => content_url($type, (string) $item['slug']),
                    'lastmod' => !empty($item['updated_at']) ? date('Y-m-d\TH:i:sP', strtotime((string) $item['updated_at'])) : $now,
                ];
            }
        }
        foreach ((new TopicRepository())->allActive() as $topic) {
            $entries[] = ['loc' => url('/topics/' . rawurlencode((string) $topic['slug'])), 'lastmod' => $now];
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
        echo 'Sitemap: ' . url('/sitemap.xml') . "\n";
    });

    // Phase 4 newsroom: all mutations are admin-only and CSRF protected.
    $adminOnly = static function (): bool { return requireRole('admin'); };
    foreach (['news'=>'خبرها','article'=>'مقالات','report'=>'گزارش‌ها','event'=>'رویدادها'] as $contentAlias => $contentAliasLabel) {
        $router->get('/admin/' . ($contentAlias === 'article' ? 'articles' : $contentAlias . 's'), static function () use ($adminOnly, $contentAlias): void {
            if (!$adminOnly()) { http_response_code(403); echo 'دسترسی غیرمجاز'; return; }
            $repo = new ContentRepository(); $rows = $repo->adminList($contentAlias, null, '', 20, 0);
            admin_view('content_registry', ['title'=>'مخزن ' . $contentAlias, 'rows'=>$rows, 'total'=>count($rows), 'page'=>1, 'pages'=>1, 'filters'=>['type'=>$contentAlias,'status'=>'','q'=>'']]);
        });
    }
    $router->get('/admin/content', static function () use ($adminOnly): void {
        if (!$adminOnly()) { http_response_code(403); echo 'دسترسی غیرمجاز'; return; }
        $page = max(1, (int)($_GET['page'] ?? 1)); $limit = 20;
        $type = in_array($_GET['type'] ?? '', ContentRepository::TYPES, true) ? (string)$_GET['type'] : null;
        $status = in_array($_GET['status'] ?? '', ContentRepository::STATUSES, true) ? (string)$_GET['status'] : null;
        $q = is_string($_GET['q'] ?? null) ? trim((string)$_GET['q']) : '';
        $repo = new ContentRepository(); $total = $repo->adminCount($type, $status, $q);
        admin_view('content_registry', ['title'=>'مخزن محتوا','rows'=>$repo->adminList($type,$status,$q,$limit,($page-1)*$limit),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit)),'filters'=>['type'=>$type??'','status'=>$status??'','q'=>$q]]);
    });
    $router->get('/admin/content/new', static function () use ($adminOnly): void {
        if (!$adminOnly()) { http_response_code(403); echo 'دسترسی غیرمجاز'; return; }
        admin_view('content_form',['title'=>'محتوای جدید','action'=>url('/admin/content/new'),'item'=>['content_type'=>'news','status'=>'draft'],'topics'=>(new TopicRepository())->allActive()]);
    });
    $router->add('POST', '/admin/content/new', static function () use ($adminOnly): void {
        if (!$adminOnly()) { http_response_code(403); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD]??null)?$_POST[Csrf::FIELD]:null)) { http_response_code(403); echo 'درخواست نامعتبر است.'; return; }
        $type=(string)($_POST['content_type']??''); $title=trim((string)($_POST['title']??'')); $slug=slugify((string)($_POST['slug']??'')); $status=(string)($_POST['status']??'draft'); $repo=new ContentRepository(); $errors=[];
        if (!in_array($type,ContentRepository::TYPES,true)) $errors[]='نوع محتوا نامعتبر است.'; if ($title===''||mb_strlen($title)>250) $errors[]='عنوان الزامی است.'; if ($slug===''||mb_strlen($slug)>190) $errors[]='Slug نامعتبر است.'; if ($repo->slugExists($type,$slug)) $errors[]='این Slug قبلاً استفاده شده است.'; if (!in_array($status,ContentRepository::STATUSES,true)) $errors[]='وضعیت نامعتبر است.';
        if ($errors) { admin_view('content_form',['title'=>'محتوای جدید','action'=>url('/admin/content/new'),'item'=>$_POST,'topics'=>(new TopicRepository())->allActive(),'errors'=>$errors]); return; }
        $repo->create(['content_type'=>$type,'slug'=>$slug,'title'=>$title,'summary'=>trim((string)($_POST['summary']??'')),'body'=>trim((string)($_POST['body']??'')),'topic_id'=>($_POST['topic_id']??'')!==''?(int)$_POST['topic_id']:null,'status'=>$status,'published_at'=>((string)($_POST['published_at']??''))!==''?str_replace('T',' ',(string)$_POST['published_at']):null]); redirect('/admin/content',303);
    });
    $router->get('/admin/content/edit/{id}', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { http_response_code(403); return; } $item=(new ContentRepository())->find((int)$params['id']); if (!$item) { http_response_code(404); echo 'پیدا نشد'; return; }
        admin_view('content_form',['title'=>'ویرایش محتوا','action'=>url('/admin/content/edit/'.$item['id']),'item'=>$item,'topics'=>(new TopicRepository())->allActive()]);
    });
    $router->add('POST', '/admin/content/edit/{id}', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { http_response_code(403); return; } if (!csrf_verify(is_string($_POST[Csrf::FIELD]??null)?$_POST[Csrf::FIELD]:null)) { http_response_code(403); return; }
        $id=(int)$params['id']; $repo=new ContentRepository(); $old=$repo->find($id); if (!$old) { http_response_code(404); return; } $slug=slugify((string)($_POST['slug']??'')); $errors=[]; if(trim((string)($_POST['title']??''))==='')$errors[]='عنوان الزامی است.'; if($slug===''||$repo->slugExists($old['content_type'],$slug,$id))$errors[]='Slug نامعتبر یا تکراری است.';
        if($errors){admin_view('content_form',['title'=>'ویرایش محتوا','action'=>url('/admin/content/edit/'.$id),'item'=>array_merge($old,$_POST),'topics'=>(new TopicRepository())->allActive(),'errors'=>$errors]);return;}
        $repo->update($id,['slug'=>$slug,'title'=>trim((string)$_POST['title']),'summary'=>trim((string)($_POST['summary']??'')),'body'=>trim((string)($_POST['body']??'')),'topic_id'=>($_POST['topic_id']??'')!==''?(int)$_POST['topic_id']:null,'status'=>(string)$_POST['status'],'published_at'=>((string)($_POST['published_at']??''))!==''?str_replace('T',' ',(string)$_POST['published_at']):null]); redirect('/admin/content',303);
    });
    $router->get('/admin/content/{id}', static function (array $params) use ($adminOnly): void { if(!$adminOnly()){http_response_code(403);return;} $item=(new ContentRepository())->find((int)$params['id']); if(!$item){http_response_code(404);echo'پیدا نشد';return;} admin_view('content_form',['title'=>'مشاهده محتوا','action'=>url('/admin/content/edit/'.$item['id']),'item'=>$item,'topics'=>(new TopicRepository())->allActive()]); });

    $router->add('POST', '/admin/content/{id}/publish', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { http_response_code(403); return; }
        if (!csrf_verify(is_string($_POST[Csrf::FIELD]??null)?$_POST[Csrf::FIELD]:null)) { http_response_code(403); return; }
        $item=(new ContentRepository())->find((int)$params['id']);
        if (!$item || trim((string)$item['title'])==='' || trim((string)$item['body'])==='') { http_response_code(422); echo 'محتوای ناقص قابل انتشار نیست.'; return; }
        (new ContentRepository())->publish((int)$params['id']); redirect('/admin/content',303);
    });
    $router->add('POST', '/admin/content/{id}/unpublish', static function (array $params) use ($adminOnly): void {
        if (!$adminOnly()) { http_response_code(403); return; } if (!csrf_verify(is_string($_POST[Csrf::FIELD]??null)?$_POST[Csrf::FIELD]:null)) { http_response_code(403); return; }
        (new ContentRepository())->unpublish((int)$params['id']); redirect('/admin/content',303);
    });
    $router->get('/admin/media', static function () use ($adminOnly): void { if(!$adminOnly()){http_response_code(403);return;} admin_view('media',['title'=>'کتابخانه رسانه','media'=>(new MediaRepository())->listByType('image',100,0)]); });
    $router->add('POST', '/admin/media', static function () use ($adminOnly): void {
        if(!$adminOnly()){http_response_code(403);return;} if(!csrf_verify(is_string($_POST[Csrf::FIELD]??null)?$_POST[Csrf::FIELD]:null)){http_response_code(403);return;}
        $file=$_FILES['media']??null; $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','audio/mpeg'=>'mp3','video/mp4'=>'mp4','application/pdf'=>'pdf'];
        if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)$file['tmp_name'])||((int)$file['size'])>10*1024*1024){http_response_code(422);echo'فایل نامعتبر است.';return;}
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']); if(!isset($allowed[$mime])){http_response_code(422);echo'نوع فایل مجاز نیست.';return;}
        $type=str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'audio/')?'audio':(str_starts_with($mime,'video/')?'video':'document')); $name=bin2hex(random_bytes(16)).'.'.$allowed[$mime]; $dir=(string)Config::get('app.base_path').'/uploads/media'; if(!is_dir($dir))mkdir($dir,0750,true); if(!move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$name)){http_response_code(500);return;}
        (new MediaRepository())->create(['media_type'=>$type,'disk_path'=>'uploads/media/'.$name,'original_name'=>basename((string)$file['name']),'mime_type'=>$mime,'file_size'=>(int)$file['size'],'title'=>trim((string)($_POST['title']??'')),'alt_text'=>trim((string)($_POST['alt_text']??''))]); redirect('/admin/media',303);
    });
    $router->get('/admin/topics', static function () use ($adminOnly): void { if(!$adminOnly()){http_response_code(403);return;} admin_view('topics',['title'=>'موضوعات','topics'=>(new TopicRepository())->allActive()]); });
    $router->add('POST', '/admin/topics', static function () use ($adminOnly): void { if(!$adminOnly()){http_response_code(403);return;} if(!csrf_verify(is_string($_POST[Csrf::FIELD]??null)?$_POST[Csrf::FIELD]:null)){http_response_code(403);return;} $r=new TopicRepository();$slug=slugify((string)($_POST['slug']??''));$title=trim((string)($_POST['title']??''));if($slug===''||$title===''||$r->slugExists($slug)){http_response_code(422);echo'موضوع نامعتبر یا تکراری است.';return;} $r->create(['slug'=>$slug,'title'=>$title,'description'=>trim((string)($_POST['description']??''))]);redirect('/admin/topics',303); });
    $router->add('POST', '/admin/content/{id}/relation', static function (array $params) use ($adminOnly): void { if(!$adminOnly()){http_response_code(403);return;}if(!csrf_verify(is_string($_POST[Csrf::FIELD]??null)?$_POST[Csrf::FIELD]:null)){http_response_code(403);return;}try{(new ContentRepository())->relate((int)$params['id'],(int)($_POST['related_content_id']??0),(int)($_POST['sort_order']??0));}catch(Throwable $e){http_response_code(422);echo'رابط نامعتبر است.';return;}redirect('/admin/content/edit/'.(int)$params['id'],303); });

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
