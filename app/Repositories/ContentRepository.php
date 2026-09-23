<?php

declare(strict_types=1);

/**
 * ContentRepository — Phase 2.
 *
 * The one place that reads and writes `contents`, the shared spine of
 * articles, news, events and reports, plus the `content_relations` links.
 * Event- and report-specific columns live in ReportRepository / the `events`
 * table and are written through their own repositories.
 *
 * Publishing rules enforced here (the schema cannot express them portably):
 *   * publishing a row without an explicit published_at stamps "now";
 *   * moving a row back to draft clears published_at;
 *   * "public" always means status = 'published' AND published_at <= now.
 */
final class ContentRepository extends BaseRepository
{
    protected string $table = 'contents';

    /** Content types accepted by the schema's ENUM. */
    public const TYPES = ['article', 'news', 'event', 'report'];

    /** Publication states accepted by the schema's ENUM. */
    public const STATUSES = ['draft', 'published', 'archived'];

    /* -----------------------------------------------------------------
     | Reads
     * ----------------------------------------------------------------- */

    /**
     * Find one content row by type + slug (the public URL pair).
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $type, string $slug): ?array
    {
        $this->assertType($type);

        return db_one(
            'SELECT * FROM `contents` WHERE `content_type` = ? AND `slug` = ? LIMIT 1',
            [$type, $slug]
        );
    }

    /**
     * Find one PUBLISHED content row by type + slug (what the public site
     * is allowed to show).
     *
     * @return array<string,mixed>|null
     */
    public function findPublishedBySlug(string $type, string $slug): ?array
    {
        $this->assertType($type);

        return db_one(
            'SELECT * FROM `contents`
             WHERE `content_type` = ?
               AND `slug` = ?
               AND `status` = \'published\'
               AND `published_at` IS NOT NULL
               AND `published_at` <= ?
             LIMIT 1',
            [$type, $slug, $this->now()]
        );
    }

    /**
     * Published rows of one type, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function listPublished(string $type, int $limit = 10, int $offset = 0): array
    {
        $this->assertType($type);
        [$limit, $offset] = $this->paging($limit, $offset);

        return db_all(
            sprintf(
                'SELECT * FROM `contents`
                 WHERE `content_type` = ?
                   AND `status` = \'published\'
                   AND `published_at` IS NOT NULL
                   AND `published_at` <= ?
                 ORDER BY `published_at` DESC, `id` DESC
                 LIMIT %d OFFSET %d',
                $limit,
                $offset
            ),
            [$type, $this->now()]
        );
    }

    /**
     * Published rows of one topic (any content type), newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function listPublishedByTopic(int $topicId, int $limit = 10, int $offset = 0): array
    {
        [$limit, $offset] = $this->paging($limit, $offset);

        return db_all(
            sprintf(
                'SELECT * FROM `contents`
                 WHERE `topic_id` = ?
                   AND `status` = \'published\'
                   AND `published_at` IS NOT NULL
                   AND `published_at` <= ?
                 ORDER BY `published_at` DESC, `id` DESC
                 LIMIT %d OFFSET %d',
                $limit,
                $offset
            ),
            [$topicId, $this->now()]
        );
    }

    /**
     * Rows of one type in a given state — the admin/editor view (Phase 4+
     * will call this; Phase 2 only needs it for draft/published tests).
     *
     * @return list<array<string,mixed>>
     */
    public function listByStatus(string $type, string $status, int $limit = 20, int $offset = 0): array
    {
        $this->assertType($type);
        $this->assertStatus($status);
        [$limit, $offset] = $this->paging($limit, $offset);

        return db_all(
            sprintf(
                'SELECT * FROM `contents`
                 WHERE `content_type` = ? AND `status` = ?
                 ORDER BY `updated_at` DESC, `id` DESC
                 LIMIT %d OFFSET %d',
                $limit,
                $offset
            ),
            [$type, $status]
        );
    }

    /**
     * Count published rows of one type.
     */
    public function countPublished(string $type): int
    {
        $this->assertType($type);

        return (int) db_value(
            'SELECT COUNT(*) FROM `contents`
             WHERE `content_type` = ?
               AND `status` = \'published\'
               AND `published_at` IS NOT NULL
               AND `published_at` <= ?',
            [$type, $this->now()],
            0
        );
    }

    /**
     * True when the type+slug pair is already taken (optionally ignoring one
     * id, so an editor can keep a slug while editing a row).
     */
    public function slugExists(string $type, string $slug, ?int $ignoreId = null): bool
    {
        $this->assertType($type);

        if ($ignoreId === null) {
            return (int) db_value(
                'SELECT COUNT(*) FROM `contents` WHERE `content_type` = ? AND `slug` = ?',
                [$type, $slug],
                0
            ) > 0;
        }

        return (int) db_value(
            'SELECT COUNT(*) FROM `contents` WHERE `content_type` = ? AND `slug` = ? AND `id` <> ?',
            [$type, $slug, $ignoreId],
            0
        ) > 0;
    }

    /** Public listing: only published and already available rows. */
    public function publicList(string $type, int $limit = 12, int $offset = 0): array
    {
        $this->assertType($type); [$limit,$offset]=$this->paging($limit,$offset,50);
        return db_all(sprintf('SELECT c.*, t.title AS topic_title, t.slug AS topic_slug, m.disk_path AS cover_path, m.alt_text AS cover_alt FROM contents c LEFT JOIN topics t ON t.id=c.topic_id LEFT JOIN media m ON m.id=c.cover_media_id WHERE c.content_type=? AND c.status=\'published\' AND c.published_at IS NOT NULL AND c.published_at<=? ORDER BY c.published_at DESC,c.id DESC LIMIT %d OFFSET %d',$limit,$offset),[$type,date('Y-m-d H:i:s')]);
    }

    /**
     * One published content item by type + slug, with its topic and cover
     * media joined in. This is the public detail read.
     *
     * @return array<string,mixed>|null
     */
    public function findPublishedDetail(string $type, string $slug): ?array
    {
        $this->assertType($type);

        return db_one(
            'SELECT c.*, t.title AS topic_title, t.slug AS topic_slug,
                    m.disk_path AS cover_path, m.alt_text AS cover_alt, m.mime_type AS cover_mime
             FROM contents c
             LEFT JOIN topics t ON t.id = c.topic_id
             LEFT JOIN media m ON m.id = c.cover_media_id
             WHERE c.content_type = ? AND c.slug = ?
               AND c.status = \'published\'
               AND c.published_at IS NOT NULL
               AND c.published_at <= ?
             LIMIT 1',
            [$type, $slug, date('Y-m-d H:i:s')]
        );
    }
    public function publicCount(?string $type = null, ?int $topicId = null, string $query = ''): int
    {
        $where=["c.status='published'",'c.published_at IS NOT NULL','c.published_at<=?']; $params=[date('Y-m-d H:i:s')];
        if($type!==null){$this->assertType($type);$where[]='c.content_type=?';$params[]=$type;}
        if($topicId!==null){$where[]='c.topic_id=?';$params[]=$topicId;}
        if($query!==''){$where[]="(c.title LIKE ? ESCAPE '=' OR c.summary LIKE ? ESCAPE '=' OR c.body LIKE ? ESCAPE '=' OR t.title LIKE ? ESCAPE '=')";$q='%'.$this->escapeLike($query).'%';array_push($params,$q,$q,$q,$q);}
        return (int)db_value('SELECT COUNT(*) FROM contents c LEFT JOIN topics t ON t.id=c.topic_id WHERE '.implode(' AND ',$where),$params,0);
    }
    public function publicSearch(string $query, int $limit=12, int $offset=0): array
    {
        [$limit,$offset]=$this->paging($limit,$offset,50);$q='%'.$this->escapeLike($query).'%';
        return db_all(sprintf("SELECT c.*,t.title AS topic_title,t.slug AS topic_slug,m.disk_path AS cover_path,m.alt_text AS cover_alt FROM contents c LEFT JOIN topics t ON t.id=c.topic_id LEFT JOIN media m ON m.id=c.cover_media_id WHERE c.status='published' AND c.published_at IS NOT NULL AND c.published_at<=? AND (c.title LIKE ? ESCAPE '=' OR c.summary LIKE ? ESCAPE '=' OR c.body LIKE ? ESCAPE '=' OR t.title LIKE ? ESCAPE '=') ORDER BY c.published_at DESC,c.id DESC LIMIT %d OFFSET %d",$limit,$offset),[date('Y-m-d H:i:s'),$q,$q,$q,$q]);
    }
    public function publicByTopic(int $topicId,int $limit=12,int $offset=0): array
    {
        [$limit,$offset]=$this->paging($limit,$offset,50); return db_all(sprintf("SELECT c.*,t.title AS topic_title,t.slug AS topic_slug,m.disk_path AS cover_path,m.alt_text AS cover_alt FROM contents c JOIN topics t ON t.id=c.topic_id LEFT JOIN media m ON m.id=c.cover_media_id WHERE c.topic_id=? AND c.status='published' AND c.published_at IS NOT NULL AND c.published_at<=? ORDER BY c.published_at DESC,c.id DESC LIMIT %d OFFSET %d",$limit,$offset),[$topicId,date('Y-m-d H:i:s')]);
    }
    /** Published rows of one topic filtered by content type. */
    public function publicByTopicAndType(int $topicId,string $type,int $limit=12,int $offset=0): array
    {
        $this->assertType($type);[$limit,$offset]=$this->paging($limit,$offset,50); return db_all(sprintf("SELECT c.*,t.title AS topic_title,t.slug AS topic_slug,m.disk_path AS cover_path,m.alt_text AS cover_alt FROM contents c JOIN topics t ON t.id=c.topic_id LEFT JOIN media m ON m.id=c.cover_media_id WHERE c.topic_id=? AND c.content_type=? AND c.status='published' AND c.published_at IS NOT NULL AND c.published_at<=? ORDER BY c.published_at DESC,c.id DESC LIMIT %d OFFSET %d",$limit,$offset),[$topicId,$type,date('Y-m-d H:i:s')]);
    }
    /**
     * Escape LIKE wildcards so a user query is matched literally.
     *
     * Uses '=' as the escape character (declared with ESCAPE '=' in every
     * query), which is portable across MySQL/MariaDB and the SQLite test
     * backend — unlike a backslash, which SQLite does not treat specially
     * inside string literals. The escape char itself is escaped first.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }

    /** Admin registry query: all content with safe, whitelisted filters. */
    public function adminList(?string $type, ?string $status, string $search, int $limit = 20, int $offset = 0): array
    {
        [$limit, $offset] = $this->paging($limit, $offset, 100);
        $where = [];
        $params = [];
        if ($type !== null && $type !== '') { $this->assertType($type); $where[] = '`c`.`content_type` = ?'; $params[] = $type; }
        if ($status !== null && $status !== '') { $this->assertStatus($status); $where[] = '`c`.`status` = ?'; $params[] = $status; }
        if ($search !== '') { $where[] = '`c`.`title` LIKE ?'; $params[] = '%' . $search . '%'; }
        $sql = 'SELECT c.*, t.title AS topic_title FROM `contents` c LEFT JOIN `topics` t ON t.id=c.topic_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . sprintf(' ORDER BY c.updated_at DESC, c.id DESC LIMIT %d OFFSET %d', $limit, $offset);
        return db_all($sql, $params);
    }

    public function adminCount(?string $type, ?string $status, string $search): int
    {
        $where = []; $params = [];
        if ($type !== null && $type !== '') { $this->assertType($type); $where[] = '`content_type` = ?'; $params[] = $type; }
        if ($status !== null && $status !== '') { $this->assertStatus($status); $where[] = '`status` = ?'; $params[] = $status; }
        if ($search !== '') { $where[] = '`title` LIKE ?'; $params[] = '%' . $search . '%'; }
        return (int) db_value('SELECT COUNT(*) FROM `contents`' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params, 0);
    }

    /* -----------------------------------------------------------------
     | Writes
     * ----------------------------------------------------------------- */

    /**
     * Create a content row and return its id.
     *
     * @param array<string,mixed> $data content_type, slug, title, summary,
     *                                  body, topic_id, cover_media_id,
     *                                  status, published_at
     */
    public function create(array $data): int
    {
        $type = (string) ($data['content_type'] ?? '');
        $this->assertType($type);

        $status = (string) ($data['status'] ?? 'draft');
        $this->assertStatus($status);

        return db_insert('contents', [
            'content_type'   => $type,
            'topic_id'       => $data['topic_id'] ?? null,
            'slug'           => (string) ($data['slug'] ?? ''),
            'title'          => (string) ($data['title'] ?? ''),
            'summary'        => $data['summary'] ?? null,
            'body'           => $data['body'] ?? null,
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'status'         => $status,
            'published_at'   => $this->resolvePublishedAt($status, $data['published_at'] ?? null),
        ]);
    }

    /**
     * Update a content row; only the provided columns are written.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $allowed = [
            'topic_id', 'slug', 'title', 'summary', 'body',
            'cover_media_id', 'status', 'published_at',
        ];
        $payload = array_intersect_key($data, array_flip($allowed));

        if ($payload === []) {
            return false;
        }

        if (isset($payload['status'])) {
            $status = (string) $payload['status'];
            $this->assertStatus($status);
            $payload['published_at'] = $this->resolvePublishedAt(
                $status,
                $payload['published_at'] ?? $this->currentPublishedAt($id)
            );
        }

        return db_update('contents', $payload, ['id' => $id]) > 0;
    }

    /**
     * Publish a row (stamps published_at when it has none).
     */
    public function publish(int $id, ?string $publishedAt = null): bool
    {
        return db_update('contents', [
            'status'       => 'published',
            'published_at' => $publishedAt ?? ($this->currentPublishedAt($id) ?? date('Y-m-d H:i:s')),
        ], ['id' => $id]) > 0;
    }

    /**
     * Move a row back to draft (clears published_at).
     */
    public function unpublish(int $id): bool
    {
        return db_update('contents', [
            'status'       => 'draft',
            'published_at' => null,
        ], ['id' => $id]) > 0;
    }

    /* -----------------------------------------------------------------
     | Related content
     * ----------------------------------------------------------------- */

    /**
     * Link two content rows (directed). Re-linking updates the order.
     *
     * @throws InvalidArgumentException when a row is related to itself
     */
    public function relate(int $contentId, int $relatedContentId, int $sortOrder = 0): void
    {
        if ($contentId === $relatedContentId) {
            // Also blocked by chk_content_relations_not_self on servers that
            // enforce CHECK; enforced here for the ones that don't.
            throw new InvalidArgumentException('A content item cannot be related to itself.');
        }

        $exists = (int) db_value(
            'SELECT COUNT(*) FROM `content_relations`
             WHERE `content_id` = ? AND `related_content_id` = ?',
            [$contentId, $relatedContentId],
            0
        ) > 0;

        if ($exists) {
            db_update(
                'content_relations',
                ['sort_order' => $sortOrder],
                ['content_id' => $contentId, 'related_content_id' => $relatedContentId]
            );

            return;
        }

        db_insert('content_relations', [
            'content_id'         => $contentId,
            'related_content_id' => $relatedContentId,
            'sort_order'         => $sortOrder,
        ]);
    }

    /**
     * Remove a related-content link. True when a link was removed.
     */
    public function unrelate(int $contentId, int $relatedContentId): bool
    {
        return db_delete('content_relations', [
            'content_id'         => $contentId,
            'related_content_id' => $relatedContentId,
        ]) > 0;
    }

    /**
     * Published items related to a content row, in editor order.
     *
     * @return list<array<string,mixed>>
     */
    public function relatedPublished(int $contentId, int $limit = 5): array
    {
        [$limit] = $this->paging($limit, 0, 50);

        return db_all(
            sprintf(
                'SELECT c.*, r.`sort_order` AS `relation_order`,
                        t.`title` AS `topic_title`, t.`slug` AS `topic_slug`,
                        m.`disk_path` AS `cover_path`, m.`alt_text` AS `cover_alt`
                 FROM `content_relations` r
                 JOIN `contents` c ON c.`id` = r.`related_content_id`
                 LEFT JOIN `topics` t ON t.`id` = c.`topic_id`
                 LEFT JOIN `media` m ON m.`id` = c.`cover_media_id`
                 WHERE r.`content_id` = ?
                   AND c.`status` = \'published\'
                   AND c.`published_at` IS NOT NULL
                   AND c.`published_at` <= ?
                 ORDER BY r.`sort_order` ASC, c.`published_at` DESC
                 LIMIT %d',
                $limit
            ),
            [$contentId, $this->now()]
        );
    }

    /**
     * All related items (any status) — for the editor, not the public site.
     *
     * @return list<array<string,mixed>>
     */
    public function relatedAll(int $contentId): array
    {
        return db_all(
            'SELECT c.*, r.`sort_order` AS `relation_order`
             FROM `content_relations` r
             JOIN `contents` c ON c.`id` = r.`related_content_id`
             WHERE r.`content_id` = ?
             ORDER BY r.`sort_order` ASC, c.`id` ASC',
            [$contentId]
        );
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    /**
     * "Now" as a bound parameter instead of SQL NOW().
     *
     * The comparison then uses the application timezone (config app.timezone)
     * rather than the database server's, which is what an editor means by
     * "publish at 09:00" — and it keeps the SQL portable.
     */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Decide the published_at value for a given status.
     */
    private function resolvePublishedAt(string $status, mixed $publishedAt): ?string
    {
        if ($status !== 'published') {
            // draft/archived rows never keep a publication stamp
            return $status === 'archived' && is_string($publishedAt) && $publishedAt !== ''
                ? $publishedAt
                : null;
        }

        return is_string($publishedAt) && $publishedAt !== ''
            ? $publishedAt
            : date('Y-m-d H:i:s');
    }

    /**
     * Current published_at of a row, if any.
     */
    private function currentPublishedAt(int $id): ?string
    {
        $value = db_value('SELECT `published_at` FROM `contents` WHERE `id` = ?', [$id]);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown content type: ' . $type);
        }
    }

    private function assertStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown content status: ' . $status);
        }
    }
}
