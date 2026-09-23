<?php

declare(strict_types=1);

/**
 * Schema helpers — Phase 2.
 *
 * Reads database/schema.sql (and seed.sql) and turns the file into the list
 * of statements it contains. Used by:
 *   * the installer path — applying the schema to a fresh database;
 *   * the test suite — verifying the file parses, installs and that every
 *     table/index/foreign key it promises really exists.
 *
 * Splitting is deliberately simple because this project owns the file: it
 * strips comments, respects quoted strings and backtick identifiers, and
 * splits on top-level semicolons. No stored procedures, no custom
 * DELIMITER blocks — if those are ever needed, this helper must grow with
 * them.
 */

if (!function_exists('schema_sql_path')) {
    /**
     * Absolute path of a file in database/.
     */
    function schema_sql_path(string $file = 'schema.sql'): string
    {
        return (string) Config::get('app.base_path') . '/database/' . basename($file);
    }
}

if (!function_exists('sql_split_statements')) {
    /**
     * Split a SQL script into executable statements.
     *
     * Comments (-- line, # line, C-style block) are removed, and semicolons
     * inside '...', "..." or `...` are not treated as separators.
     *
     * @return list<string> statements without their trailing semicolon
     */
    function sql_split_statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if (!$inSingle && !$inDouble && !$inBacktick) {
                // -- line comment (needs the space/EOL that SQL requires)
                if ($char === '-' && $next === '-') {
                    $lineEnd = strpos($sql, "\n", $i);
                    $i = $lineEnd === false ? $length : $lineEnd;
                    continue;
                }
                // # line comment
                if ($char === '#') {
                    $lineEnd = strpos($sql, "\n", $i);
                    $i = $lineEnd === false ? $length : $lineEnd;
                    continue;
                }
                // /* block comment */
                if ($char === '/' && $next === '*') {
                    $end = strpos($sql, '*/', $i + 2);
                    $i = $end === false ? $length : $end + 1;
                    continue;
                }
                if ($char === ';') {
                    $trimmed = trim($current);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                    continue;
                }
            }

            // Quote state machine (SQL escapes a quote by doubling it)
            if ($char === "'" && !$inDouble && !$inBacktick) {
                if ($inSingle && $next === "'") {
                    $current .= $char . $next;
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && !$inBacktick) {
                if ($inDouble && $next === '"') {
                    $current .= $char . $next;
                    $i++;
                    continue;
                }
                $inDouble = !$inDouble;
            } elseif ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}

if (!function_exists('schema_statements')) {
    /**
     * Statements of a file in database/ (default: schema.sql).
     *
     * @return list<string>
     */
    function schema_statements(string $file = 'schema.sql'): array
    {
        $path = schema_sql_path($file);
        if (!is_file($path)) {
            throw new RuntimeException('SQL file not found: ' . basename($path));
        }

        return sql_split_statements((string) file_get_contents($path));
    }
}

if (!function_exists('schema_table_names')) {
    /**
     * Table names the schema file creates, in creation order.
     *
     * @return list<string>
     */
    function schema_table_names(string $file = 'schema.sql'): array
    {
        $tables = [];
        foreach (schema_statements($file) as $statement) {
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $statement, $m) === 1) {
                $tables[] = $m[1];
            }
        }

        return $tables;
    }
}

if (!function_exists('schema_install')) {
    /**
     * Apply a SQL file to a PDO connection, statement by statement.
     *
     * @param PDO $pdo target connection (the caller owns it, so tests can
     *                 install into a scratch database)
     * @return int number of statements executed
     */
    function schema_install(PDO $pdo, string $file = 'schema.sql'): int
    {
        $count = 0;
        foreach (schema_statements($file) as $statement) {
            $pdo->exec($statement);
            $count++;
        }

        return $count;
    }
}
