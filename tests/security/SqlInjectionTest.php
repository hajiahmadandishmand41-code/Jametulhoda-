<?php

declare(strict_types=1);

/**
 * Phase 2 — SQL injection regression tests.
 *
 * Two layers of defence are checked:
 *   1. behaviour — hostile strings passed as values must be stored and
 *      compared as data, never executed;
 *   2. source — the data layer must not build SQL by interpolating
 *      variables into query text.
 */
final class SqlInjectionTest extends TestCase
{
    /** Payloads that must never be interpreted as SQL. */
    private const PAYLOADS = [
        "'; DROP TABLE contents; --",
        '" OR "1"="1',
        "' OR 1=1 --",
        "\\'; DELETE FROM media; --",
        "1; UPDATE contents SET status='published'",
        "admin'--",
        "' UNION SELECT NULL,NULL,NULL --",
    ];

    private PDO $pdo;

    private ContentRepository $contents;

    public function setUp(): void
    {
        $this->pdo = SchemaSandbox::fresh();
        SchemaSandbox::clear($this->pdo);
        db_set_connection($this->pdo);
        $this->contents = new ContentRepository();
    }

    public function tearDown(): void
    {
        db_set_connection(null);
    }

    public function testHostileSlugLookupsFindNothingAndBreakNothing(): void
    {
        $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'safe-article',
            'title'        => 'مقاله سالم',
            'status'       => 'published',
        ]);

        foreach (self::PAYLOADS as $payload) {
            $row = $this->contents->findBySlug('article', $payload);
            $this->assertNull($row, 'an injection payload must simply not match: ' . $payload);
        }

        // The table and its row must still be there.
        $this->assertSame(1, (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0));
        $this->assertTrue($this->contents->findBySlug('article', 'safe-article') !== null);
    }

    public function testHostileStringsAreStoredVerbatimAsData(): void
    {
        $payload = "'; DROP TABLE contents; --";

        $id = $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'injection-title',
            'title'        => $payload,
            'body'         => $payload,
        ]);

        $row = (array) $this->contents->find($id);
        $this->assertSame($payload, (string) $row['title'], 'the payload must round-trip as plain text');
        $this->assertSame($payload, (string) $row['body']);
        $this->assertSame(1, (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0), 'nothing may have been dropped');
    }

    public function testInjectionInUpdateAndDeleteParametersIsInert(): void
    {
        $id = $this->contents->create([
            'content_type' => 'news',
            'slug'         => 'update-target',
            'title'        => 'خبر',
            'status'       => 'draft',
        ]);
        $other = $this->contents->create([
            'content_type' => 'news',
            'slug'         => 'untouched',
            'title'        => 'دست‌نخورده',
            'status'       => 'draft',
        ]);

        $this->contents->update($id, ['title' => "x'; UPDATE contents SET status='published'; --"]);

        $this->assertSame(
            'draft',
            (string) ((array) $this->contents->find($other))['status'],
            'the second row must stay a draft — the payload was data, not SQL'
        );
        $this->assertSame(2, (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0));
    }

    public function testInvalidIdentifiersAreRejected(): void
    {
        // Identifiers cannot be bound as parameters, so they are whitelisted.
        $hostile = ['contents`; DROP TABLE contents; --', 'contents WHERE 1=1', 'a b', '', '1abc'];

        foreach ($hostile as $identifier) {
            $threw = false;
            try {
                db_assert_identifiers($identifier, ['id']);
            } catch (InvalidArgumentException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, 'must reject the identifier: ' . $identifier);
        }

        $threw = false;
        try {
            db_insert('contents', ['id`, (SELECT 1)) -- ' => 1]);
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a hostile column name must be rejected');
    }

    public function testUnknownEnumValuesAreRejectedBeforeReachingSql(): void
    {
        foreach (["article'; --", 'superuser', ''] as $type) {
            $threw = false;
            try {
                $this->contents->create(['content_type' => $type, 'slug' => 's', 'title' => 't']);
            } catch (InvalidArgumentException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, 'unknown content type must be refused: ' . $type);
        }

        foreach (["published'--", 'deleted'] as $status) {
            $threw = false;
            try {
                $this->contents->create([
                    'content_type' => 'article',
                    'slug'         => 'st-' . md5($status),
                    'title'        => 't',
                    'status'       => $status,
                ]);
            } catch (InvalidArgumentException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, 'unknown status must be refused: ' . $status);
        }
    }

    public function testPagingArgumentsCannotInjectSql(): void
    {
        $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'paging',
            'title'        => 'صفحه‌بندی',
            'status'       => 'published',
        ]);

        // LIMIT/OFFSET are ints clamped by BaseRepository::paging(), so even
        // absurd values stay harmless.
        $this->assertSame(1, count($this->contents->listPublished('article', 5, 0)));
        $this->assertSame(0, count($this->contents->listPublished('article', 5, 50)));
        $this->assertSame(1, count($this->contents->listPublished('article', -1, -1)), 'negatives are clamped');
        $this->assertSame(1, count($this->contents->listPublished('article', PHP_INT_MAX, 0)), 'huge limits are clamped');
    }

    public function testDataLayerNeverInterpolatesValuesIntoSql(): void
    {
        $files = array_merge(
            glob(BASE_PATH . '/app/Repositories/*.php') ?: [],
            [BASE_PATH . '/config/database.php']
        );
        $this->assertTrue(count($files) >= 7, 'the data layer files must be found');

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $name = basename($file);

            // A double-quoted string containing both SQL and a $variable is
            // the classic interpolation bug.
            $this->assertSame(
                0,
                preg_match_all('/"[^"\n]*\b(SELECT|INSERT|UPDATE|DELETE|WHERE|VALUES)\b[^"\n]*\$[A-Za-z_]/i', $code),
                "{$name}: SQL must never interpolate a variable into a double-quoted string"
            );

            // Concatenating a variable directly into a SQL fragment.
            $this->assertSame(
                0,
                preg_match_all('/\b(WHERE|VALUES|SET|LIMIT|OFFSET)\b[^\'";\n]*\'\s*\.\s*\$/i', $code),
                "{$name}: SQL must never concatenate a variable into a clause"
            );
        }
    }

    public function testDataLayerUsesPreparedStatementsOnly(): void
    {
        foreach (glob(BASE_PATH . '/app/Repositories/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            $name = basename($file);

            // Repositories must go through the db_*() helpers, which prepare
            // every statement; direct query()/exec() calls would bypass that.
            $this->assertSame(0, preg_match_all('/->\s*query\s*\(/', $code), "{$name}: no raw PDO::query()");
            $this->assertSame(0, preg_match_all('/->\s*exec\s*\(/', $code), "{$name}: no raw PDO::exec()");
        }
    }

    public function testEmulatedPreparesStayDisabled(): void
    {
        // Real prepared statements are what keeps values out of the SQL text.
        $config = (string) file_get_contents(BASE_PATH . '/config/database.php');
        $this->assertContains('PDO::ATTR_EMULATE_PREPARES   => false', $config);

        if (SchemaSandbox::driver() === 'mysql') {
            $this->assertFalse((bool) db()->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        }
    }
}
