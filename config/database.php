<?php

declare(strict_types=1);

/**
 * Database access — one shared PDO connection, created lazily (Phase 1),
 * plus the small query helper layer added in Phase 2.
 *
 * db() returns the shared instance; the connection is opened only on first
 * use, so pages that don't need the database stay fast.
 *
 * Security baseline:
 *  - PDO::ERRMODE_EXCEPTION           -> errors surface, never silent
 *  - PDO::ATTR_EMULATE_PREPARES=false -> real prepared statements
 *  - utf8mb4                           -> full Unicode (Persian included)
 *  - connection failures are logged without leaking credentials or the DSN
 *
 * Phase 2 rule: every statement that touches user input goes through the
 * db_*() helpers below, which only ever bind values as parameters. SQL text
 * is written by the application; values are NEVER interpolated into it.
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
    $pdo = db_connection_holder();

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
        // Do not put a DSN, username, password or driver error detail in the
        // log. The visitor-facing production message is generic as well.
        log_error('DB connection failed.');
        if (Config::isDebug()) {
            throw $e;
        }
        throw new RuntimeException('Database temporarily unavailable.');
    }

    db_connection_holder($pdo);

    return $pdo;
}

/**
 * Storage for the shared connection.
 *
 * Call with a PDO to install it, with false to reset, or with nothing to read
 * the current value. Kept in one place so db() and db_set_connection() cannot
 * drift apart.
 */
function db_connection_holder(PDO|false|null $set = null): ?PDO
{
    static $pdo = null;

    if ($set instanceof PDO) {
        $pdo = $set;
    } elseif ($set === false) {
        $pdo = null;
    }

    return $pdo;
}

/**
 * Replace the shared connection (pass null to restore the normal one).
 *
 * The only supported use is testing: it lets the Phase 2 suite point the
 * repositories at a scratch database instead of the configured one. Nothing
 * in the request path ever calls this.
 */
function db_set_connection(?PDO $pdo): void
{
    db_connection_holder($pdo ?? false);
}

/* ---------------------------------------------------------------------------
 | Phase 2 — query helpers
 |
 | Thin wrappers over PDO so repositories never repeat prepare/execute and
 | never build SQL by string concatenation. Every helper takes the SQL text
 | (written by us) plus an array of bound parameters (possibly user input).
 * ------------------------------------------------------------------------- */

/**
 * Prepare and execute a statement with bound parameters.
 *
 * @param string               $sql    SQL with ? or :name placeholders
 * @param array<int|string,mixed> $params values bound as parameters only
 */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt;
}

/**
 * Fetch every matching row.
 *
 * @param array<int|string,mixed> $params
 * @return list<array<string,mixed>>
 */
function db_all(string $sql, array $params = []): array
{
    /** @var list<array<string,mixed>> $rows */
    $rows = db_run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
}

/**
 * Fetch the first matching row, or null when there is none.
 *
 * @param array<int|string,mixed> $params
 * @return array<string,mixed>|null
 */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * Fetch a single scalar value (first column of the first row).
 *
 * @param array<int|string,mixed> $params
 */
function db_value(string $sql, array $params = [], mixed $default = null): mixed
{
    $value = db_run($sql, $params)->fetchColumn();

    return $value === false ? $default : $value;
}

/**
 * Execute a write statement and return the number of affected rows.
 *
 * @param array<int|string,mixed> $params
 */
function db_execute(string $sql, array $params = []): int
{
    return db_run($sql, $params)->rowCount();
}

/**
 * INSERT a row from a column => value map and return the new id.
 *
 * Column names come from the application (never from request data); values
 * are always bound as parameters.
 *
 * @param array<string,mixed> $data
 */
function db_insert(string $table, array $data): int
{
    if ($data === []) {
        throw new InvalidArgumentException('db_insert: no columns given');
    }

    $columns = array_keys($data);
    db_assert_identifiers($table, $columns);

    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
        implode(', ', array_fill(0, count($columns), '?'))
    );

    db_run($sql, array_values($data));

    return (int) db()->lastInsertId();
}

/**
 * UPDATE rows matching a column => value condition map.
 *
 * @param array<string,mixed> $data  columns to set
 * @param array<string,mixed> $where equality conditions (ANDed together)
 */
function db_update(string $table, array $data, array $where): int
{
    if ($data === [] || $where === []) {
        throw new InvalidArgumentException('db_update: data and where are both required');
    }

    db_assert_identifiers($table, array_merge(array_keys($data), array_keys($where)));

    $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($data)));
    $cond = implode(' AND ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($where)));

    return db_execute(
        sprintf('UPDATE `%s` SET %s WHERE %s', $table, $set, $cond),
        array_merge(array_values($data), array_values($where))
    );
}

/**
 * DELETE rows matching a column => value condition map.
 *
 * @param array<string,mixed> $where equality conditions (ANDed together)
 */
function db_delete(string $table, array $where): int
{
    if ($where === []) {
        throw new InvalidArgumentException('db_delete: refusing to delete without a condition');
    }

    db_assert_identifiers($table, array_keys($where));

    $cond = implode(' AND ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($where)));

    return db_execute(sprintf('DELETE FROM `%s` WHERE %s', $table, $cond), array_values($where));
}

/**
 * Run a callback inside a transaction: commit on success, roll back on any
 * exception (which is then rethrown). Nested calls reuse the outer
 * transaction, so a helper can be wrapped safely.
 *
 * @template T
 * @param callable(PDO):T $callback
 * @return T
 */
function db_transaction(callable $callback): mixed
{
    $pdo = db();

    if ($pdo->inTransaction()) {
        return $callback($pdo);
    }

    $pdo->beginTransaction();
    try {
        $result = $callback($pdo);
        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Guard for table/column names, which cannot be bound as parameters.
 *
 * Identifiers in this codebase are always written by the application, never
 * taken from a request. This is the safety net that makes that explicit: only
 * [A-Za-z0-9_] is accepted, so a stray value can never smuggle SQL into an
 * identifier position.
 *
 * @param list<string> $columns
 */
function db_assert_identifiers(string $table, array $columns): void
{
    foreach (array_merge([$table], $columns) as $identifier) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }
    }
}
