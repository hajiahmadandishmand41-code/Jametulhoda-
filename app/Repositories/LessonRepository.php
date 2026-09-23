<?php

declare(strict_types=1);

/**
 * LessonRepository — Phase 6.
 *
 * Owns the `lessons` extension row: the teaching sequence (sort_order) and
 * the access policy (requires_login). The public list orders lessons by
 * sort_order first — a course reads as an ordered curriculum, not a feed.
 *
 * Access rule (enforced by the routes, never by the view):
 *   requires_login = 0 -> public like any published content
 *   requires_login = 1 -> guests see the public teaser only; the body and
 *                         attached media are withheld server-side
 */
final class LessonRepository extends BaseRepository
{
    protected string $table = 'lessons';

    /* -----------------------------------------------------------------
     | Writes
     * ----------------------------------------------------------------- */

    /**
     * Create a lesson: the shared `contents` row plus its `lessons` row.
     *
     * @param array<string,mixed> $data slug, title, summary, body, topic_id,
     *                                  cover_media_id, status, published_at,
     *                                  sort_order, requires_login
     */
    public function create(array $data): int
    {
        return db_transaction(static function () use ($data): int {
            $contents = new ContentRepository();
            $contentId = $contents->create($data + ['content_type' => 'lesson']);

            db_insert('lessons', [
                'content_id'     => $contentId,
                'content_type'   => 'lesson',
                'sort_order'     => max(0, (int) ($data['sort_order'] ?? 0)),
                'requires_login' => !empty($data['requires_login']) ? 1 : 0,
            ]);

            return $contentId;
        });
    }

    /**
     * Create or update the extension row for an existing content id.
     */
    public function save(int $contentId, int $sortOrder, bool $requiresLogin): void
    {
        $exists = (int) db_value(
            'SELECT COUNT(*) FROM `lessons` WHERE `content_id` = ?',
            [$contentId],
            0
        ) > 0;

        $payload = [
            'sort_order'     => max(0, $sortOrder),
            'requires_login' => $requiresLogin ? 1 : 0,
        ];

        if ($exists) {
            db_update('lessons', $payload, ['content_id' => $contentId]);
            return;
        }

        db_insert('lessons', $payload + [
            'content_id'   => $contentId,
            'content_type' => 'lesson',
        ]);
    }

    /**
     * The next free sort position (current max + 1), so a new lesson
     * lands at the end of the curriculum by default.
     */
    public function nextSortOrder(): int
    {
        return 1 + (int) db_value('SELECT COALESCE(MAX(`sort_order`), 0) FROM `lessons`', [], 0);
    }

    /* -----------------------------------------------------------------
     | Reads
     * ----------------------------------------------------------------- */

    /**
     * One lesson (contents + lessons joined) by id — the admin read.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $contentId): ?array
    {
        return db_one(
            'SELECT c.*, l.`sort_order`, l.`requires_login`
             FROM `lessons` l
             JOIN `contents` c ON c.`id` = l.`content_id`
             WHERE l.`content_id` = ?
             LIMIT 1',
            [$contentId]
        );
    }

    /**
     * One PUBLISHED lesson by slug — the public read.
     *
     * @return array<string,mixed>|null
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        return db_one(
            'SELECT c.*, l.`sort_order`, l.`requires_login`,
                    t.`title` AS `topic_title`, t.`slug` AS `topic_slug`,
                    m.`disk_path` AS `cover_path`, m.`alt_text` AS `cover_alt`
             FROM `lessons` l
             JOIN `contents` c ON c.`id` = l.`content_id`
             LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
             LEFT JOIN `media` m ON m.`id` = c.`cover_media_id`
             WHERE c.`slug` = ?
               AND c.`content_type` = \'lesson\'
               AND c.`status` = \'published\'
               AND c.`published_at` IS NOT NULL
               AND c.`published_at` <= ?
             LIMIT 1',
            [$slug, date('Y-m-d H:i:s')]
        );
    }

    /**
     * Published lessons in curriculum order (sort_order first), newest first
     * within the same position, with optional topic filter.
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(array $filters = [], int $limit = 12, int $offset = 0): array
    {
        [$limit, $offset] = $this->paging($limit, $offset);
        [$where, $params] = $this->publicFilter($filters);

        return db_all(
            sprintf(
                'SELECT c.*, l.`sort_order`, l.`requires_login`,
                        t.`title` AS `topic_title`, t.`slug` AS `topic_slug`,
                        m.`disk_path` AS `cover_path`, m.`alt_text` AS `cover_alt`
                 FROM `lessons` l
                 JOIN `contents` c ON c.`id` = l.`content_id`
                 LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
                 LEFT JOIN `media` m ON m.`id` = c.`cover_media_id`
                 WHERE %s
                 ORDER BY l.`sort_order` ASC, c.`published_at` DESC, c.`id` DESC
                 LIMIT %d OFFSET %d',
                implode(' AND ', $where),
                $limit,
                $offset
            ),
            $params
        );
    }

    /**
     * Count of published lessons honouring the same filters as publicList().
     */
    public function publicCount(array $filters = []): int
    {
        [$where, $params] = $this->publicFilter($filters);

        return (int) db_value(
            'SELECT COUNT(*)
             FROM `lessons` l
             JOIN `contents` c ON c.`id` = l.`content_id`
             WHERE ' . implode(' AND ', $where),
            $params,
            0
        );
    }

    /**
     * Other published lessons of the same topic in curriculum order
     * (fallback: latest published lessons overall).
     *
     * @return list<array<string,mixed>>
     */
    public function relatedPublished(int $contentId, ?int $topicId, int $limit = 4): array
    {
        [$limit] = $this->paging($limit, 0, 50);
        $now = date('Y-m-d H:i:s');
        $select = 'SELECT c.*, l.`sort_order`, l.`requires_login`,
                          t.`title` AS `topic_title`, t.`slug` AS `topic_slug`,
                          m.`disk_path` AS `cover_path`, m.`alt_text` AS `cover_alt`
                   FROM `lessons` l
                   JOIN `contents` c ON c.`id` = l.`content_id`
                   LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
                   LEFT JOIN `media` m ON m.`id` = c.`cover_media_id`
                   WHERE c.`content_type` = \'lesson\'
                     AND c.`status` = \'published\'
                     AND c.`published_at` IS NOT NULL
                     AND c.`published_at` <= ?';

        if ($topicId !== null) {
            $rows = db_all(
                sprintf($select . '
                     AND c.`topic_id` = ?
                     AND c.`id` <> ?
                     ORDER BY l.`sort_order` ASC, c.`published_at` DESC
                     LIMIT %d', $limit),
                [$now, $topicId, $contentId]
            );
            if ($rows !== []) {
                return $rows;
            }
        }

        return db_all(
            sprintf($select . '
                     AND c.`id` <> ?
                     ORDER BY l.`sort_order` ASC, c.`published_at` DESC
                     LIMIT %d', $limit),
            [$now, $contentId]
        );
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Shared WHERE builder for publicList()/publicCount().
     *
     * @param array{topic?:int|null} $filters
     * @return array{0:list<string>,1:list<mixed>}
     */
    private function publicFilter(array $filters): array
    {
        $where = [
            'c.`content_type` = \'lesson\'',
            'c.`status` = \'published\'',
            'c.`published_at` IS NOT NULL',
            'c.`published_at` <= ?',
        ];
        $params = [date('Y-m-d H:i:s')];

        if (!empty($filters['topic'])) {
            $where[] = 'c.`topic_id` = ?';
            $params[] = (int) $filters['topic'];
        }

        return [$where, $params];
    }
}
