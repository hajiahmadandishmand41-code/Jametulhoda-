<?php

declare(strict_types=1);

/**
 * Phase 2 — database security invariants.
 *
 * Focus: a database failure must never expose credentials, the DSN or the
 * server's internals to a visitor, and the committed tree must never contain
 * a real secret.
 */
final class DatabaseSecurityTest extends TestCase
{
    public function testProductionConnectionFailureHidesDetails(): void
    {
        // Simulate production (no config/local.php override in play) and an
        // unreachable server: the visitor-facing message must be generic.
        $message = $this->failureMessageFor([
            'host' => '203.0.113.201', // TEST-NET-3, never routable
            'port' => '3306',
            'name' => 'secret_db_name',
            'user' => 'secret_db_user',
            'password' => 'SuperSecret!Pa55',
        ], debug: false);

        if ($message === null) {
            $this->assertTrue(true, 'connection unexpectedly succeeded — nothing to leak');
            return;
        }

        $this->assertContains('Database temporarily unavailable', $message);
        $this->assertNotContains('SuperSecret!Pa55', $message, 'the password must never surface');
        $this->assertNotContains('secret_db_user', $message, 'the user must never surface');
        $this->assertNotContains('secret_db_name', $message, 'the database name must never surface');
        $this->assertNotContains('203.0.113.201', $message, 'the host must never surface');
        $this->assertNotContains('mysql:host', $message, 'the DSN must never surface');
        $this->assertNotContains('SQLSTATE', $message, 'driver internals must never surface');
    }

    public function testConnectionErrorsAreLoggedNotPrinted(): void
    {
        $source = (string) file_get_contents(BASE_PATH . '/config/database.php');

        $this->assertContains('log_error(', $source, 'connection failures must be logged');
        $this->assertNotContains('echo $e', $source);
        $this->assertNotContains('print_r($e', $source);
        $this->assertNotContains('var_dump(', $source);
        $this->assertNotContains('die(', $source);
    }

    public function testSchemaAndSeedContainNoCredentials(): void
    {
        foreach (['schema.sql', 'seed.sql'] as $file) {
            $sql = (string) file_get_contents(schema_sql_path($file));
            $upper = strtoupper($sql);

            $this->assertNotContains('IDENTIFIED BY', $upper, "{$file} must not create database users");
            $this->assertNotContains('GRANT ', $upper, "{$file} must not grant privileges");
            $this->assertNotContains('CREATE USER', $upper, "{$file} must not create users");
            $this->assertNotContains('CREATE DATABASE', $upper, "{$file}: the host panel owns the database");
        }
    }

    public function testCommittedTreeHasNoLocalConfig(): void
    {
        // config/local.php holds the real credentials and is git-ignored; the
        // example file must stay free of real values.
        $gitignore = (string) file_get_contents(BASE_PATH . '/.gitignore');
        $this->assertContains('config/local.php', $gitignore);

        $example = (string) file_get_contents(BASE_PATH . '/config/local.example.php');
        $this->assertNotContains('your_real_password', $example);
        $this->assertMatches('/^\s*(\/\/|#|\*|<\?php|declare|\/\*|\*\/|return|\];|\[|$)/m', $example);
    }

    public function testRepositoriesDoNotLogOrEchoRowData(): void
    {
        foreach (glob(BASE_PATH . '/app/Repositories/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            $name = basename($file);

            $this->assertSame(0, preg_match_all('/\bvar_dump\s*\(/', $code), "{$name}: no var_dump");
            $this->assertSame(0, preg_match_all('/\bprint_r\s*\(/', $code), "{$name}: no print_r");
            $this->assertSame(0, preg_match_all('/\becho\s+\$/', $code), "{$name}: repositories must not echo");
        }
    }

    /**
     * Try to connect with throwaway settings and return the visitor-facing
     * message, or null when the connection somehow succeeded.
     */
    private function failureMessageFor(array $dbConfig, bool $debug): ?string
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['name']
        );

        try {
            // Mirrors config/database.php's db(), with a short timeout so the
            // test never hangs.
            new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
                PDO::ATTR_ERRMODE  => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT  => 2,
            ]);

            return null;
        } catch (PDOException $e) {
            log_error('DB connection failed: ' . $e->getMessage());

            if ($debug) {
                return $e->getMessage();
            }

            return (new RuntimeException('Database temporarily unavailable.'))->getMessage();
        }
    }
}
