<?php

declare(strict_types=1);

/**
 * Phase 1 — Check 6: project structure audit.
 *
 * Verifies:
 *  1. every directory from the blueprint exists
 *  2. every Phase 1 file exists
 *  3. NO later-phase files were created prematurely
 *
 * Usage: php tests/audit/check_structure.php
 */

$root = dirname(__DIR__, 2);
$missing = 0;
$ok = 0;

$expectedDirs = [
    'config',
    'app', 'app/Controllers', 'app/Models', 'app/Services',
    'app/Repositories', 'app/Middleware', 'app/Helpers',
    'pages', 'admin',
    'views', 'views/layouts', 'views/components', 'views/partials',
    'assets', 'assets/css', 'assets/js', 'assets/img', 'assets/fonts',
    'uploads', 'uploads/images', 'uploads/audio', 'uploads/video', 'uploads/documents',
    'database',
    'tests', 'tests/unit', 'tests/integration', 'tests/security', 'tests/browser', 'tests/audit', 'tests/lib',
    'logs', 'docs',
];

$expectedFiles = [
    'index.php', 'router.php', '.htaccess', 'composer.json', '.gitignore',
    'config/config.php', 'config/database.php', 'config/local.example.php',
    'app/Router.php', 'app/Helpers/functions.php',
    'pages/home.php', 'pages/404.php',
    'views/layouts/main.php',
    'assets/css/main.css', 'assets/js/main.js',
    'uploads/.htaccess', 'admin/.htaccess',
    'database/schema.sql', 'database/seed.sql',
    'tests/run.php', 'tests/run_all.sh', 'tests/bootstrap.php',
    'tests/lib/TestCase.php', 'tests/lib/Runner.php',
    'tests/audit/check_syntax.sh', 'tests/audit/check_includes.php',
    'tests/audit/check_links.php', 'tests/audit/check_structure.php',
    'tests/audit/http_test.sh', 'tests/audit/apache_test.sh',
    'docs/ARCHITECTURE.md', 'docs/ROUTES.md', 'docs/DATABASE.md', 'docs/TESTING.md',
];

// Files that belong to later phases and must NOT exist yet
$forbiddenFiles = [
    'pages/about.php', 'pages/article.php', 'pages/news.php',
    'pages/event.php', 'pages/report.php', 'pages/search.php',
    'admin/index.php', 'admin/login.php', 'admin/logout.php',
];

foreach ($expectedDirs as $dir) {
    if (is_dir($root . '/' . $dir)) {
        $ok++;
    } else {
        $missing++;
        echo "[FAIL]    missing directory: {$dir}\n";
    }
}

foreach ($expectedFiles as $file) {
    if (is_file($root . '/' . $file)) {
        $ok++;
    } else {
        $missing++;
        echo "[FAIL]    missing file: {$file}\n";
    }
}

$premature = 0;
foreach ($forbiddenFiles as $file) {
    if (file_exists($root . '/' . $file)) {
        $premature++;
        echo "[FAIL]    later-phase file exists prematurely: {$file}\n";
    }
}

// Later-phase code must not have appeared in the app layers yet
foreach (['Controllers', 'Models', 'Services', 'Repositories', 'Middleware'] as $layer) {
    $phpFiles = glob($root . "/app/{$layer}/*.php") ?: [];
    if ($phpFiles !== []) {
        $premature++;
        echo "[FAIL]    app/{$layer} should be empty in Phase 1: "
            . implode(', ', array_map('basename', $phpFiles)) . "\n";
    }
}

$pages = glob($root . '/pages/*.php') ?: [];
$allowedPages = ['home.php', '404.php'];
foreach ($pages as $page) {
    if (!in_array(basename($page), $allowedPages, true)) {
        $premature++;
        echo "[FAIL]    pages/" . basename($page) . " is not part of Phase 1\n";
    }
}

echo "Structure OK: {$ok} | missing: {$missing} | premature later-phase files: {$premature}\n";

if ($missing > 0 || $premature > 0) {
    echo "Structure check: FAILURES FOUND\n";
    exit(1);
}
echo "Structure check: ALL OK\n";
exit(0);
