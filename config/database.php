<?php

declare(strict_types=1);

/**
 * Database access — one shared PDO connection, created lazily (Phase 1).
 *
 * db() returns the shared instance; the connection is opened only on first
 * use, so pages that don't need the database stay fast.
 *
 * Security baseline:
 *  - PDO::ERRMODE_EXCEPTION           -> errors surface, never silent
 *  - PDO::ATTR_EMULATE_PREPARES=false -> real prepared statements
 *  - utf8mb4                           -> full Unicode (Persian included)
 *  - connection failures are logged without leaking credentials or the DSN
 */

/**
 * Build the MySQL DSN from config (exposed separately for tests).
 */
function db_dsn(): string
{
    return sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        (string) Config::get('db.host', 'localhost'),
        (string) Config::get('db.port', '3306'),
        (string) Config::get('db.name', ''),
        (string) Config::get('db.charset', 'utf8mb4')
    );
}

/**
 * Shared PDO instance (lazy).
 *
 * @throws RuntimeException when the database is unreachable (message is safe
 *                          to show; details go to logs/error.log)
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = new PDO(
            db_dsn(),
            (string) Config::get('db.user', ''),
            (string) Config::get('db.password', ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        log_error('DB connection failed: ' . $e->getMessage());
        if (Config::isDebug()) {
            throw $e;
        }
        throw new RuntimeException('Database temporarily unavailable.');
    }

    return $pdo;
}
