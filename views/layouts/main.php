<?php

declare(strict_types=1);

/**
 * Main public layout — Phase 5.
 *
 * Variables available (all optional except $content):
 *   $content         rendered page HTML (set by view())
 *   $title           page title (empty on the home page)
 *   $metaDescription meta description
 *   $ogType          Open Graph type (default 'website')
 *   $ogImage         absolute URL of a share image
 *   $jsonLd          associative array rendered as JSON-LD structured data
 *   $noindex         true to emit a noindex robots meta (search/utility pages)
 *
 * Rules:
 *  - this file owns <html> ... <body>; views must not repeat it
 *  - every dynamic value is escaped with e()
 *  - no inline styles; the only inline <script> is JSON-LD (type application/ld+json,
 *    which the CSP allows as data, not executable script). Behavioural JS lives in /assets.
 */

$appName = (string) Config::get('app.name');
$pageTitle = (isset($title) && (string) $title !== '') ? $title . ' | ' . $appName : $appName;
$description = (string) ($metaDescription ?? Config::get('app.description'));
$authenticated = function_exists('isAuthenticated') && isAuthenticated();
$logoutCsrfField = $authenticated && function_exists('csrf_field') ? csrf_field() : '';
$canonical = url(current_path());
$ogType = (string) ($ogType ?? 'website');
$ogImage = isset($ogImage) && (string) $ogImage !== '' ? (string) $ogImage : '';
$noindex = !empty($noindex);
$activePath = current_path();

$navItems = [
    '/' => 'خانه',
    '/news' => 'خبرها',
    '/articles' => 'مقالات',
    '/books' => 'کتاب‌ها',
    '/lessons' => 'درس‌ها',
    '/research' => 'پژوهش‌ها',
    '/media' => 'رسانه',
    '/reports' => 'گزارش‌ها',
    '/events' => 'رویدادها',
];

/** @var list<array<string,mixed>> $navTopics */
$navTopics = [];
try {
    if (class_exists('TopicRepository')) {
        $navTopics = array_slice((new TopicRepository())->allActive(), 0, 8);
    }
} catch (Throwable $e) {
    $navTopics = [];
}

$is_active = static function (string $path) use ($activePath): bool {
    if ($path === '/') {
        return $activePath === '/';
    }
    return $activePath === $path || str_starts_with($activePath, $path . '/');
};
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($description) ?>">
    <?php if ($noindex): ?>
    <meta name="robots" content="noindex, follow">
    <?php endif; ?>
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta property="og:site_name" content="<?= e($appName) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($description) ?>">
    <meta property="og:type" content="<?= e($ogType) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:locale" content="fa_IR">
    <?php if ($ogImage !== ''): ?>
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <?php else: ?>
    <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/main.css')) ?>">
    <?php if (!empty($jsonLd) && is_array($jsonLd)): ?>
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand-mark" aria-hidden="true">ج</span>
            <span class="brand-name"><?= e($appName) ?></span>
        </a>

        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-nav" aria-label="باز و بسته کردن منو">
            <span class="nav-toggle-bar" aria-hidden="true"></span>
            <span class="nav-toggle-bar" aria-hidden="true"></span>
            <span class="nav-toggle-bar" aria-hidden="true"></span>
        </button>

        <nav class="site-nav" id="primary-nav" aria-label="ناوبری اصلی">
            <ul class="nav-links">
                <?php foreach ($navItems as $path => $label): ?>
                    <li>
                        <a href="<?= e(url($path)) ?>"<?= $is_active($path) ? ' aria-current="page" class="is-active"' : '' ?>><?= e($label) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form class="nav-search" action="<?= e(url('/search')) ?>" method="get" role="search">
                <label class="visually-hidden" for="nav-search-input">جستجو</label>
                <input id="nav-search-input" name="q" type="search" placeholder="جستجو…" maxlength="120" autocomplete="off">
                <button type="submit" aria-label="جستجو">جستجو</button>
            </form>
            <?php if ($authenticated): ?>
                <form method="post" action="<?= e(url('/logout')) ?>" class="logout-form">
                    <?= $logoutCsrfField ?>
                    <button type="submit">خروج</button>
                </form>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="site-main" id="main">
    <div class="container">
<?= $content ?>
    </div>
</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-col footer-about">
            <p class="footer-brand"><?= e($appName) ?></p>
            <p><?= e((string) Config::get('app.description')) ?></p>
        </div>
        <nav class="footer-col" aria-label="بخش‌های اصلی">
            <h2>بخش‌ها</h2>
            <ul>
                <li><a href="<?= e(url('/news')) ?>">خبرها</a></li>
                <li><a href="<?= e(url('/articles')) ?>">مقالات</a></li>
                <li><a href="<?= e(url('/reports')) ?>">گزارش‌ها</a></li>
                <li><a href="<?= e(url('/events')) ?>">رویدادها</a></li>
            </ul>
        </nav>
        <?php if ($navTopics !== []): ?>
        <nav class="footer-col" aria-label="موضوعات">
            <h2>موضوعات</h2>
            <ul>
                <?php foreach ($navTopics as $topic): ?>
                    <li><a href="<?= e(url('/topics/' . rawurlencode((string) $topic['slug']))) ?>"><?= e((string) $topic['title']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php endif; ?>
        <nav class="footer-col" aria-label="پیوندهای ضروری">
            <h2>درباره</h2>
            <ul>
                <li><a href="<?= e(url('/')) ?>">صفحهٔ اصلی</a></li>
                <li><a href="<?= e(url('/search')) ?>">جستجو</a></li>
                <li><a href="<?= e(url('/sitemap.xml')) ?>">نقشهٔ سایت</a></li>
            </ul>
        </nav>
    </div>
    <div class="container footer-bottom">
        <p>© <?= e(fa_digits((string) date('Y'))) ?> — <?= e($appName) ?>. همهٔ حقوق محفوظ است.</p>
    </div>
</footer>

<script src="<?= e(asset('js/main.js')) ?>" defer></script>
</body>
</html>
