<?php

declare(strict_types=1);

/**
 * Phase 2 + Phase 3 — the schema file itself: does it parse, does it
 * install, and does the installed database contain the promised content and
 * authentication tables, keys and indexes?
 */
final class SchemaTest extends TestCase
{
    /** Every table Phase 2 and Phase 3 must create, in dependency order. */
    private const TABLES = [
        'schema_migrations',
        'topics',
        'media',
        'contents',
        'events',
        'reports',
        'report_images',
        'content_media',
        'content_relations',
        // Phase 6 knowledge extension tables
        'books',
        'research',
        'lessons',
        'users',
        'login_attempts',
    ];

    public function testSchemaFileParsesIntoStatements(): void
    {
        $statements = schema_statements('schema.sql');

        $this->assertTrue(count($statements) >= 9, 'schema.sql must contain at least 9 statements');
        foreach ($statements as $statement) {
            $this->assertFalse(
                str_starts_with(ltrim($statement), '--'),
                'comments must be stripped from parsed statements'
            );
        }
    }

    public function testSchemaCreatesEveryPhaseTwoTable(): void
    {
        $tables = schema_table_names('schema.sql');

        foreach (self::TABLES as $table) {
            $this->assertTrue(in_array($table, $tables, true), "schema.sql must create `{$table}`");
        }
        $this->assertSame(count(self::TABLES), count($tables), 'no unexpected extra tables in Phase 2 + Phase 3 + Phase 6');
    }

    public function testTablesAreCreatedAfterTheTablesTheyReference(): void
    {
        // A foreign key can only be created when its target table exists, so
        // the creation order in the file must be topologically sound.
        $order = array_flip(schema_table_names('schema.sql'));

        $dependencies = [
            'contents'          => ['topics', 'media'],
            'events'            => ['contents'],
            'reports'           => ['contents'],
            'report_images'     => ['reports', 'media'],
            'content_media'     => ['contents', 'media'],
            'content_relations' => ['contents'],
            // Phase 6 knowledge extensions hang off the same spine
            'books'             => ['contents'],
            'research'          => ['contents'],
            'lessons'           => ['contents'],
        ];

        foreach ($dependencies as $table => $needs) {
            foreach ($needs as $need) {
                $this->assertTrue(
                    $order[$need] < $order[$table],
                    "`{$need}` must be created before `{$table}`"
                );
            }
        }
    }

    public function testSchemaUsesInnodbAndUtf8mb4(): void
    {
        // Checked against the parsed statements, not the raw file, so prose
        // in the header comments cannot influence the result.
        $creates = $this->createTableStatements();
        $this->assertSame(count(self::TABLES), count($creates), 'one CREATE TABLE per content/auth table');

        foreach ($creates as $statement) {
            $name = $this->tableNameOf($statement);
            $this->assertMatches('/ENGINE\s*=\s*InnoDB/i', $statement, "`{$name}` must be InnoDB (transactions + real FKs)");
            $this->assertMatches('/CHARSET\s*=\s*utf8mb4/i', $statement, "`{$name}` must be utf8mb4");
            $this->assertMatches('/COLLATE\s*=\s*utf8mb4_unicode_ci/i', $statement, "`{$name}` needs a unicode collation for Persian");
        }
    }

    public function testSchemaIsSafeToRunTwice(): void
    {
        foreach ($this->createTableStatements() as $statement) {
            $this->assertMatches(
                '/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i',
                $statement,
                '`' . $this->tableNameOf($statement) . '` needs CREATE TABLE IF NOT EXISTS'
            );
        }

        // No destructive statement may hide anywhere in the executable SQL.
        foreach (schema_statements('schema.sql') as $statement) {
            $upper = strtoupper($statement);
            $this->assertNotContains('DROP TABLE', $upper, 'schema.sql must never drop tables');
            $this->assertNotContains('TRUNCATE', $upper, 'schema.sql must never truncate tables');
            $this->assertNotContains('DELETE FROM', $upper, 'schema.sql must never delete rows');
        }
    }

    public function testSchemaInstallsAndCreatesAllTables(): void
    {
        $pdo = SchemaSandbox::fresh();
        $found = $this->tableNames($pdo);

        foreach (self::TABLES as $table) {
            $this->assertTrue(in_array($table, $found, true), "table `{$table}` must exist after install");
        }
    }

    public function testInstallIsIdempotent(): void
    {
        // Running the file a second time must not throw and must not change
        // the set of tables.
        $pdo = SchemaSandbox::fresh();
        $before = $this->tableNames($pdo);

        foreach (schema_statements('schema.sql') as $statement) {
            $translated = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? $statement
                : SchemaSandbox::translate($statement);
            if ($translated !== null) {
                $pdo->exec($translated);
            }
        }

        $this->assertSame($before, $this->tableNames($pdo), 'a second run must be a no-op');
    }

    public function testForeignKeysExistOnEveryRelationship(): void
    {
        $pdo = SchemaSandbox::fresh();

        $expected = [
            'contents'          => 2, // topic, cover media
            'events'            => 1, // -> contents
            'reports'           => 1, // -> contents
            'report_images'     => 2, // -> reports, -> media
            'content_media'     => 2, // -> contents, -> media
            'content_relations' => 2, // -> contents (both sides)
            'topics'            => 1, // self reference (parent)
        ];

        foreach ($expected as $table => $min) {
            $count = $this->foreignKeyCount($pdo, $table);
            $this->assertTrue(
                $count >= $min,
                "`{$table}` must define at least {$min} foreign key(s), found {$count}"
            );
        }
    }

    public function testIndexesExistForSlugStatusAndPublishedAt(): void
    {
        $pdo = SchemaSandbox::fresh();

        $contentIndexes = $this->indexedColumnSets($pdo, 'contents');
        $flat = array_map(static fn (array $cols): string => implode(',', $cols), $contentIndexes);

        $this->assertTrue(
            $this->anyStartsWith($flat, 'content_type,slug'),
            'contents needs a (content_type, slug) index for public URL lookups'
        );
        $this->assertTrue(
            $this->anyStartsWith($flat, 'content_type,status') || $this->anyStartsWith($flat, 'status'),
            'contents needs an index covering status for listing queries'
        );
        $this->assertTrue(
            $this->anyContains($flat, 'published_at'),
            'contents needs published_at covered by an index'
        );
        $this->assertTrue(
            $this->anyContains($flat, 'topic_id'),
            'contents needs topic_id covered by an index'
        );

        $this->assertTrue(
            $this->anyContains(
                array_map(
                    static fn (array $c): string => implode(',', $c),
                    $this->indexedColumnSets($pdo, 'topics')
                ),
                'slug'
            ),
            'topics.slug must be indexed'
        );
    }

    public function testUniqueConstraintsAreDeclared(): void
    {
        $pdo = SchemaSandbox::fresh();

        $this->assertTrue(
            $this->hasUniqueOn($pdo, 'contents', ['content_type', 'slug']),
            'contents must be unique on (content_type, slug)'
        );
        $this->assertTrue(
            $this->hasUniqueOn($pdo, 'topics', ['slug']),
            'topics.slug must be unique'
        );
        $this->assertTrue(
            $this->hasUniqueOn($pdo, 'media', ['disk_path']),
            'media.disk_path must be unique'
        );
        $this->assertTrue(
            $this->hasUniqueOn($pdo, 'report_images', ['report_id', 'media_id']),
            'an image may appear in one report gallery only once'
        );
        $this->assertTrue(
            $this->hasUniqueOn($pdo, 'users', ['email']),
            'users.email must be unique'
        );
        $this->assertTrue(
            $this->hasUniqueOn($pdo, 'login_attempts', ['fingerprint']),
            'login attempt fingerprints must be unique'
        );
    }

    /* ---------------------------------------------------------------
     | Helpers
     * --------------------------------------------------------------- */

    /**
     * Only the CREATE TABLE statements of schema.sql.
     *
     * @return list<string>
     */
    private function createTableStatements(): array
    {
        return array_values(array_filter(
            schema_statements('schema.sql'),
            static fn (string $s): bool => preg_match('/^CREATE\s+TABLE/i', $s) === 1
        ));
    }

    private function tableNameOf(string $createStatement): string
    {
        return preg_match(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',
            $createStatement,
            $m
        ) === 1 ? $m[1] : '?';
    }

    /* ---------------------------------------------------------------
     | Backend-independent introspection helpers
     * --------------------------------------------------------------- */

    /** @return list<string> */
    private function tableNames(PDO $pdo): array
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            /** @var list<string> $rows */
            $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        } else {
            /** @var list<string> $rows */
            $rows = $pdo->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            )->fetchAll(PDO::FETCH_COLUMN);
        }

        $rows = array_map('strval', $rows);
        sort($rows);

        return $rows;
    }

    private function foreignKeyCount(PDO $pdo, string $table): int
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return (int) $pdo->query(
                "SELECT COUNT(DISTINCT CONSTRAINT_NAME)
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = " . $pdo->quote($table) . "
                   AND REFERENCED_TABLE_NAME IS NOT NULL"
            )->fetchColumn();
        }

        $rows = $pdo->query('PRAGMA foreign_key_list(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($rows as $row) {
            $ids[(string) $row['id']] = true;
        }

        return count($ids);
    }

    /**
     * Column lists of every index on a table.
     *
     * @return list<list<string>>
     */
    private function indexedColumnSets(PDO $pdo, string $table): array
    {
        $sets = [];

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $rows = $pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            $grouped = [];
            foreach ($rows as $row) {
                $grouped[(string) $row['Key_name']][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
            }
            foreach ($grouped as $columns) {
                ksort($columns);
                $sets[] = array_values($columns);
            }

            return $sets;
        }

        $indexes = $pdo->query('PRAGMA index_list(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            $info = $pdo->query('PRAGMA index_info(`' . (string) $index['name'] . '`)')->fetchAll(PDO::FETCH_ASSOC);
            $columns = [];
            foreach ($info as $col) {
                $columns[(int) $col['seqno']] = (string) $col['name'];
            }
            ksort($columns);
            $sets[] = array_values($columns);
        }

        // SQLite reports the INTEGER PRIMARY KEY implicitly; add it so the
        // two backends describe the same table.
        $sets[] = ['id'];

        return $sets;
    }

    /**
     * @param list<string> $columns
     */
    private function hasUniqueOn(PDO $pdo, string $table, array $columns): bool
    {
        $target = implode(',', $columns);

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $rows = $pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            $grouped = [];
            foreach ($rows as $row) {
                if ((int) $row['Non_unique'] === 0) {
                    $grouped[(string) $row['Key_name']][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
                }
            }
            foreach ($grouped as $cols) {
                ksort($cols);
                if (implode(',', $cols) === $target) {
                    return true;
                }
            }

            return false;
        }

        $indexes = $pdo->query('PRAGMA index_list(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            if ((int) $index['unique'] !== 1) {
                continue;
            }
            $info = $pdo->query('PRAGMA index_info(`' . (string) $index['name'] . '`)')->fetchAll(PDO::FETCH_ASSOC);
            $cols = [];
            foreach ($info as $col) {
                $cols[(int) $col['seqno']] = (string) $col['name'];
            }
            ksort($cols);
            if (implode(',', $cols) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $haystack
     */
    private function anyStartsWith(array $haystack, string $prefix): bool
    {
        foreach ($haystack as $value) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $haystack
     */
    private function anyContains(array $haystack, string $needle): bool
    {
        foreach ($haystack as $value) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
