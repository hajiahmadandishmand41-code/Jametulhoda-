<?php

declare(strict_types=1);

/**
 * BookRepository — Phase 6.
 *
 * Owns the `books` extension row (the book-specific author field) and the
 * public reads for the /books section. Everything the book shares with any
 * other content type (slug, title, summary, body, topic, cover, status,
 * published_at, media, relations) stays in ContentRepository — this class
 * only adds what is genuinely book-specific.
 */
final class BookRepository extends BaseRepository
{
    protected string $table = 'books';

    /* -----------------------------------------------------------------
     | Writes
     * ----------------------------------------------------------------- */

    /**
     * Create a book: the shared `contents` row plus its `books` row.
     * Two tables are touched, so it runs inside a transaction.
     *
     * @param array<string,mixed> $data slug, title, summary, body, topic_id,
     *                                  cover_media_id, status, published_at, author
     */
    public function create(array $data): int
    {
        return db_transaction(static function () use ($data): int {
            $contents = new ContentRepository();
            $contentId = $contents->create($data + ['content_type' => 'book']);

            db_insert('books', [
                'content_id'   => $contentId,
                'content_type' => 'book',
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
            'SELECT COUNT(*) FROM `books` WHERE `content_id` = ?',
            [$contentId],
            0
        ) > 0;

        if ($exists) {
            db_update('books', ['author' => self::normalizeAuthor($author)], ['content_id' => $contentId]);
            return;
        }

        db_insert('books', [
            'content_id'   => $contentId,
            'content_type' => 'book',
            'author'       => self::normalizeAuthor($author),
        ]);
    }

    /* -----------------------------------------------------------------
     | Reads
     * ----------------------------------------------------------------- */

    /**
     * One book (contents + books joined) by id — the admin read.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $contentId): ?array
    {
        return db_one(
            'SELECT c.*, b.`author`
             FROM `books` b
             JOIN `contents` c ON c.`id` = b.`content_id`
             WHERE b.`content_id` = ?
             LIMIT 1',
            [$contentId]
        );
    }

    /**
     * One PUBLISHED book by slug — the public read. Only rows with
     * status = 'published' and published_at <= now are ever returned.
     *
     * @return array<string,mixed>|null
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        return db_one(
            'SELECT c.*, b.`author`,
                    t.`title` AS `topic_title`, t.`slug` AS `topic_slug`,
                    m.`disk_path` AS `cover_path`, m.`alt_text` AS `cover_alt`
             FROM `books` b
             JOIN `contents` c ON c.`id` = b.`content_id`
             LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
             LEFT JOIN `media` m ON m.`id` = c.`cover_media_id`
             WHERE c.`slug` = ?
               AND c.`content_type` = \'book\'
               AND c.`status` = \'published\'
               AND c.`published_at` IS NOT NULL
               AND c.`published_at` <= ?
             LIMIT 1',
            [$slug, date('Y-m-d H:i:s')]
        );
    }

    /**
     * Published books, newest first, with optional topic and title filters.
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
                'SELECT c.*, b.`author`,
                        t.`title` AS `topic_title`, t.`slug` AS `topic_slug`,
                        m.`disk_path` AS `cover_path`, m.`alt_text` AS `cover_alt`
                 FROM `books` b
                 JOIN `contents` c ON c.`id` = b.`content_id`
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
     * Count of published books honouring the same filters as publicList().
     */
    public function publicCount(array $filters = []): int
    {
        [$where, $params] = $this->publicFilter($filters);

        return (int) db_value(
            'SELECT COUNT(*)
             FROM `books` b
             JOIN `contents` c ON c.`id` = b.`content_id`
             LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
             WHERE ' . implode(' AND ', $where),
            $params,
            0
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
            'c.`content_type` = \'book\'',
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
            // LIKE wildcards are escaped so the user query is matched literally.
            $escaped = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $q) . '%';
            $where[] = '(c.`title` LIKE ? ESCAPE \'=\' OR b.`author` LIKE ? ESCAPE \'=\')';
            array_push($params, $escaped, $escaped);
        }

        return [$where, $params];
    }

    private static function normalizeAuthor(mixed $author): ?string
    {
        $author = trim((string) ($author ?? ''));

        return $author === '' ? null : mb_substr($author, 0, 250, 'UTF-8');
    }
}
