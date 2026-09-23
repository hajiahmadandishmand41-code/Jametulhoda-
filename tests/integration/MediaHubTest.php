<?php

declare(strict_types=1);

/**
 * Phase 6 — the multimedia hub (/media).
 *
 * Guarantees under test:
 *   * only media attached to PUBLISHED content is listed;
 *   * unattached media (admin library only) is never public;
 *   * attachments of login-required lessons are hidden from guests and
 *     revealed to authenticated viewers — the hub must not become a
 *     side door around the lesson access rule;
 *   * the "related content" map points at published rows only;
 *   * the type filter and pagination behave.
 */
final class MediaHubTest extends TestCase
{
    private PDO $pdo;

    private MediaRepository $media;

    public function setUp(): void
    {
        $this->pdo = SchemaSandbox::fresh();
        SchemaSandbox::clear($this->pdo);
        db_set_connection($this->pdo);
        $this->media = new MediaRepository();
    }

    public function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        db_set_connection(null);
        auth_session_logout();
        http_response_code(200);
    }

    private function dispatch(string $method, string $path): array
    {
        $router = new Router();
        define_routes($router);

        $parts = parse_url($path);
        $routePath = (string) ($parts['path'] ?? '/');
        $_SERVER['QUERY_STRING'] = (string) ($parts['query'] ?? '');
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = [];
        if ($_SERVER['QUERY_STRING'] !== '') {
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        }

        http_response_code(200);
        ob_start();
        $router->dispatch($method, $routePath);
        $body = (string) ob_get_clean();
        $code = (int) http_response_code();
        http_response_code(200);

        return [$code, $body];
    }

    private function makeMedia(string $type, string $path): int
    {
        return $this->media->create([
            'media_type' => $type,
            'disk_path' => $path,
            'original_name' => $path,
            'mime_type' => $type === 'video' ? 'video/mp4' : 'audio/mpeg',
            'title' => 'رسانهٔ ' . $path,
        ]);
    }

    private function attachToPublishedContent(int $mediaId, string $type = 'news', array $overrides = []): int
    {
        $data = array_merge([
            'slug' => 'host-' . bin2hex(random_bytes(3)),
            'title' => 'محتوای میزبان',
            'body' => 'متن',
            'status' => 'published',
        ], $overrides);
        $contentId = (new ContentRepository())->create($data + ['content_type' => $type]);
        if ($type === 'lesson') {
            (new LessonRepository())->save($contentId, 1, !empty($overrides['requires_login']));
        }
        $this->media->attachToContent($contentId, $mediaId, $type === 'lesson' ? 'video' : 'video', 0);

        return $contentId;
    }

    public function testUnattachedMediaIsNeverListed(): void
    {
        $this->makeMedia('video', 'uploads/video/lonely.mp4');

        [$code, $body] = $this->dispatch('GET', '/media');
        $this->assertSame(200, $code);
        $this->assertContains('empty-state', $body);
        $this->assertNotContains('lonely.mp4', $body);
    }

    public function testMediaOfDraftContentIsNeverListed(): void
    {
        $mediaId = $this->makeMedia('audio', 'uploads/audio/draft-song.mp3');
        $this->attachToPublishedContent($mediaId, 'news', ['status' => 'draft']);

        [, $body] = $this->dispatch('GET', '/media');
        $this->assertNotContains('draft-song.mp3', $body);
        $this->assertSame(0, $this->media->publicHubCount(null, false));
    }

    public function testAttachedMediaIsListedWithRelatedContent(): void
    {
        $mediaId = $this->makeMedia('video', 'uploads/video/attached.mp4');
        $contentId = $this->attachToPublishedContent($mediaId);

        [$code, $body] = $this->dispatch('GET', '/media');
        $this->assertSame(200, $code);
        $this->assertContains('attached.mp4', $body);
        $this->assertContains('محتوای میزبان', $body, 'the related content of the media is shown');
        $this->assertContains(content_url('news', (string) db_value('SELECT `slug` FROM `contents` WHERE `id` = ?', [$contentId])), $body);
    }

    public function testLessonLockedMediaIsHiddenFromGuestsAndShownToUsers(): void
    {
        $mediaId = $this->makeMedia('audio', 'uploads/audio/class-locked.mp3');
        $this->attachToPublishedContent($mediaId, 'lesson', ['requires_login' => true]);

        // guest: hidden
        [, $guestBody] = $this->dispatch('GET', '/media');
        $this->assertNotContains('class-locked.mp3', $guestBody);

        // authenticated: visible
        SessionManager::authenticate([
            'id' => 7, 'name' => 'کاربر', 'email' => 'u@example.test',
            'role' => 'user', 'is_active' => true,
        ]);
        [, $userBody] = $this->dispatch('GET', '/media');
        $this->assertContains('class-locked.mp3', $userBody);
    }

    public function testOpenLessonMediaIsPublic(): void
    {
        $mediaId = $this->makeMedia('video', 'uploads/video/class-open.mp4');
        $this->attachToPublishedContent($mediaId, 'lesson', ['requires_login' => false]);

        [, $body] = $this->dispatch('GET', '/media');
        $this->assertContains('class-open.mp4', $body);
    }

    public function testTypeFilter(): void
    {
        $videoId = $this->makeMedia('video', 'uploads/video/filter.mp4');
        $audioId = $this->makeMedia('audio', 'uploads/audio/filter.mp3');
        $this->attachToPublishedContent($videoId);
        $this->attachToPublishedContent($audioId);

        [, $videoOnly] = $this->dispatch('GET', '/media?type=video');
        $this->assertContains('filter.mp4', $videoOnly);
        $this->assertNotContains('filter.mp3', $videoOnly);

        [, $audioOnly] = $this->dispatch('GET', '/media?type=audio');
        $this->assertContains('filter.mp3', $audioOnly);
        $this->assertNotContains('filter.mp4', $audioOnly);

        [, $both] = $this->dispatch('GET', '/media');
        $this->assertContains('filter.mp4', $both);
        $this->assertContains('filter.mp3', $both);
    }

    public function testHubPagination(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $mediaId = $this->makeMedia('audio', 'uploads/audio/page-' . $i . '.mp3');
            $this->attachToPublishedContent($mediaId, 'news', ['slug' => 'host-page-' . $i]);
        }

        // Newest first: page 2 holds the OLDEST file (page-1.mp3).
        [$code, $body] = $this->dispatch('GET', '/media?page=2');
        $this->assertSame(200, $code);
        $this->assertContains('page-1.mp3', $body);
        $this->assertNotContains('page-2.mp3', $body);
    }

    public function testRepositoryLayerRefusesUnknownHubType(): void
    {
        $raised = false;
        try {
            $this->media->publicHubList('image', false);
        } catch (InvalidArgumentException) {
            $raised = true;
        }
        $this->assertTrue($raised, 'the hub type filter is a closed whitelist');
    }

    public function testDocumentsAndImagesNeverAppearInHub(): void
    {
        $docId = $this->makeMedia('document', 'uploads/documents/note.pdf');
        $imageId = $this->makeMedia('image', 'uploads/images/pic.png');
        $this->attachToPublishedContent($docId);
        $this->attachToPublishedContent($imageId);

        [, $body] = $this->dispatch('GET', '/media');
        $this->assertNotContains('note.pdf', $body);
        $this->assertNotContains('pic.png', $body);
    }
}
