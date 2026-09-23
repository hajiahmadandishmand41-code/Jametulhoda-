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

require dirname(__DIR__) . '/app/Helpers/functions.php';
require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/app/Router.php';
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/app/Services/SessionManager.php';
require dirname(__DIR__) . '/app/Services/Csrf.php';
require dirname(__DIR__) . '/app/Repositories/BaseRepository.php';
require dirname(__DIR__) . '/app/Repositories/UserRepository.php';
require dirname(__DIR__) . '/app/Services/LoginRateLimiter.php';
require dirname(__DIR__) . '/app/Services/AuthService.php';
require dirname(__DIR__) . '/app/Middleware/AuthGuards.php';
require dirname(__DIR__) . '/router.php';

// --- Phase 2: schema helper + data layer ---
require dirname(__DIR__) . '/app/Helpers/schema.php';
require dirname(__DIR__) . '/app/Repositories/ContentRepository.php';
require dirname(__DIR__) . '/app/Repositories/TopicRepository.php';
require dirname(__DIR__) . '/app/Repositories/MediaRepository.php';
require dirname(__DIR__) . '/app/Repositories/ReportRepository.php';
require dirname(__DIR__) . '/app/Repositories/EventRepository.php';
require dirname(__DIR__) . '/tests/lib/SchemaSandbox.php';
