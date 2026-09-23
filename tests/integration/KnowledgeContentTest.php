<?php

declare(strict_types=1);

/**
 * Phase 6 — knowledge data layer (books, lessons, research).
 *
 * Exercises the three extension repositories against a real installed
 * schema: CRUD, the 1:1 extension relationship, publication filtering,
 * curriculum ordering, the access flag and cascade deletes. Persian
 * round-trips are asserted everywhere a string can carry Persian.
 */
final class KnowledgeContentTest extends TestCase
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
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        db_set_connection(null);
    }

    private function books(): BookRepository
    {
        return new BookRepository();
    }

    private function lessons(): LessonRepository
    {
        return new LessonRepository();
    }

    private function research(): ResearchRepository
    {
        return new ResearchRepository();
    }

    private function publishedBookData(string $slug = 'sifr-ul-hoda'): array
    {
        return [
            'slug' => $slug,
            'title' => 'کتاب راهنمای زندگی',
            'summary' => 'خلاصه‌ای کوتاه از کتاب.',
            'body' => 'متن کامل کتاب برای مطالعه.',
            'author' => 'نویسندهٔ نمونه',
            'status' => 'published',
        ];
    }

    /* -----------------------------------------------------------------
     | Books
     * ----------------------------------------------------------------- */

    public function testBookCreateAndPublishedRead(): void
    {
        $id = $this->books()->create($this->publishedBookData());

        $row = $this->books()->findPublishedBySlug('sifr-ul-hoda');
        $this->assertTrue($row !== null);
        $this->assertSame($id, (int) $row['id']);
        $this->assertSame('کتاب راهنمای زندگی', (string) $row['title']);
        $this->assertSame('نویسندهٔ نمونه', (string) $row['author']);
    }

    public function testDraftBookIsInvisibleToPublicReads(): void
    {
        $id = $this->books()->create(['slug' => 'draft-book', 'title' => 'پیش‌نویس کتاب', 'status' => 'draft', 'author' => 'الف']);

        $this->assertTrue($this->books()->findPublishedBySlug('draft-book') === null);
        $this->assertSame(0, $this->books()->publicCount());
        $this->assertSame([], $this->books()->publicList());
        $this->assertTrue($this->books()->find($id) !== null, 'admin read still sees the row');
    }

    public function testBookSlugIsUniquePerTypeButSharesNamespaceWithOthers(): void
    {
        $contents = new ContentRepository();
        $this->books()->create($this->publishedBookData('shared-slug'));

        // same slug, different content type: allowed by the (type, slug) unique key
        $researchId = $this->research()->create(['slug' => 'shared-slug', 'title' => 'پژوهش هم‌نام', 'status' => 'published', 'author' => 'ب']);
        $this->assertTrue($researchId > 0);

        // same type + same slug: rejected by slugExists()
        $this->assertTrue($contents->slugExists('book', 'shared-slug'));
        $this->assertFalse($contents->slugExists('book', 'shared-slug', 1), 'ignoreId lets an edit keep its own slug');
    }

    public function testBookSaveUpdatesAuthor(): void
    {
        $id = $this->books()->create($this->publishedBookData('author-update'));
        $this->books()->save($id, 'نویسندهٔ تازه');

        $row = $this->books()->find($id);
        $this->assertSame('نویسندهٔ تازه', (string) $row['author']);
    }

    public function testBookListingFiltersByTopicAndQuery(): void
    {
        $topicId = (new TopicRepository())->create(['slug' => 'aqayed', 'title' => 'عقاید']);
        $otherTopic = (new TopicRepository())->create(['slug' => 'fegh-h', 'title' => 'فقه']);

        $this->books()->create(['slug' => 'b1', 'title' => 'توحید در قرآن', 'author' => 'عالم اول', 'status' => 'published', 'topic_id' => $topicId]);
        $this->books()->create(['slug' => 'b2', 'title' => 'احکام نماز', 'author' => 'عالم دوم', 'status' => 'published', 'topic_id' => $otherTopic]);

        $this->assertSame(2, $this->books()->publicCount());
        $this->assertSame(1, $this->books()->publicCount(['topic' => $topicId]));
        $this->assertSame(1, $this->books()->publicCount(['q' => 'توحید']));
        $this->assertSame(1, $this->books()->publicCount(['q' => 'عالم دوم']), 'author is searchable too');

        $rows = $this->books()->publicList(['topic' => $topicId]);
        $this->assertSame('توحید در قرآن', (string) $rows[0]['title']);
        $this->assertSame('عقاید', (string) $rows[0]['topic_title']);
    }

    public function testBookDeleteCascades(): void
    {
        $contents = new ContentRepository();
        $id = $this->books()->create($this->publishedBookData('cascade-book'));
        $this->assertTrue($this->books()->find($id) !== null);

        $contents->delete($id);

        $this->assertTrue($contents->find($id) === null);
        $left = db_value('SELECT COUNT(*) FROM `books` WHERE `content_id` = ?', [$id], 0);
        $this->assertSame(0, (int) $left, 'extension row must cascade with the contents row');
    }

    /* -----------------------------------------------------------------
     | Research
     * ----------------------------------------------------------------- */

    public function testResearchCreateAndPublishedRead(): void
    {
        $id = $this->research()->create([
            'slug' => 'barresi-vekam',
            'title' => 'بررسی وکالت در امور حسبی',
            'summary' => 'چکیدهٔ پژوهش.',
            'body' => 'متن کامل و بلند پژوهش برای صفحهٔ مطالعه.',
            'author' => 'پژوهشگر نمونه',
            'status' => 'published',
        ]);

        $row = $this->research()->findPublishedBySlug('barresi-vekam');
        $this->assertTrue($row !== null);
        $this->assertSame('پژوهشگر نمونه', (string) $row['author']);
        $this->assertSame($id, (int) $row['id']);
    }

    public function testResearchRelatedPrefersSameTopic(): void
    {
        $topicId = (new TopicRepository())->create(['slug' => 'hoquq', 'title' => 'حقوق']);
        $a = $this->research()->create(['slug' => 'r1', 'title' => 'پژوهش یک', 'status' => 'published', 'author' => 'الف', 'topic_id' => $topicId]);
        $b = $this->research()->create(['slug' => 'r2', 'title' => 'پژوهش دو', 'status' => 'published', 'author' => 'ب', 'topic_id' => $topicId]);
        $c = $this->research()->create(['slug' => 'r3', 'title' => 'پژوهش سه', 'status' => 'published', 'author' => 'ج']);

        $related = $this->research()->relatedPublished($a, $topicId);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $related);
        $this->assertTrue(in_array($b, $ids, true), 'same-topic research is related');
        $this->assertFalse(in_array($a, $ids, true), 'never related to itself');
        $this->assertFalse(in_array($c, $ids, true), 'another-topic row only appears via the fallback');
    }

    public function testResearchQueryMatchesTitleAuthorAndSummary(): void
    {
        $this->research()->create(['slug' => 'rq1', 'title' => 'عنوان ویژه', 'summary' => 'چکیدهٔ ممتاز', 'author' => 'پژوهگر الف', 'status' => 'published']);
        $this->research()->create(['slug' => 'rq2', 'title' => 'دیگری', 'summary' => '', 'author' => 'ب', 'status' => 'published']);

        $this->assertSame(1, $this->research()->publicCount(['q' => 'ویژه']));
        $this->assertSame(1, $this->research()->publicCount(['q' => 'چکیدهٔ ممتاز']));
        $this->assertSame(1, $this->research()->publicCount(['q' => 'پژوهگر الف']));
    }

    /* -----------------------------------------------------------------
     | Lessons
     * ----------------------------------------------------------------- */

    public function testLessonCreateWithOrderAndLock(): void
    {
        $id = $this->lessons()->create([
            'slug' => 'dars-1',
            'title' => 'درس یکم: مقدمات',
            'body' => 'متن درس',
            'status' => 'published',
            'sort_order' => 3,
            'requires_login' => true,
        ]);

        $row = $this->lessons()->findPublishedBySlug('dars-1');
        $this->assertTrue($row !== null);
        $this->assertSame(3, (int) $row['sort_order']);
        $this->assertSame(1, (int) $row['requires_login']);
    }

    public function testLessonsListInCurriculumOrder(): void
    {
        $this->lessons()->create(['slug' => 'l3', 'title' => 'درس سوم', 'status' => 'published', 'sort_order' => 30]);
        $this->lessons()->create(['slug' => 'l1', 'title' => 'درس یکم', 'status' => 'published', 'sort_order' => 10]);
        $this->lessons()->create(['slug' => 'l2', 'title' => 'درس دوم', 'status' => 'published', 'sort_order' => 20]);

        $titles = array_map(static fn (array $r): string => (string) $r['title'], $this->lessons()->publicList());
        $this->assertSame(['درس یکم', 'درس دوم', 'درس سوم'], $titles, 'sort_order drives the curriculum order');
    }

    public function testNextSortOrderContinuesTheSequence(): void
    {
        $this->assertSame(1, $this->lessons()->nextSortOrder());
        $this->lessons()->create(['slug' => 'l1', 'title' => 'اول', 'status' => 'published', 'sort_order' => 7]);
        $this->assertSame(8, $this->lessons()->nextSortOrder());
    }

    public function testLessonSaveUpdatesExtensionRow(): void
    {
        $id = $this->lessons()->create(['slug' => 'ls', 'title' => 'درس', 'status' => 'published', 'sort_order' => 1]);
        $this->lessons()->save($id, 42, true);

        $row = $this->lessons()->find($id);
        $this->assertSame(42, (int) $row['sort_order']);
        $this->assertSame(1, (int) $row['requires_login']);
    }

    public function testLessonDeleteCascades(): void
    {
        $contents = new ContentRepository();
        $id = $this->lessons()->create(['slug' => 'lx', 'title' => 'درس حذفی', 'status' => 'published']);
        $contents->delete($id);

        $left = db_value('SELECT COUNT(*) FROM `lessons` WHERE `content_id` = ?', [$id], 0);
        $this->assertSame(0, (int) $left);
    }

    /* -----------------------------------------------------------------
     | Cross-cutting rules
     * ----------------------------------------------------------------- */

    public function testUnknownKnowledgeTypeIsRejectedBeforeSql(): void
    {
        $raised = false;
        try {
            (new ContentRepository())->publicList('movie');
        } catch (InvalidArgumentException) {
            $raised = true;
        }
        $this->assertTrue($raised, 'the content-type whitelist must stay closed');
    }

    public function testKnowledgeTypesAcceptPersianTextEndToEnd(): void
    {
        $title = 'کتاب «سِفْرُ الْهُدى» — ویرایش دوم (۱۴۰۴)';
        $id = $this->books()->create(['slug' => 'sifr-alhoda-1404', 'title' => $title, 'author' => 'مؤلف', 'status' => 'published']);

        $row = $this->books()->findPublishedBySlug('sifr-alhoda-1404');
        $this->assertSame($title, (string) $row['title'], 'Persian text must round-trip byte-identical');
        $this->assertSame($id, (int) $row['id']);
    }
}
