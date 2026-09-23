<?php

declare(strict_types=1);

/**
 * Phase 2 — database/seed.sql.
 *
 * The seed exists to make the relationships testable, so the tests check
 * exactly that: it installs, it stays minimal, every row is recognisable as
 * test data, and the relationships it creates really resolve.
 */
final class SeedTest extends TestCase
{
    private PDO $pdo;

    public function setUp(): void
    {
        $this->pdo = SchemaSandbox::fresh();
        SchemaSandbox::clear($this->pdo);
        db_set_connection($this->pdo);
    }

    public function tearDown(): void
    {
        SchemaSandbox::clear($this->pdo);
        db_set_connection(null);
    }

    public function testSeedIsClearlyMarkedAsTestData(): void
    {
        $sql = (string) file_get_contents(schema_sql_path('seed.sql'));
        $upper = strtoupper($sql);

        $this->assertContains('TEST', $upper, 'seed.sql must announce that it is test data');
        $this->assertContains('DO NOT RUN IN PRODUCTION', $upper);
    }

    public function testSeedInstallsOnTopOfTheSchema(): void
    {
        $this->applySeed();

        $this->assertTrue((int) db_value('SELECT COUNT(*) FROM `topics`', [], 0) > 0);
        $this->assertTrue((int) db_value('SELECT COUNT(*) FROM `contents`', [], 0) > 0);
        $this->assertTrue((int) db_value('SELECT COUNT(*) FROM `media`', [], 0) > 0);
    }

    public function testSeedStaysMinimal(): void
    {
        $this->applySeed();

        $contents = (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0);
        $this->assertTrue(
            $contents > 0 && $contents <= 10,
            "the seed must stay minimal — found {$contents} content rows"
        );
    }

    public function testEverySeededRowIsIdentifiableAsTestData(): void
    {
        $this->applySeed();

        $badContents = (int) db_value(
            "SELECT COUNT(*) FROM `contents` WHERE `slug` NOT LIKE 'test-%'",
            [],
            0
        );
        $this->assertSame(0, $badContents, "every seeded content slug must start with 'test-'");

        $badTopics = (int) db_value(
            "SELECT COUNT(*) FROM `topics` WHERE `slug` NOT LIKE 'test-%'",
            [],
            0
        );
        $this->assertSame(0, $badTopics, "every seeded topic slug must start with 'test-'");

        $badMedia = (int) db_value(
            "SELECT COUNT(*) FROM `media` WHERE `disk_path` NOT LIKE '%/dev/%'",
            [],
            0
        );
        $this->assertSame(0, $badMedia, 'seeded media must live under a dev/ path');
    }

    public function testSeedCoversEveryContentType(): void
    {
        $this->applySeed();

        foreach (['article', 'news', 'event', 'report'] as $type) {
            $count = (int) db_value(
                'SELECT COUNT(*) FROM `contents` WHERE `content_type` = ?',
                [$type],
                0
            );
            $this->assertTrue($count > 0, "the seed must include at least one {$type}");
        }
    }

    public function testSeedIncludesBothADraftAndPublishedRows(): void
    {
        $this->applySeed();

        $this->assertTrue(
            (int) db_value("SELECT COUNT(*) FROM `contents` WHERE `status` = 'draft'", [], 0) > 0,
            'a draft is needed to test draft/published filtering'
        );
        $this->assertTrue(
            (int) db_value("SELECT COUNT(*) FROM `contents` WHERE `status` = 'published'", [], 0) > 0,
            'published rows are needed for the public queries'
        );
    }

    public function testSeededReportHasMultipleImages(): void
    {
        $this->applySeed();

        $reports = new ReportRepository();
        $report = $reports->findBySlug('test-gozaresh-nemune');
        $this->assertTrue($report !== null, 'the seeded report must exist');

        $images = $reports->images((int) $report['id']);
        $this->assertTrue(count($images) >= 2, 'the seeded report must prove a report can hold many images');
        $this->assertSame('تصویر اول گزارش آزمایشی', (string) $images[0]['caption']);
    }

    public function testSeededContentHasAttachedMedia(): void
    {
        $this->applySeed();

        $contents = new ContentRepository();
        $media = new MediaRepository();

        $article = $contents->findBySlug('article', 'test-maqale-nemune');
        $this->assertTrue($article !== null);
        $this->assertSame(
            1,
            count($media->forContent((int) $article['id'], 'audio')),
            'the seeded article must carry contextual audio'
        );

        $news = $contents->findBySlug('news', 'test-khabar-nemune');
        $this->assertTrue($news !== null);
        $this->assertSame(
            1,
            count($media->forContent((int) $news['id'], 'video')),
            'the seeded news item must carry contextual video'
        );
    }

    public function testSeededRelatedContentResolves(): void
    {
        $this->applySeed();

        $contents = new ContentRepository();
        $article = (array) $contents->findBySlug('article', 'test-maqale-nemune');

        $related = $contents->relatedPublished((int) $article['id']);
        $this->assertTrue(count($related) >= 1, 'the seed must create at least one related-content link');
        $this->assertSame('test-khabar-nemune', (string) $related[0]['slug']);
    }

    public function testSeededSubTopicIsLinkedToItsParent(): void
    {
        $this->applySeed();

        $topics = new TopicRepository();
        $parent = (array) $topics->findBySlug('test-eteqadi');
        $children = $topics->children((int) $parent['id']);

        $this->assertSame(1, count($children), 'the seed must exercise topics.parent_id');
        $this->assertSame('test-tafsir', (string) $children[0]['slug']);
    }

    public function testSeedCleanupRemovesEverythingItCreated(): void
    {
        $this->applySeed();
        $this->assertTrue((int) db_value('SELECT COUNT(*) FROM `contents`', [], 0) > 0);

        // The cleanup block documented at the bottom of seed.sql.
        db_execute("DELETE FROM `contents` WHERE `slug` LIKE 'test-%'");
        db_execute("DELETE FROM `media` WHERE `disk_path` LIKE 'uploads/%/dev/%'");
        db_execute("DELETE FROM `topics` WHERE `slug` LIKE 'test-%'");

        foreach (schema_table_names() as $table) {
            $this->assertSame(
                0,
                (int) db_value('SELECT COUNT(*) FROM `' . $table . '`', [], 0),
                "cleanup must leave `{$table}` empty (foreign keys cascade)"
            );
        }
    }

    /**
     * Apply seed.sql to the sandbox.
     *
     * On MySQL the file runs verbatim. On the SQLite fallback the two
     * MySQL-only constructs it uses are translated: INSERT ... ON DUPLICATE
     * KEY UPDATE becomes INSERT OR REPLACE, and VALUES(col) references are
     * resolved by simply re-inserting the row.
     */
    private function applySeed(): void
    {
        $isMysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        foreach (schema_statements('seed.sql') as $statement) {
            if (preg_match('/^SET\s+/i', $statement) === 1) {
                continue;
            }

            if (!$isMysql) {
                $statement = $this->toSqlite($statement);
                if ($statement === null) {
                    continue;
                }
            }

            $this->pdo->exec($statement);
        }
    }

    /**
     * Translate one seed statement to SQLite.
     */
    private function toSqlite(string $statement): ?string
    {
        // Drop the MySQL upsert tail; INSERT OR REPLACE gives the same
        // "re-running the seed is safe" behaviour on SQLite.
        $sql = (string) preg_replace('/\s*ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*$/is', '', $statement);
        $sql = (string) preg_replace('/^INSERT\s+INTO/i', 'INSERT OR REPLACE INTO', $sql);

        return trim($sql) === '' ? null : $sql;
    }
}
