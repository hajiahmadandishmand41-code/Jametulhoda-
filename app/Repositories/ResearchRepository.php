<?php

declare(strict_types=1);

/**
 * ResearchRepository — Phase 6.
 *
 * Owns the `research` extension row (the researcher/author field) and the
 * public reads for the /research section. The shared content columns stay
 * in ContentRepository; attachments (documents/PDFs) ride on the existing
 * content_media link table through MediaRepository.
 */
final class ResearchRepository extends BaseRepository
{
    protected string $table = 'research';

    /* -----------------------------------------------------------------
     | Writes
     * ----------------------------------------------------------------- */

    /**
     * Create a research item: the shared `contents` row plus its
     * `research` row, in one transaction.
     *
     * @param array<string,mixed> $data slug, title, summary, body, topic_id,
     *                                  cover_media_id, status, published_at, author
     */
    public function create(array $data): int
    {
        return db_transaction(static function () use ($data): int {
            $contents = new ContentRepository();
            $contentId = $contents->create($data + ['content_type' => 'research']);

            db_insert('research', [
                'content_id'   => $contentId,
                'content_type' => 'research',
                'author'       => self::normalizeAuthor($data['author'] ?? null),
            ]);

            return $contentId;
        });
    }

    /**
     * Create or update the extension row for an existing content id.
     */
    public function save(int $contentId, ?string $author): void
    {
        $exists = (int) db_value(
            'SELECT COUNT(*) FROM `research` WHERE `content_id` = ?',
            [$contentId],
            0
        ) > 0;

        if ($exists) {
            db_update('research', ['author' => self::normalizeAuthor($author)], ['content_id' => $contentId]);
            return;
        }

        db_insert('research', [
            'content_id'   => $contentId,
            'content_type' => 'research',
            'author'       => self::normalizeAuthor($author),
        ]);
    }

    /* -----------------------------------------------------------------
     | Reads
     * ----------------------------------------------------------------- */

    /**
     * One research item (contents + research joined) by id — the admin read.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $contentId): ?array
    {
        return db_one(
            'SELECT c.*, r.`author`
             FROM `research` r
             JOIN `contents` c ON c.`id` = r.`content_id`
             WHERE r.`content_id` = ?
             LIMIT 1',
            [$contentId]
        );
    }

    /**
     * One PUBLISHED research item by slug — the public read.
     *
     * @return array<string,mixed>|null
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        return db_one(
            'SELECT c.*, r.`author`,
                    t.`title` AS `topic_title`, t.`slug` AS `topic_slug`,
                    m.`disk_path` AS `cover_path`, m.`alt_text` AS `cover_alt`
             FROM `research` r
             JOIN `contents` c ON c.`id` = r.`content_id`
             LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
             LEFT JOIN `media` m ON m.`id` = c.`cover_media_id`
             WHERE c.`slug` = ?
               AND c.`content_type` = \'research\'
               AND c.`status` = \'published\'
               AND c.`published_at` IS NOT NULL
               AND c.`published_at` <= ?
             LIMIT 1',
            [$slug, date('Y-m-d H:i:s')]
        );
    }

    /**
     * Published research, newest first, with optional topic/title filters.
     *
     * @param array{topic?:int|null,q?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function publicList(array $filters = [], int $limit = 12, int $offset = 0): array
    {
        [$limit, $offset] = $this->paging($limit, $offset);
        [$where, $params] = $this->publicFilter($filters);

        return db_all(
            sprintf(
                'SELECT c.*, r.`author`,
                        t.`title` AS `topic_title`, t.`slug` AS `topic_slug`,
                        m.`disk_path` AS `cover_path`, m.`alt_text` AS `cover_alt`
                 FROM `research` r
                 JOIN `contents` c ON c.`id` = r.`content_id`
                 LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
                 LEFT JOIN `media` m ON m.`id` = c.`cover_media_id`
                 WHERE %s
                 ORDER BY c.`published_at` DESC, c.`id` DESC
                 LIMIT %d OFFSET %d',
                implode(' AND ', $where),
                $limit,
                $offset
            ),
            $params
        );
    }

    /**
     * Count of published research honouring the same filters as publicList().
     */
    public function publicCount(array $filters = []): int
    {
        [$where, $params] = $this->publicFilter($filters);

        return (int) db_value(
            'SELECT COUNT(*)
             FROM `research` r
             JOIN `contents` c ON c.`id` = r.`content_id`
             LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
             WHERE ' . implode(' AND ', $where),
            $params,
            0
        );
    }

    /**
     * Other published research in the same topic (fallback: latest overall).
     *
     * @return list<array<string,mixed>>
     */
    public function relatedPublished(int $contentId, ?int $topicId, int $limit = 4): array
    {
        [$limit] = $this->paging($limit, 0, 50);
        $now = date('Y-m-d H:i:s');

        if ($topicId !== null) {
            $rows = db_all(
                sprintf(
                    'SELECT c.*, r.`author`,
                            t.`title` AS `topic_title`, t.`slug` AS `topic_slug`,
                            m.`disk_path` AS `cover_path`, m.`alt_text` AS `cover_alt`
                     FROM `research` r
                     JOIN `contents` c ON c.`id` = r.`content_id`
                     LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
                     LEFT JOIN `media` m ON m.`id` = c.`cover_media_id`
                     WHERE c.`content_type` = \'research\'
                       AND c.`status` = \'published\'
                       AND c.`published_at` IS NOT NULL
                       AND c.`published_at` <= ?
                       AND c.`topic_id` = ?
                       AND c.`id` <> ?
                     ORDER BY c.`published_at` DESC, c.`id` DESC
                     LIMIT %d',
                    $limit
                ),
                [$now, $topicId, $contentId]
            );
            if ($rows !== []) {
                return $rows;
            }
        }

        return db_all(
            sprintf(
                'SELECT c.*, r.`author`,
                        t.`title` AS `topic_title`, t.`slug` AS `topic_slug`,
                        m.`disk_path` AS `cover_path`, m.`alt_text` AS `cover_alt`
                 FROM `research` r
                 JOIN `contents` c ON c.`id` = r.`content_id`
                 LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
                 LEFT JOIN `media` m ON m.`id` = c.`cover_media_id`
                 WHERE c.`content_type` = \'research\'
                   AND c.`status` = \'published\'
                   AND c.`published_at` IS NOT NULL
                   AND c.`published_at` <= ?
                   AND c.`id` <> ?
                 ORDER BY c.`published_at` DESC, c.`id` DESC
                 LIMIT %d',
                $limit
            ),
            [$now, $contentId]
        );
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * Shared WHERE builder for publicList()/publicCount().
     *
     * @param array{topic?:int|null,q?:string} $filters
     * @return array{0:list<string>,1:list<mixed>}
     */
    private function publicFilter(array $filters): array
    {
        $where = [
            'c.`content_type` = \'research\'',
            'c.`status` = \'published\'',
            'c.`published_at` IS NOT NULL',
            'c.`published_at` <= ?',
        ];
        $params = [date('Y-m-d H:i:s')];

        if (!empty($filters['topic'])) {
            $where[] = 'c.`topic_id` = ?';
            $params[] = (int) $filters['topic'];
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $escaped = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $q) . '%';
            $where[] = '(c.`title` LIKE ? ESCAPE \'=\' OR r.`author` LIKE ? ESCAPE \'=\' OR c.`summary` LIKE ? ESCAPE \'=\')';
            array_push($params, $escaped, $escaped, $escaped);
        }

        return [$where, $params];
    }

    private static function normalizeAuthor(mixed $author): ?string
    {
        $author = trim((string) ($author ?? ''));

        return $author === '' ? null : mb_substr($author, 0, 250, 'UTF-8');
    }
}
