<?php

declare(strict_types=1);

/**
 * SchemaSandbox — Phase 2 test support.
 *
 * Installs database/schema.sql into a throwaway database so the tests can
 * exercise real tables, foreign keys, unique constraints and transactions.
 *
 * Two backends, picked automatically:
 *
 *   1. MySQL/MariaDB (preferred, the production engine)
 *      Used when config/local.php points at a reachable server. The schema
 *      is applied verbatim — no translation, no compromises.
 *
 *   2. SQLite in-memory (fallback, for machines with no MySQL)
 *      The same schema.sql is executed through a small, explicit MySQL ->
 *      SQLite translation (see translate()). SQLite enforces PRIMARY KEY,
 *      UNIQUE, NOT NULL, CHECK and — with foreign_keys=ON — real foreign
 *      keys with ON DELETE/UPDATE actions, which is exactly what the
 *      relationship tests need. Engine/charset clauses and MySQL-only
 *      column flags are dropped because SQLite has no equivalent.
 *
 * Tests that must run on the real engine call requiresMysql() and report
 * themselves as skipped when only the fallback is available, so a fallback
 * run can never silently claim MySQL-specific coverage.
 */
final class SchemaSandbox
{
    private static ?string $mysqlUnavailableReason = null;

    /**
     * True when a real MySQL/MariaDB server is reachable.
     */
    public static function mysqlAvailable(): bool
    {
        return self::mysqlConnection() instanceof PDO;
    }

    /**
     * Why MySQL could not be used (for skip messages).
     */
    public static function mysqlUnavailableReason(): string
    {
        self::mysqlConnection();

        return self::$mysqlUnavailableReason ?? 'MySQL not configured';
    }

    /**
     * The shared app connection, but only when it really works.
     */
    private static function mysqlConnection(): ?PDO
    {
        static $checked = false;
        static $pdo = null;

        if ($checked) {
            return $pdo;
        }
        $checked = true;

        try {
            $candidate = db();
            $candidate->query('SELECT 1');
            $pdo = $candidate;
        } catch (Throwable $e) {
            self::$mysqlUnavailableReason = 'MySQL not reachable (' . self::shortReason($e) . ')';
            $pdo = null;
        }

        return $pdo;
    }

    /**
     * A connection with the Phase 2 schema installed and no rows in it.
     *
     * On MySQL this is the configured database with the schema applied and
     * the Phase 2 tables emptied; on SQLite it is a fresh in-memory database.
     */
    public static function fresh(): PDO
    {
        $mysql = self::mysqlConnection();
        if ($mysql instanceof PDO) {
            schema_install($mysql);
            self::truncateMysql($mysql);

            return $mysql;
        }

        return self::sqlite();
    }

    /**
     * Name of the backend in use ('mysql' or 'sqlite').
     */
    public static function driver(): string
    {
        return self::mysqlAvailable() ? 'mysql' : 'sqlite';
    }

    /**
     * A fresh in-memory SQLite database with the translated schema applied.
     */
    public static function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        foreach (schema_statements('schema.sql') as $statement) {
            $translated = self::translate($statement);
            if ($translated !== null) {
                $pdo->exec($translated);
            }
        }

        return $pdo;
    }

    /**
     * Empty every Phase 2 table (children first) without dropping anything.
     */
    public static function truncateMysql(PDO $pdo): void
    {
        $tables = array_reverse(schema_table_names());
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Empty every Phase 2 table on any backend.
     */
    public static function clear(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            self::truncateMysql($pdo);

            return;
        }

        foreach (array_reverse(schema_table_names()) as $table) {
            $pdo->exec('DELETE FROM `' . $table . '`');
        }
    }

    /**
     * Translate one MySQL DDL statement to its SQLite equivalent.
     *
     * Only the constructs this project's schema.sql actually uses are
     * handled; anything unexpected is left as-is so it fails loudly rather
     * than being silently mistranslated.
     *
     * @return string|null null when the statement has no SQLite meaning
     */
    public static function translate(string $statement): ?string
    {
        // Session/charset statements: no SQLite equivalent, and none needed
        // (SQLite stores TEXT as UTF-8 natively).
        if (preg_match('/^SET\s+/i', $statement) === 1) {
            return null;
        }
        if (preg_match('/^CREATE\s+TABLE/i', $statement) !== 1) {
            return $statement;
        }

        $sql = $statement;

        // Table options (ENGINE=..., CHARSET=..., COLLATE=...) live after the
        // closing parenthesis and have no SQLite equivalent.
        $sql = (string) preg_replace('/\)\s*ENGINE\s*=.*$/is', ')', $sql);

        // AUTO_INCREMENT: SQLite auto-assigns rowids to INTEGER PRIMARY KEY.
        // The column becomes INTEGER PRIMARY KEY and the table-level
        // PRIMARY KEY clause for that column is dropped below.
        $autoColumn = null;
        if (preg_match('/`([A-Za-z0-9_]+)`[^,\n]*AUTO_INCREMENT/i', $sql, $m) === 1) {
            $autoColumn = $m[1];
            $sql = (string) preg_replace(
                '/`' . preg_quote($autoColumn, '/') . '`\s+[A-Z]+(?:\s+UNSIGNED)?\s+NOT\s+NULL\s+AUTO_INCREMENT/i',
                '`' . $autoColumn . '` INTEGER PRIMARY KEY AUTOINCREMENT',
                $sql
            );
            $sql = (string) preg_replace(
                '/,\s*PRIMARY\s+KEY\s*\(\s*`' . preg_quote($autoColumn, '/') . '`\s*\)/i',
                '',
                $sql
            );
        }

        // Types SQLite does not know, mapped to its storage classes.
        $sql = (string) preg_replace('/\bBIGINT\s+UNSIGNED\b/i', 'INTEGER', $sql);
        $sql = (string) preg_replace('/\bINT\s+UNSIGNED\b/i', 'INTEGER', $sql);
        $sql = (string) preg_replace('/\bTINYINT\(1\)/i', 'INTEGER', $sql);
        $sql = (string) preg_replace('/\bMEDIUMTEXT\b/i', 'TEXT', $sql);
        $sql = (string) preg_replace('/\bVARCHAR\((\d+)\)/i', 'TEXT', $sql);
        $sql = (string) preg_replace('/\bDATETIME\b/i', 'TEXT', $sql);
        $sql = (string) preg_replace('/\bDATE\b(?!TIME)/i', 'TEXT', $sql);

        // ENUM('a','b') -> TEXT plus a CHECK that enforces the same values,
        // so the constraint survives the translation instead of vanishing.
        $sql = (string) preg_replace_callback(
            '/`([A-Za-z0-9_]+)`\s+ENUM\s*\(([^)]*)\)/i',
            static function (array $m): string {
                $values = $m[2];

                return '`' . $m[1] . '` TEXT CHECK (`' . $m[1] . '` IN (' . $values . '))';
            },
            $sql
        );

        // DEFAULT CURRENT_TIMESTAMP [ON UPDATE CURRENT_TIMESTAMP]:
        // SQLite supports the default but not the ON UPDATE clause.
        $sql = (string) preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', '', $sql);

        // Plain secondary indexes are declared separately in SQLite; the
        // tests read them back with PRAGMA index_list, so they are turned
        // into CREATE INDEX statements appended after the table.
        $indexes = [];
        $sql = (string) preg_replace_callback(
            '/,\s*(?:KEY|INDEX)\s+`([A-Za-z0-9_]+)`\s*\(([^)]*)\)/i',
            static function (array $m) use (&$indexes): string {
                $indexes[] = ['name' => $m[1], 'columns' => $m[2]];

                return '';
            },
            $sql
        );

        // UNIQUE KEY `name` (cols) -> table-level UNIQUE (cols); the name is
        // kept as a named constraint so it is visible to PRAGMA index_list.
        $sql = (string) preg_replace(
            '/,\s*UNIQUE\s+KEY\s+`([A-Za-z0-9_]+)`\s*\(([^)]*)\)/i',
            ', CONSTRAINT `$1` UNIQUE ($2)',
            $sql
        );

        $tableName = '';
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m) === 1) {
            $tableName = $m[1];
        }

        $out = $sql;
        foreach ($indexes as $index) {
            $out .= '; CREATE INDEX IF NOT EXISTS `' . $index['name'] . '` ON `' . $tableName . '` (' . $index['columns'] . ')';
        }

        return $out;
    }

    /**
     * Short, credential-free reason from a connection failure.
     */
    private static function shortReason(Throwable $e): string
    {
        $message = $e->getMessage();
        $message = (string) preg_replace('/(password|pwd)=\S+/i', '$1=***', $message);

        return substr($message, 0, 80);
    }
}
