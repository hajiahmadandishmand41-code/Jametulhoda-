<?php

declare(strict_types=1);

/** @var string $content */
$user = currentUser();
$pageTitle = (string) ($title ?? 'داشبورد');
$activePath = current_path();
$navigation = [
    ['label' => 'داشبورد', 'icon' => '⌂', 'href' => '/admin'],
    ['label' => 'همه محتوا', 'icon' => '▤', 'href' => '/admin/content'],
    ['label' => 'خبرها', 'icon' => '◌', 'href' => '/admin/news'],
    ['label' => 'مقالات', 'icon' => '▥', 'href' => '/admin/articles'],
    ['label' => 'گزارش‌ها', 'icon' => '▦', 'href' => '/admin/reports'],
    ['label' => 'رویدادها', 'icon' => '◷', 'href' => '/admin/events'],
    ['label' => 'کتاب‌ها', 'icon' => '❑', 'href' => '/admin/books'],
    ['label' => 'درس‌ها', 'icon' => '≣', 'href' => '/admin/lessons'],
    ['label' => 'پژوهش‌ها', 'icon' => '◎', 'href' => '/admin/research'],
    ['label' => 'رسانه‌ها', 'icon' => '◉', 'href' => '/admin/media'],
    ['label' => 'موضوعات', 'icon' => '◆', 'href' => '/admin/topics'],
    ['label' => 'کاربران', 'icon' => '♙', 'href' => '/admin/users'],
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e((string) Config::get('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/main.css')) ?>">
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="admin-sidebar" aria-label="ناوبری پنل مدیریت">
        <div class="admin-brand">
            <img class="admin-brand-logo" src="<?= e(asset('img/logo.svg')) ?>" alt="" width="38" height="38" aria-hidden="true">
            <span><?= e((string) Config::get('app.name')) ?></span>
        </div>
        <p class="admin-section-label">اتاق خبر</p>
        <nav class="admin-nav">
            <?php foreach ($navigation as $item): ?>
                <?php $isActive = $activePath === $item['href'] || ($item['href'] !== '/admin' && str_starts_with($activePath, $item['href'] . '/')); ?>
                <a class="admin-nav-item<?= $isActive ? ' is-active' : '' ?>" href="<?= e(url($item['href'])) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                    <span aria-hidden="true"><?= e($item['icon']) ?></span><span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-footer">
            <a href="<?= e(url('/')) ?>">← بازگشت به سایت</a>
        </div>
    </aside>

    <div class="admin-workspace">
        <header class="admin-header">
            <div>
                <p class="admin-eyebrow">مرکز مدیریت تحریریه</p>
                <h1><?= e($pageTitle) ?></h1>
            </div>
            <div class="admin-user">
                <div class="admin-avatar" aria-hidden="true"><?= e(mb_substr((string) ($user['name'] ?? 'ا'), 0, 1)) ?></div>
                <div class="admin-user-copy">
                    <strong><?= e((string) ($user['name'] ?? 'کاربر')) ?></strong>
                    <span><?= e(['admin' => 'مدیر', 'editor' => 'ویرایشگر', 'user' => 'کاربر'][(string) ($user['role'] ?? 'user')] ?? 'کاربر') ?></span>
                </div>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="admin-logout" type="submit">خروج</button>
                </form>
            </div>
        </header>
        <main class="admin-main" id="main">
            <nav class="admin-breadcrumbs" aria-label="مسیر صفحه"><a href="<?= e(url('/admin')) ?>">پنل مدیریت</a><span aria-hidden="true">/</span><span><?= e($pageTitle) ?></span></nav>
            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= e(asset('js/main.js')) ?>" defer></script>
</body>
</html>
