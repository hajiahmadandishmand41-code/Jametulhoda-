<?php

declare(strict_types=1);

/**
 * Check 6: project structure audit (Phase 1 core + Phase 2 data layer
 * + Phase 3 authentication foundation).
 *
 * Verifies:
 *  1. every directory from the blueprint exists
 *  2. every Phase 1 + Phase 2 file exists
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
    'app/Services/SessionManager.php', 'app/Services/Csrf.php',
    'app/Services/LoginRateLimiter.php', 'app/Services/AuthService.php',
    'app/Middleware/AuthGuards.php',
    'app/Repositories/UserRepository.php',
    'pages/home.php', 'pages/404.php', 'pages/login.php',

    // --- Phase 6: knowledge & multimedia platform ---
    'app/Repositories/BookRepository.php',
    'app/Repositories/LessonRepository.php',
    'app/Repositories/ResearchRepository.php',
    'pages/knowledge_listing.php', 'pages/lessons.php', 'pages/media_hub.php',
    'pages/admin/knowledge_registry.php', 'pages/admin/knowledge_form.php',
    'database/migrations/2026-09-23_phase6_knowledge_types.sql',
    'tests/integration/KnowledgeContentTest.php',
    'tests/integration/KnowledgeRoutesTest.php',
    'tests/integration/MediaHubTest.php',
    'tests/security/KnowledgeSecurityTest.php',
    'views/layouts/main.php',

    // --- Phase 5: public content surface ---
    'pages/public_listing.php', 'pages/public_detail.php',
    'pages/topic.php', 'pages/search.php',
    'views/partials/content_card.php', 'views/partials/pagination.php',
    'views/partials/list_body.php',
    'tests/security/PublicSurfaceSecurityTest.php',
    'assets/css/main.css', 'assets/js/main.js',
    'uploads/.htaccess', 'admin/.htaccess',
    'database/schema.sql', 'database/seed.sql',
    'tests/run.php', 'tests/run_all.sh', 'tests/bootstrap.php',
    'tests/lib/TestCase.php', 'tests/lib/Runner.php',
    'tests/audit/check_syntax.sh', 'tests/audit/check_includes.php',
    'tests/audit/check_links.php', 'tests/audit/check_structure.php',
    'tests/audit/http_test.sh', 'tests/audit/apache_test.sh',
    'docs/ARCHITECTURE.md', 'docs/ROUTES.md', 'docs/DATABASE.md', 'docs/TESTING.md',

    // --- Phase 2: database schema + data layer ---
    'app/Helpers/schema.php',
    'app/Repositories/BaseRepository.php',
    'app/Repositories/ContentRepository.php',
    'app/Repositories/TopicRepository.php',
    'app/Repositories/MediaRepository.php',
    'app/Repositories/ReportRepository.php',
    'app/Repositories/EventRepository.php',
    'tests/lib/SchemaSandbox.php',
    'tests/integration/SchemaTest.php',
    'tests/integration/DataLayerTest.php',
    'tests/integration/SeedTest.php',
    'tests/security/SqlInjectionTest.php',
    'tests/security/DatabaseSecurityTest.php',

    // --- Phase 3: authentication and authorization ---
    'tests/unit/AuthTest.php',
    'tests/integration/AuthenticationTest.php',
    'tests/security/AuthenticationSecurityTest.php',
];

// Files that belong to later phases and must NOT exist yet
$forbiddenFiles = [
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

// Later-phase code must not have appeared in the app layers yet.
// Phase 3 populates only the authentication service and middleware layers;
// controllers/models remain empty until later phases.
foreach (['Controllers', 'Models'] as $layer) {
    $phpFiles = glob($root . "/app/{$layer}/*.php") ?: [];
    if ($phpFiles !== []) {
        $premature++;
        echo "[FAIL]    app/{$layer} must stay empty until its own phase: "
            . implode(', ', array_map('basename', $phpFiles)) . "\n";
    }
}

$allowedServices = [
    'AuthService.php', 'Csrf.php', 'LoginRateLimiter.php', 'SessionManager.php',
];
foreach (glob($root . '/app/Services/*.php') ?: [] as $service) {
    if (!in_array(basename($service), $allowedServices, true)) {
        $premature++;
        echo '[FAIL]    app/Services/' . basename($service) . " is not part of Phase 3\n";
    }
}

$allowedMiddleware = ['AuthGuards.php'];
foreach (glob($root . '/app/Middleware/*.php') ?: [] as $middleware) {
    if (!in_array(basename($middleware), $allowedMiddleware, true)) {
        $premature++;
        echo '[FAIL]    app/Middleware/' . basename($middleware) . " is not part of Phase 3\n";
    }
}

// Phase 3 adds only the user repository; admin/content repositories remain
// outside this phase.
$allowedRepositories = [
    'BaseRepository.php', 'ContentRepository.php', 'TopicRepository.php',
    'MediaRepository.php', 'ReportRepository.php', 'EventRepository.php',
    'UserRepository.php',
    // Phase 6 knowledge repositories
    'BookRepository.php', 'LessonRepository.php', 'ResearchRepository.php',
];
foreach (glob($root . '/app/Repositories/*.php') ?: [] as $repository) {
    if (!in_array(basename($repository), $allowedRepositories, true)) {
        $premature++;
        echo '[FAIL]    app/Repositories/' . basename($repository) . " is not part of Phase 2\n";
    }
}

$pages = glob($root . '/pages/*.php') ?: [];
$allowedPages = [
    'home.php', '404.php', 'login.php',
    // Phase 5 public content surface
    'public_listing.php', 'public_detail.php', 'topic.php', 'search.php',
    // Phase 6 knowledge & multimedia surface
    'knowledge_listing.php', 'lessons.php', 'media_hub.php',
];
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
