<?php

declare(strict_types=1);

/**
 * Base repository — Phase 2.
 *
 * Holds only what every content repository genuinely shares: the table name,
 * the db_*() helpers from config/database.php and a few small guards. No
 * query builder, no ORM, no magic — SQL stays readable in each repository.
 *
 * Hard rules for every subclass:
 *   1. SQL text is written in the repository; values are ALWAYS bound.
 *   2. Table and column names never come from request data.
 *   3. Anything that writes more than one table runs inside a transaction.
 */
abstract class BaseRepository
{
    /** Table this repository owns. */
    protected string $table = '';

    public function table(): string
    {
        return $this->table;
    }

    /**
     * Find one row by primary key.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return db_one(
            sprintf('SELECT * FROM `%s` WHERE `id` = ? LIMIT 1', $this->table),
            [$id]
        );
    }

    /**
     * Count rows, optionally filtered by a column => value map.
     *
     * @param array<string,mixed> $where
     */
    public function count(array $where = []): int
    {
        if ($where === []) {
            return (int) db_value(sprintf('SELECT COUNT(*) FROM `%s`', $this->table), [], 0);
        }

        db_assert_identifiers($this->table, array_keys($where));
        $cond = implode(' AND ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($where)));

        return (int) db_value(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $this->table, $cond),
            array_values($where),
            0
        );
    }

    /**
     * Delete one row by primary key. Returns true when a row was removed.
     */
    public function delete(int $id): bool
    {
        return db_delete($this->table, ['id' => $id]) > 0;
    }

    /**
     * Clamp a LIMIT/OFFSET pair coming from the outside world.
     *
     * LIMIT and OFFSET cannot be bound as parameters on every MySQL setup, so
     * they are cast to int and clamped here before ever reaching SQL.
     *
     * @return array{0:int,1:int} [limit, offset]
     */
    protected function paging(int $limit, int $offset, int $maxLimit = 100): array
    {
        $limit = max(1, min($limit, $maxLimit));
        $offset = max(0, $offset);

        return [$limit, $offset];
    }

    /**
     * Whitelist a sort column: anything unknown falls back to $default.
     *
     * @param list<string> $allowed
     */
    protected function sortColumn(string $column, array $allowed, string $default): string
    {
        return in_array($column, $allowed, true) ? $column : $default;
    }

    /**
     * Whitelist a sort direction.
     */
    protected function sortDirection(string $direction): string
    {
        return strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
    }
}
