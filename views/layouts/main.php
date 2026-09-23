<?php

declare(strict_types=1);

/**
 * Main layout (Phase 1).
 *
 * Variables available:
 *   $content         rendered page HTML (set by view())
 *   $title           page title (optional)
 *   $metaDescription meta description (optional)
 *
 * Rules:
 *  - this file owns <html> ... <body>; views must not repeat it
 *  - every dynamic value is escaped with e()
 *  - no inline <style>/<script>: assets live in /assets (strict CSP in .htaccess)
 */

$appName = (string) Config::get('app.name');
$pageTitle = (isset($title) && (string) $title !== '') ? $title . ' | ' . $appName : $appName;
$description = (string) ($metaDescription ?? '');
$authenticated = function_exists('isAuthenticated') && isAuthenticated();
$logoutCsrfField = $authenticated && function_exists('csrf_field') ? csrf_field() : '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <?php if ($description !== ''): ?>
    <meta name="description" content="<?= e($description) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/main.css')) ?>">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('/')) ?>"><?= e($appName) ?></a>
        <nav class="site-nav" aria-label="ناوبری اصلی">
            <a href="<?= e(url('/')) ?>">خانه</a>
            <?php if ($authenticated): ?>
                <form method="post" action="<?= e(url('/logout')) ?>" class="logout-form">
                    <?= $logoutCsrfField ?>
                    <button type="submit">خروج</button>
                </form>
            <?php else: ?>
                <a href="<?= e(url('/login')) ?>">ورود</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="site-main container" id="main">
<?= $content ?>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <p>© <?= date('Y') ?> — <?= e($appName) ?></p>
    </div>
</footer>

<script src="<?= e(asset('js/main.js')) ?>" defer></script>
</body>
</html>
