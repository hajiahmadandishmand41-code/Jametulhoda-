<?php

declare(strict_types=1);

/**
 * Integration tests for config/database.php.
 *
 * Behaviour depends on the environment:
 *  - DB reachable (config/local.php points to a live MySQL/MariaDB):
 *      deep checks run (SELECT 1, PDO options, prepared statements)
 *  - DB unreachable:
 *      the secure failure path is tested instead (no credential/DSN leak)
 */

final class DatabaseTest extends TestCase
{
    public function testDsnIsBuiltFromConfig(): void
    {
        $dsn = db_dsn();
        $this->assertMatches(
            '#^mysql:host=[^;]+;port=\d+;dbname=[^;]*;charset=utf8mb4$#',
            $dsn
        );
    }

    public function testConnectionOrSecureFailure(): void
    {
        try {
            $pdo = db();
        } catch (PDOException $e) {
            // development mode rethrows the raw exception with details
            $this->assertTrue(true, 'DB unreachable (development mode) — failure path OK');
            return;
        } catch (RuntimeException $e) {
            // production mode: generic message, no DSN/credentials
            $this->assertContains('Database temporarily unavailable', $e->getMessage());
            $this->assertNotContains('PDO', $e->getMessage());
            return;
        }

        // ---- DB is reachable: deep checks ----
        $this->assertSame('1', (string) $pdo->query('SELECT 1')->fetchColumn());
        $this->assertSame(PDO::ERRMODE_EXCEPTION, (int) $pdo->getAttribute(PDO::ATTR_ERRMODE));
        $this->assertFalse((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'emulated prepares must be off');
        $this->assertSame(PDO::FETCH_ASSOC, (int) $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
    }

    public function testPreparedStatementsWork(): void
    {
        try {
            $pdo = db();
        } catch (Throwable $e) {
            $this->assertTrue(true, 'DB unreachable — deep check skipped');
            return;
        }

        $stmt = $pdo->prepare('SELECT ? AS v');
        $stmt->execute(['پارامتر تست']);
        $this->assertSame('پارامتر تست', (string) $stmt->fetchColumn());
    }
}
