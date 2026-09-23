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
    ['label' => 'تنظیمات سایت', 'icon' => '⚙', 'href' => '/admin/settings', 'role' => 'admin'],
    ['label' => 'کاربران', 'icon' => '♙', 'href' => '/admin/users', 'role' => 'admin'],
];
$roleLabels = ['admin' => 'مدیر', 'editor' => 'ویرایشگر', 'user' => 'کاربر'];
$userRole = (string) ($user['role'] ?? 'user');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> | <?= e((string) site_setting('name')) ?></title>
    <link rel="icon" href="<?= e(site_image('favicon')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/main.css')) ?>">
</head>
<body class="admin-body no-js">
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>
<div class="admin-shell">
    <aside class="admin-sidebar" id="admin-sidebar" aria-label="ناوبری پنل مدیریت">
        <div class="admin-brand">
            <img class="admin-brand-logo" src="<?= e(site_image('logo')) ?>" alt="" width="38" height="38" aria-hidden="true">
            <span><?= e((string) site_setting('name')) ?></span>
        </div>
        <p class="admin-section-label">مدیریت محتوا</p>
        <nav class="admin-nav">
            <?php foreach ($navigation as $item): ?>
                <?php if (($item['role'] ?? null) === 'admin' && $userRole !== 'admin') { continue; } ?>
                <?php $isActive = $activePath === $item['href'] || ($item['href'] !== '/admin' && str_starts_with($activePath, $item['href'] . '/')); ?>
                <a class="admin-nav-item<?= $isActive ? ' is-active' : '' ?>" href="<?= e(url($item['href'])) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                    <span class="admin-nav-icon" aria-hidden="true"><?= e($item['icon']) ?></span><span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-footer">
            <a href="<?= e(url('/')) ?>">← بازگشت به سایت</a>
        </div>
    </aside>
    <button class="admin-sidebar-overlay" type="button" aria-label="بستن منوی مدیریت" hidden></button>

    <div class="admin-workspace">
        <header class="admin-header">
            <div class="admin-header-title">
                <button class="admin-nav-toggle" type="button" aria-expanded="false" aria-controls="admin-sidebar" aria-label="باز و بسته کردن منوی مدیریت">
                    <span aria-hidden="true">☰</span>
                </button>
                <div>
                    <p class="admin-eyebrow">مرکز مدیریت تحریریه</p>
                    <h1><?= e($pageTitle) ?></h1>
                </div>
            </div>
            <div class="admin-user">
                <div class="admin-avatar" aria-hidden="true"><?= e(mb_substr((string) ($user['name'] ?? 'ا'), 0, 1)) ?></div>
                <div class="admin-user-copy">
                    <strong><?= e((string) ($user['name'] ?? 'کاربر')) ?></strong>
                    <span><?= e($roleLabels[$userRole] ?? 'کاربر') ?></span>
                </div>
                <?php if ($user !== null): ?>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="admin-logout" type="submit">خروج</button>
                </form>
                <?php endif; ?>
            </div>
        </header>
        <main class="admin-main" id="main">
            <nav class="admin-breadcrumbs" aria-label="مسیر صفحه"><a href="<?= e(url('/admin')) ?>">پنل مدیریت</a><span aria-hidden="true">/</span><span aria-current="page"><?= e($pageTitle) ?></span></nav>
            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= e(asset('js/main.js')) ?>" defer></script>
</body>
</html>
