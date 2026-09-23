<?php

declare(strict_types=1);

/**
 * Test bootstrap — loads the app core exactly like index.php does,
 * without dispatching any request.
 *
 * Phase 2 and Phase 3 additionally load the schema helper, repositories,
 * authentication services and SchemaSandbox support so tests can install
 * database/schema.sql into a scratch database.
 */

define('BASE_PATH', dirname(__DIR__));

// Test double for redirect(): the production helper sends the header and
// exits, which would terminate the whole (single-process) test runner.
// functions.php installs its real implementation only when this name is
// still free, so defining the throwing double first gives every route a
// precise, catchable redirect signal under test — production is unchanged.
if (!function_exists('redirect')) {
    final class RedirectException extends RuntimeException
    {
        public function __construct(
            public readonly string $path,
            public readonly int $status,
        ) {
            parent::__construct(sprintf('redirect(%d) %s', $status, $path));
        }
    }

    function redirect(string $path, int $status = 302): never
    {
        throw new RedirectException($path, $status);
    }
}

require dirname(__DIR__) . '/app/Helpers/functions.php';
require dirname(__DIR__) . '/app/Helpers/admin.php';
require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/app/Router.php';
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/app/Services/SessionManager.php';
require dirname(__DIR__) . '/app/Services/Csrf.php';
require dirname(__DIR__) . '/app/Repositories/BaseRepository.php';
require dirname(__DIR__) . '/app/Repositories/UserRepository.php';
require dirname(__DIR__) . '/app/Repositories/ContentRepository.php';
require dirname(__DIR__) . '/app/Repositories/MediaRepository.php';
require dirname(__DIR__) . '/app/Repositories/TopicRepository.php';
require dirname(__DIR__) . '/app/Services/LoginRateLimiter.php';
require dirname(__DIR__) . '/app/Services/AuthService.php';
require dirname(__DIR__) . '/app/Middleware/AuthGuards.php';
require dirname(__DIR__) . '/router.php';

// --- Phase 2: schema helper + data layer ---
// (ContentRepository, TopicRepository and MediaRepository are already
// required above — re-requiring them here would redeclare the classes.)
require dirname(__DIR__) . '/app/Helpers/schema.php';
require dirname(__DIR__) . '/app/Repositories/ReportRepository.php';
require dirname(__DIR__) . '/app/Repositories/EventRepository.php';
require dirname(__DIR__) . '/app/Repositories/BookRepository.php';
require dirname(__DIR__) . '/app/Repositories/LessonRepository.php';
require dirname(__DIR__) . '/app/Repositories/ResearchRepository.php';
require dirname(__DIR__) . '/tests/lib/SchemaSandbox.php';
