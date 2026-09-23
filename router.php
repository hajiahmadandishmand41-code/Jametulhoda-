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
        view('home', [
            'title' => 'خانه',
            'metaDescription' => (string) Config::get('app.description'),
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
            'typeLabels' => [
                'article' => 'مقالات',
                'news' => 'خبرها',
                'event' => 'رویدادها',
                'report' => 'گزارش‌ها',
            ],
        ]);
    });

    // Phase 4 newsroom: all mutations are admin-only and CSRF protected.
    $adminOnly = static function (): bool { return requireRole('admin'); };
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
