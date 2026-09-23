<?php

declare(strict_types=1);

/**
 * Phase 2 — the data layer against a real installed schema.
 *
 * Covers insert/select/update/delete, the relationships (report images,
 * content media, related content), slug uniqueness, draft/published
 * behaviour, transactions and Persian text round-trips.
 *
 * Each test starts from an empty database (setUp) so order never matters.
 */
final class DataLayerTest extends TestCase
{
    private PDO $pdo;

    private ContentRepository $contents;

    private TopicRepository $topics;

    private MediaRepository $media;

    private ReportRepository $reports;

    private EventRepository $events;

    /**
     * Install the schema, empty it and point the repositories at it.
     */
    public function setUp(): void
    {
        $this->pdo = SchemaSandbox::fresh();
        SchemaSandbox::clear($this->pdo);
        db_set_connection($this->pdo);

        $this->contents = new ContentRepository();
        $this->topics = new TopicRepository();
        $this->media = new MediaRepository();
        $this->reports = new ReportRepository();
        $this->events = new EventRepository();
    }

    /**
     * Hand the shared connection back, so no other suite inherits the
     * scratch database.
     */
    public function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        db_set_connection(null);
    }

    /* -----------------------------------------------------------------
     | Basic CRUD
     * ----------------------------------------------------------------- */

    public function testInsertAndSelectContent(): void
    {
        $id = $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'first-article',
            'title'        => 'اولین مقاله',
            'summary'      => 'خلاصه',
            'body'         => 'متن کامل',
            'status'       => 'draft',
        ]);

        $this->assertTrue($id > 0, 'insert must return the new id');

        $row = $this->contents->find($id);
        $this->assertTrue($row !== null, 'the inserted row must be readable');
        $this->assertSame('اولین مقاله', (string) $row['title']);
        $this->assertSame('article', (string) $row['content_type']);
        $this->assertSame('draft', (string) $row['status']);
    }

    public function testUpdateContent(): void
    {
        $id = $this->contents->create([
            'content_type' => 'news',
            'slug'         => 'news-update',
            'title'        => 'عنوان اولیه',
        ]);

        $this->assertTrue($this->contents->update($id, ['title' => 'عنوان ویرایش‌شده']));

        $row = (array) $this->contents->find($id);
        $this->assertSame('عنوان ویرایش‌شده', (string) $row['title']);
    }

    public function testDeleteContent(): void
    {
        $id = $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'to-be-deleted',
            'title'        => 'حذف‌شدنی',
        ]);

        $this->assertTrue($this->contents->delete($id));
        $this->assertNull($this->contents->find($id), 'the row must be gone after delete');
    }

    public function testTopicCrudAndChildren(): void
    {
        $parent = $this->topics->create(['slug' => 'aqaed', 'title' => 'عقاید']);
        $child = $this->topics->create(['slug' => 'tafsir', 'title' => 'تفسیر', 'parent_id' => $parent]);

        $this->assertSame(1, count($this->topics->children($parent)));
        $this->assertSame('تفسیر', (string) ((array) $this->topics->find($child))['title']);

        $this->topics->update($child, ['title' => 'تفسیر قرآن']);
        $this->assertSame('تفسیر قرآن', (string) ((array) $this->topics->find($child))['title']);

        $this->assertSame(1, count($this->topics->allActive()) - 1, 'both topics are active');
    }

    /* -----------------------------------------------------------------
     | Slug uniqueness
     * ----------------------------------------------------------------- */

    public function testSlugIsUniquePerContentType(): void
    {
        $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'shared-slug',
            'title'        => 'مقاله',
        ]);

        $threw = false;
        try {
            $this->contents->create([
                'content_type' => 'article',
                'slug'         => 'shared-slug',
                'title'        => 'تکراری',
            ]);
        } catch (PDOException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'the same slug must not be reusable within one content type');
    }

    public function testSameSlugIsAllowedAcrossDifferentTypes(): void
    {
        $article = $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'ramadan',
            'title'        => 'مقاله رمضان',
        ]);
        $news = $this->contents->create([
            'content_type' => 'news',
            'slug'         => 'ramadan',
            'title'        => 'خبر رمضان',
        ]);

        $this->assertTrue($article !== $news);
        $this->assertSame('مقاله رمضان', (string) ((array) $this->contents->findBySlug('article', 'ramadan'))['title']);
        $this->assertSame('خبر رمضان', (string) ((array) $this->contents->findBySlug('news', 'ramadan'))['title']);
    }

    public function testSlugExistsHelper(): void
    {
        $id = $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'exists-check',
            'title'        => 'بررسی',
        ]);

        $this->assertTrue($this->contents->slugExists('article', 'exists-check'));
        $this->assertFalse($this->contents->slugExists('news', 'exists-check'), 'slugs are scoped per type');
        $this->assertFalse(
            $this->contents->slugExists('article', 'exists-check', $id),
            'a row must not collide with itself while being edited'
        );
    }

    public function testTopicSlugIsUnique(): void
    {
        $this->topics->create(['slug' => 'akhlaq', 'title' => 'اخلاق']);

        $threw = false;
        try {
            $this->topics->create(['slug' => 'akhlaq', 'title' => 'تکراری']);
        } catch (PDOException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'topics.slug must be unique');
    }

    /* -----------------------------------------------------------------
     | Draft / published behaviour
     * ----------------------------------------------------------------- */

    public function testDraftIsNotVisiblePublicly(): void
    {
        $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'draft-article',
            'title'        => 'پیش‌نویس',
            'status'       => 'draft',
        ]);

        $this->assertNull(
            $this->contents->findPublishedBySlug('article', 'draft-article'),
            'a draft must never be returned by the public lookup'
        );
        $this->assertSame(0, $this->contents->countPublished('article'));
        $this->assertSame(0, count($this->contents->listPublished('article')));
    }

    public function testPublishingStampsPublishedAt(): void
    {
        $id = $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'to-publish',
            'title'        => 'برای انتشار',
            'status'       => 'draft',
        ]);
        $this->assertNull(((array) $this->contents->find($id))['published_at']);

        $this->assertTrue($this->contents->publish($id));

        $row = (array) $this->contents->find($id);
        $this->assertSame('published', (string) $row['status']);
        $this->assertTrue($row['published_at'] !== null, 'publishing must stamp published_at');
        $this->assertSame(1, $this->contents->countPublished('article'));
    }

    public function testUnpublishingClearsPublishedAt(): void
    {
        $id = $this->contents->create([
            'content_type' => 'news',
            'slug'         => 'published-news',
            'title'        => 'خبر منتشرشده',
            'status'       => 'published',
        ]);
        $this->assertSame(1, $this->contents->countPublished('news'));

        $this->assertTrue($this->contents->unpublish($id));

        $row = (array) $this->contents->find($id);
        $this->assertSame('draft', (string) $row['status']);
        $this->assertNull($row['published_at'], 'going back to draft must clear published_at');
        $this->assertSame(0, $this->contents->countPublished('news'));
    }

    public function testFuturePublishedAtStaysHidden(): void
    {
        $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'scheduled',
            'title'        => 'زمان‌بندی‌شده',
            'status'       => 'published',
            'published_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $this->assertNull(
            $this->contents->findPublishedBySlug('article', 'scheduled'),
            'an item scheduled for the future must not be public yet'
        );
        $this->assertSame(0, $this->contents->countPublished('article'));
    }

    public function testListByStatusSeparatesDraftsFromPublished(): void
    {
        $this->contents->create(['content_type' => 'article', 'slug' => 'p1', 'title' => 'یک', 'status' => 'published']);
        $this->contents->create(['content_type' => 'article', 'slug' => 'd1', 'title' => 'دو', 'status' => 'draft']);
        $this->contents->create(['content_type' => 'article', 'slug' => 'd2', 'title' => 'سه', 'status' => 'draft']);

        $this->assertSame(1, count($this->contents->listByStatus('article', 'published')));
        $this->assertSame(2, count($this->contents->listByStatus('article', 'draft')));
    }

    /* -----------------------------------------------------------------
     | Report -> report images
     * ----------------------------------------------------------------- */

    public function testReportCanHoldManyImages(): void
    {
        $reportId = $this->reports->create([
            'slug'   => 'report-gallery',
            'title'  => 'گزارش تصویری',
            'status' => 'published',
        ]);

        $first = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/r1.jpg']);
        $second = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/r2.jpg']);
        $third = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/r3.jpg']);

        $this->reports->addImage($reportId, $first, 'تصویر یک', 1);
        $this->reports->addImage($reportId, $second, 'تصویر دو', 2);
        $this->reports->addImage($reportId, $third, 'تصویر سه', 3);

        $this->assertSame(3, $this->reports->imageCount($reportId));

        $captions = array_map(static fn (array $r): string => (string) $r['caption'], $this->reports->images($reportId));
        $this->assertSame(['تصویر یک', 'تصویر دو', 'تصویر سه'], $captions, 'gallery must come back in sort order');
    }

    public function testReportImagesAreRemovedWithTheReport(): void
    {
        $reportId = $this->reports->create(['slug' => 'cascade-report', 'title' => 'گزارش']);
        $mediaId = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/c1.jpg']);
        $this->reports->addImage($reportId, $mediaId, null, 1);

        $this->assertSame(1, $this->reports->imageCount($reportId));

        $this->reports->delete($reportId);

        $this->assertSame(
            0,
            (int) db_value('SELECT COUNT(*) FROM `report_images` WHERE `report_id` = ?', [$reportId], 0),
            'deleting a report must not leave orphan gallery rows'
        );
        $this->assertSame(
            0,
            (int) db_value('SELECT COUNT(*) FROM `reports` WHERE `content_id` = ?', [$reportId], 0),
            'the reports extension row must cascade too'
        );
        $this->assertTrue(
            $this->media->find($mediaId) !== null,
            'the media file itself stays in the registry'
        );
    }

    public function testDeletingMediaRemovesItFromGalleries(): void
    {
        $reportId = $this->reports->create(['slug' => 'media-cascade', 'title' => 'گزارش']);
        $mediaId = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/m1.jpg']);
        $this->reports->addImage($reportId, $mediaId, null, 1);

        db_delete('media', ['id' => $mediaId]);

        $this->assertSame(0, $this->reports->imageCount($reportId), 'gallery rows must not outlive their media');
    }

    public function testGalleryRejectsNonImageMedia(): void
    {
        $reportId = $this->reports->create(['slug' => 'audio-in-gallery', 'title' => 'گزارش']);
        $audioId = $this->media->create(['media_type' => 'audio', 'disk_path' => 'uploads/audio/a1.mp3']);

        $threw = false;
        try {
            $this->reports->addImage($reportId, $audioId);
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'only image media may enter a report gallery');
        $this->assertSame(0, $this->reports->imageCount($reportId));
    }

    public function testAddingTheSameImageTwiceUpdatesInsteadOfDuplicating(): void
    {
        $reportId = $this->reports->create(['slug' => 'dup-image', 'title' => 'گزارش']);
        $mediaId = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/d1.jpg']);

        $this->reports->addImage($reportId, $mediaId, 'اول', 1);
        $this->reports->addImage($reportId, $mediaId, 'دوم', 5);

        $this->assertSame(1, $this->reports->imageCount($reportId), 'unique (report_id, media_id) must hold');
        $images = $this->reports->images($reportId);
        $this->assertSame('دوم', (string) $images[0]['caption'], 're-adding updates the caption');
    }

    public function testSyncImagesReplacesTheWholeGallery(): void
    {
        $reportId = $this->reports->create(['slug' => 'sync-gallery', 'title' => 'گزارش']);
        $a = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/s1.jpg']);
        $b = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/s2.jpg']);
        $c = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/s3.jpg']);

        $this->reports->addImage($reportId, $a, 'قدیمی', 1);
        $this->reports->syncImages($reportId, [
            ['media_id' => $b, 'caption' => 'جدید یک', 'sort_order' => 1],
            ['media_id' => $c, 'caption' => 'جدید دو', 'sort_order' => 2],
        ]);

        $captions = array_map(static fn (array $r): string => (string) $r['caption'], $this->reports->images($reportId));
        $this->assertSame(['جدید یک', 'جدید دو'], $captions);
    }

    /* -----------------------------------------------------------------
     | Content -> media
     * ----------------------------------------------------------------- */

    public function testAudioAndVideoAttachToContent(): void
    {
        $articleId = $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'with-media',
            'title'        => 'مقاله با رسانه',
            'status'       => 'published',
        ]);

        $audio = $this->media->create(['media_type' => 'audio', 'disk_path' => 'uploads/audio/lecture.mp3']);
        $video = $this->media->create(['media_type' => 'video', 'disk_path' => 'uploads/video/clip.mp4']);

        $this->media->attachToContent($articleId, $audio, 'audio', 1);
        $this->media->attachToContent($articleId, $video, 'video', 2);

        $this->assertSame(2, count($this->media->forContent($articleId)));
        $this->assertSame(1, count($this->media->forContent($articleId, 'audio')));
        $this->assertSame(
            'uploads/video/clip.mp4',
            (string) $this->media->forContent($articleId, 'video')[0]['disk_path']
        );
    }

    public function testDetachingMediaKeepsTheFile(): void
    {
        $id = $this->contents->create(['content_type' => 'news', 'slug' => 'detach', 'title' => 'خبر']);
        $mediaId = $this->media->create(['media_type' => 'audio', 'disk_path' => 'uploads/audio/detach.mp3']);

        $this->media->attachToContent($id, $mediaId, 'audio');
        $this->assertTrue($this->media->detachFromContent($id, $mediaId));

        $this->assertSame(0, count($this->media->forContent($id)));
        $this->assertTrue($this->media->find($mediaId) !== null, 'detaching must not delete the media row');
    }

    public function testContentMediaLinksCascadeOnContentDelete(): void
    {
        $id = $this->contents->create(['content_type' => 'article', 'slug' => 'cascade-media', 'title' => 'مقاله']);
        $mediaId = $this->media->create(['media_type' => 'audio', 'disk_path' => 'uploads/audio/cascade.mp3']);
        $this->media->attachToContent($id, $mediaId, 'audio');

        $this->contents->delete($id);

        $this->assertSame(
            0,
            (int) db_value('SELECT COUNT(*) FROM `content_media` WHERE `content_id` = ?', [$id], 0),
            'no orphan content_media rows may survive'
        );
    }

    public function testCoverMediaIsClearedNotCascadedWhenMediaIsDeleted(): void
    {
        $mediaId = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/cover.jpg']);
        $id = $this->contents->create([
            'content_type'   => 'article',
            'slug'           => 'with-cover',
            'title'          => 'مقاله با جلد',
            'cover_media_id' => $mediaId,
        ]);

        db_delete('media', ['id' => $mediaId]);

        $row = $this->contents->find($id);
        $this->assertTrue($row !== null, 'deleting a cover image must not delete the article');
        $this->assertNull($row['cover_media_id'], 'the cover reference must be cleared (ON DELETE SET NULL)');
    }

    /* -----------------------------------------------------------------
     | Related content
     * ----------------------------------------------------------------- */

    public function testRelatedContentLinksAndOrders(): void
    {
        $article = $this->contents->create([
            'content_type' => 'article', 'slug' => 'main', 'title' => 'اصلی', 'status' => 'published',
        ]);
        $newsA = $this->contents->create([
            'content_type' => 'news', 'slug' => 'rel-a', 'title' => 'خبر الف', 'status' => 'published',
        ]);
        $newsB = $this->contents->create([
            'content_type' => 'news', 'slug' => 'rel-b', 'title' => 'خبر ب', 'status' => 'published',
        ]);

        $this->contents->relate($article, $newsB, 2);
        $this->contents->relate($article, $newsA, 1);

        $titles = array_map(
            static fn (array $r): string => (string) $r['title'],
            $this->contents->relatedPublished($article)
        );
        $this->assertSame(['خبر الف', 'خبر ب'], $titles, 'related items follow the editor order');
    }

    public function testRelatedContentHidesDrafts(): void
    {
        $article = $this->contents->create([
            'content_type' => 'article', 'slug' => 'rel-main', 'title' => 'اصلی', 'status' => 'published',
        ]);
        $draft = $this->contents->create([
            'content_type' => 'news', 'slug' => 'rel-draft', 'title' => 'پیش‌نویس', 'status' => 'draft',
        ]);
        $this->contents->relate($article, $draft, 1);

        $this->assertSame(0, count($this->contents->relatedPublished($article)), 'drafts stay out of public related lists');
        $this->assertSame(1, count($this->contents->relatedAll($article)), 'the editor still sees the link');
    }

    public function testContentCannotBeRelatedToItself(): void
    {
        $id = $this->contents->create(['content_type' => 'article', 'slug' => 'self', 'title' => 'خود']);

        $threw = false;
        try {
            $this->contents->relate($id, $id);
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'self-relation must be rejected');
        $this->assertSame(0, (int) db_value('SELECT COUNT(*) FROM `content_relations`', [], 0));
    }

    public function testRelationsCascadeWhenEitherSideIsDeleted(): void
    {
        $a = $this->contents->create(['content_type' => 'article', 'slug' => 'ra', 'title' => 'الف']);
        $b = $this->contents->create(['content_type' => 'news', 'slug' => 'rb', 'title' => 'ب']);
        $this->contents->relate($a, $b, 1);

        $this->contents->delete($b);

        $this->assertSame(
            0,
            (int) db_value('SELECT COUNT(*) FROM `content_relations`', [], 0),
            'a relation must not outlive the content it points at'
        );
    }

    public function testUnrelateRemovesOnlyTheLink(): void
    {
        $a = $this->contents->create(['content_type' => 'article', 'slug' => 'ua', 'title' => 'الف']);
        $b = $this->contents->create(['content_type' => 'news', 'slug' => 'ub', 'title' => 'ب']);
        $this->contents->relate($a, $b);

        $this->assertTrue($this->contents->unrelate($a, $b));
        $this->assertSame(0, count($this->contents->relatedAll($a)));
        $this->assertTrue($this->contents->find($b) !== null, 'unrelating must not delete the content');
    }

    /* -----------------------------------------------------------------
     | Orphan prevention
     * ----------------------------------------------------------------- */

    public function testForeignKeysRejectOrphanRows(): void
    {
        $threw = false;
        try {
            db_insert('content_media', [
                'content_id' => 999999,
                'media_id'   => 999999,
                'role'       => 'audio',
                'sort_order' => 1,
            ]);
        } catch (PDOException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a media link to a non-existent content row must be refused');
    }

    public function testReportImageRejectsUnknownReport(): void
    {
        $mediaId = $this->media->create(['media_type' => 'image', 'disk_path' => 'uploads/images/orphan.jpg']);

        $threw = false;
        try {
            db_insert('report_images', [
                'report_id'  => 999999,
                'media_id'   => $mediaId,
                'media_type' => 'image',
                'sort_order' => 1,
            ]);
        } catch (PDOException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a gallery row for a non-existent report must be refused');
    }

    public function testTopicDeletionDetachesContentInsteadOfDeletingIt(): void
    {
        $topicId = $this->topics->create(['slug' => 'temp-topic', 'title' => 'موقت']);
        $contentId = $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'keeps-living',
            'title'        => 'مقاله',
            'topic_id'     => $topicId,
        ]);

        db_delete('topics', ['id' => $topicId]);

        $row = $this->contents->find($contentId);
        $this->assertTrue($row !== null, 'removing a topic must not delete its content');
        $this->assertNull($row['topic_id'], 'the topic reference must be cleared (ON DELETE SET NULL)');
    }

    /* -----------------------------------------------------------------
     | Events
     * ----------------------------------------------------------------- */

    public function testEventStoresDatesAndIsFoundBySlug(): void
    {
        $id = $this->events->create([
            'slug'      => 'annual-meeting',
            'title'     => 'نشست سالانه',
            'status'    => 'published',
            'starts_at' => '2026-05-01 09:00:00',
            'ends_at'   => '2026-05-01 12:00:00',
            'location'  => 'سالن اصلی',
        ]);

        $row = (array) $this->events->findBySlug('annual-meeting');
        $this->assertSame($id, (int) $row['id']);
        $this->assertSame('2026-05-01 09:00:00', (string) $row['starts_at']);
        $this->assertSame('سالن اصلی', (string) $row['location']);
    }

    public function testEventRejectsEndBeforeStart(): void
    {
        $threw = false;
        try {
            $this->events->create([
                'slug'      => 'bad-dates',
                'title'     => 'تاریخ نادرست',
                'starts_at' => '2026-05-02 10:00:00',
                'ends_at'   => '2026-05-01 10:00:00',
            ]);
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'an event cannot end before it starts');
    }

    public function testUpcomingAndPastEventsAreSeparated(): void
    {
        $this->events->create([
            'slug'      => 'future-event',
            'title'     => 'رویداد آینده',
            'status'    => 'published',
            'starts_at' => date('Y-m-d H:i:s', time() + 7 * 86400),
        ]);
        $this->events->create([
            'slug'      => 'past-event',
            'title'     => 'رویداد گذشته',
            'status'    => 'published',
            'starts_at' => date('Y-m-d H:i:s', time() - 7 * 86400),
        ]);

        $upcoming = $this->events->upcoming();
        $past = $this->events->past();

        $this->assertSame(1, count($upcoming));
        $this->assertSame('رویداد آینده', (string) $upcoming[0]['title']);
        $this->assertSame(1, count($past));
        $this->assertSame('رویداد گذشته', (string) $past[0]['title']);
    }

    /* -----------------------------------------------------------------
     | Transactions
     * ----------------------------------------------------------------- */

    public function testTransactionRollsBackOnFailure(): void
    {
        $before = (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0);

        $threw = false;
        try {
            db_transaction(function (): void {
                $this->contents->create([
                    'content_type' => 'article',
                    'slug'         => 'rollback-me',
                    'title'        => 'برگشت',
                ]);
                throw new RuntimeException('forced failure');
            });
        } catch (RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'the exception must propagate');
        $this->assertSame($before, (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0), 'the insert must be rolled back');
        $this->assertNull($this->contents->findBySlug('article', 'rollback-me'));
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $id = db_transaction(fn (): int => $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'committed',
            'title'        => 'ثبت‌شده',
        ]));

        $this->assertTrue($this->contents->find($id) !== null, 'a successful transaction must commit');
    }

    public function testFailedReportCreationLeavesNoHalfRow(): void
    {
        // A report writes `contents` + `reports`; if the second insert fails
        // the first must be rolled back as well.
        $before = (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0);

        $threw = false;
        try {
            db_transaction(function (): void {
                $this->reports->create(['slug' => 'half-report', 'title' => 'گزارش']);
                throw new RuntimeException('failure after the report row');
            });
        } catch (RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertSame($before, (int) db_value('SELECT COUNT(*) FROM `contents`', [], 0));
        $this->assertSame(0, (int) db_value('SELECT COUNT(*) FROM `reports`', [], 0), 'no orphan reports row');
    }

    /* -----------------------------------------------------------------
     | Persian / UTF-8
     * ----------------------------------------------------------------- */

    public function testPersianTextRoundTripsIntact(): void
    {
        $title = 'جامعة الهدی — گزارش ویژه‌ی نیم‌سال';
        $body = "متن آزمایشی با نیم‌فاصله، «گیومه» و اعداد ۱۲۳۴۵۶۷۸۹۰.\nخط دوم با ي و ك عربی.";

        $id = $this->contents->create([
            'content_type' => 'report',
            'slug'         => 'persian-text',
            'title'        => $title,
            'summary'      => 'خلاصه‌ی فارسی',
            'body'         => $body,
            'status'       => 'published',
        ]);

        $row = (array) $this->contents->find($id);
        $this->assertSame($title, (string) $row['title'], 'Persian title must survive the round trip');
        $this->assertSame($body, (string) $row['body'], 'multiline Persian body must survive unchanged');
        $this->assertSame('خلاصه‌ی فارسی', (string) $row['summary']);
    }

    public function testPersianLookupByBoundParameter(): void
    {
        $this->contents->create([
            'content_type' => 'news',
            'slug'         => 'persian-search',
            'title'        => 'نشست علمی',
            'status'       => 'published',
        ]);

        $row = db_one('SELECT * FROM `contents` WHERE `title` = ?', ['نشست علمی']);
        $this->assertTrue($row !== null, 'a Persian value must be findable as a bound parameter');
        $this->assertSame('persian-search', (string) $row['slug']);
    }

    public function testEmojiAndFourByteCharactersSurvive(): void
    {
        // utf8mb4 (not utf8) is what makes 4-byte characters storable.
        $title = 'گزارش تصویری 📷 سال ۱۴۰۵';
        $id = $this->contents->create([
            'content_type' => 'article',
            'slug'         => 'four-byte',
            'title'        => $title,
        ]);

        $this->assertSame($title, (string) ((array) $this->contents->find($id))['title']);
    }
}
