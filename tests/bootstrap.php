<?php

declare(strict_types=1);

/**
 * Test bootstrap — loads the app core exactly like index.php does,
 * without dispatching any request.
 */

define('BASE_PATH', dirname(__DIR__));

require dirname(__DIR__) . '/app/Helpers/functions.php';
require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/app/Router.php';
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/router.php';
