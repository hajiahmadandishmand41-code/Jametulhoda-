<?php

declare(strict_types=1);

/**
 * MediaRepository — Phase 2.
 *
 * Owns the `media` registry and the `content_media` link table, i.e. the
 * "contextual audio/video attached to a parent content item" relationship
 * from the blueprint. Upload handling and admin UI belong to later phases;
 * this layer only stores and reads the rows.
 */
final class MediaRepository extends BaseRepository
{
    protected string $table = 'media';

    /** Media types accepted by the schema's ENUM. */
    public const TYPES = ['image', 'audio', 'video', 'document'];

    /** Roles a media row can play on a content item. */
    public const ROLES = ['attachment', 'audio', 'video', 'document'];

    /**
     * Register an uploaded file and return its media id.
     *
     * @param array<string,mixed> $data media_type, disk_path, original_name,
     *                                  mime_type, file_size, title, alt_text,
     *                                  duration_seconds
     */
    public function create(array $data): int
    {
        $type = (string) ($data['media_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown media type: ' . $type);
        }

        return db_insert('media', [
            'media_type'       => $type,
            'disk_path'        => (string) ($data['disk_path'] ?? ''),
            'original_name'    => $data['original_name'] ?? null,
            'mime_type'        => $data['mime_type'] ?? null,
            'file_size'        => isset($data['file_size']) ? (int) $data['file_size'] : null,
            'title'            => $data['title'] ?? null,
            'alt_text'         => $data['alt_text'] ?? null,
            'duration_seconds' => isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
        ]);
    }

    /**
     * Find a media row by its unique storage path.
     *
     * @return array<string,mixed>|null
     */
    public function findByPath(string $diskPath): ?array
    {
        return db_one('SELECT * FROM `media` WHERE `disk_path` = ? LIMIT 1', [$diskPath]);
    }

    /**
     * Newest media rows of one type.
     *
     * @return list<array<string,mixed>>
     */
    public function listByType(string $type, int $limit = 20, int $offset = 0): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown media type: ' . $type);
        }
        [$limit, $offset] = $this->paging($limit, $offset);

        return db_all(
            sprintf(
                'SELECT * FROM `media`
                 WHERE `media_type` = ?
                 ORDER BY `created_at` DESC, `id` DESC
                 LIMIT %d OFFSET %d',
                $limit,
                $offset
            ),
            [$type]
        );
    }

    /**
     * Attach a media row to a content item (idempotent: re-attaching updates
     * the role/order instead of failing on the primary key).
     */
    public function attachToContent(int $contentId, int $mediaId, string $role = 'attachment', int $sortOrder = 0): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Unknown media role: ' . $role);
        }

        $exists = (int) db_value(
            'SELECT COUNT(*) FROM `content_media` WHERE `content_id` = ? AND `media_id` = ?',
            [$contentId, $mediaId],
            0
        ) > 0;

        if ($exists) {
            db_update(
                'content_media',
                ['role' => $role, 'sort_order' => $sortOrder],
                ['content_id' => $contentId, 'media_id' => $mediaId]
            );

            return;
        }

        db_insert('content_media', [
            'content_id' => $contentId,
            'media_id'   => $mediaId,
            'role'       => $role,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * Detach a media row from a content item. True when a link was removed.
     */
    public function detachFromContent(int $contentId, int $mediaId): bool
    {
        return db_delete('content_media', [
            'content_id' => $contentId,
            'media_id'   => $mediaId,
        ]) > 0;
    }

    /**
     * Every media row attached to a content item, in display order.
     *
     * @param string|null $role optional filter ('audio', 'video', ...)
     * @return list<array<string,mixed>>
     */
    public function forContent(int $contentId, ?string $role = null): array
    {
        if ($role !== null && !in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Unknown media role: ' . $role);
        }

        $sql = 'SELECT m.*, cm.`role`, cm.`sort_order`
                FROM `content_media` cm
                JOIN `media` m ON m.`id` = cm.`media_id`
                WHERE cm.`content_id` = ?';
        $params = [$contentId];

        if ($role !== null) {
            $sql .= ' AND cm.`role` = ?';
            $params[] = $role;
        }

        $sql .= ' ORDER BY cm.`sort_order` ASC, m.`id` ASC';

        return db_all($sql, $params);
    }

    /* -----------------------------------------------------------------
     | Phase 6 — public multimedia hub (/media)
     |
     | A media row is only PUBLIC when it is attached to at least one
     | PUBLISHED content item. Attachments of login-required lessons are
     | withheld from guests, so the hub can never become a back door
     | around the lesson access rule.
     * ----------------------------------------------------------------- */

    /**
     * One page of the public multimedia hub, newest first.
     *
     * @param string|null $type  'video' | 'audio' | null = both
     * @param bool        $viewerAuthenticated false = hide lesson-locked media
     * @return list<array<string,mixed>>
     */
    public function publicHubList(?string $type, bool $viewerAuthenticated, int $limit = 12, int $offset = 0): array
    {
        [$limit, $offset] = $this->paging($limit, $offset);
        [$where, $params] = $this->hubFilter($type, $viewerAuthenticated);

        return db_all(
            sprintf(
                'SELECT m.*, MIN(cm.`content_id`) AS `primary_content_id`
                 FROM `media` m
                 JOIN `content_media` cm ON cm.`media_id` = m.`id`
                 JOIN `contents` c ON c.`id` = cm.`content_id`
                 LEFT JOIN `lessons` l ON l.`content_id` = c.`id` AND c.`content_type` = \'lesson\'
                 WHERE %s
                 GROUP BY m.`id`, m.`media_type`, m.`disk_path`, m.`original_name`, m.`mime_type`,
                          m.`file_size`, m.`title`, m.`alt_text`, m.`duration_seconds`,
                          m.`created_at`, m.`updated_at`
                 ORDER BY m.`created_at` DESC, m.`id` DESC
                 LIMIT %d OFFSET %d',
                implode(' AND ', $where),
                $limit,
                $offset
            ),
            $params
        );
    }

    /**
     * Count of distinct public hub rows honouring the same filters as
     * publicHubList().
     */
    public function publicHubCount(?string $type, bool $viewerAuthenticated): int
    {
        [$where, $params] = $this->hubFilter($type, $viewerAuthenticated);

        return (int) db_value(
            'SELECT COUNT(DISTINCT m.`id`)
             FROM `media` m
             JOIN `content_media` cm ON cm.`media_id` = m.`id`
             JOIN `contents` c ON c.`id` = cm.`content_id`
             LEFT JOIN `lessons` l ON l.`content_id` = c.`id` AND c.`content_type` = \'lesson\'
             WHERE ' . implode(' AND ', $where),
            $params,
            0
        );
    }

    /**
     * The PUBLISHED content items a set of media rows is attached to —
     * the hub's "related content". One query for the whole page (no
     * per-card extra queries); guests never receive links into a
     * login-required lesson.
     *
     * @param list<int> $mediaIds
     * @return array<int, list<array<string,mixed>>> media_id => contents
     */
    public function publishedContentsForMedia(array $mediaIds, bool $viewerAuthenticated, int $perMedia = 3): array
    {
        $mediaIds = array_values(array_filter(array_map('intval', $mediaIds), static fn (int $id): bool => $id > 0));
        if ($mediaIds === []) {
            return [];
        }

        $perMedia = max(1, min($perMedia, 10));
        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
        // Application-computed boolean (never request data), inlined as a
        // literal: see hubFilter() for the driver-affinity rationale.
        $authFlag = $viewerAuthenticated ? '1' : '0';
        $sql = 'SELECT cm.`media_id`, c.`id`, c.`content_type`, c.`slug`, c.`title`, c.`published_at`
                 FROM `content_media` cm
                 JOIN `contents` c ON c.`id` = cm.`content_id`
                 LEFT JOIN `lessons` l ON l.`content_id` = c.`id` AND c.`content_type` = \'lesson\'
                 WHERE cm.`media_id` IN (%s)
                   AND c.`status` = \'published\'
                   AND c.`published_at` IS NOT NULL
                   AND c.`published_at` <= ?
                   AND (l.`content_id` IS NULL OR l.`requires_login` = 0 OR ' . $authFlag . ' = 1)
                 ORDER BY cm.`media_id` ASC, c.`published_at` DESC, c.`id` DESC
                 LIMIT %d';
        $rows = db_all(
            sprintf($sql, $placeholders, count($mediaIds) * $perMedia),
            array_merge($mediaIds, [date('Y-m-d H:i:s')])
        );

        $grouped = [];
        foreach ($rows as $row) {
            $mediaId = (int) $row['media_id'];
            $grouped[$mediaId] = $grouped[$mediaId] ?? [];
            if (count($grouped[$mediaId]) < $perMedia) {
                $grouped[$mediaId][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * Shared WHERE builder for the hub queries.
     *
     * @return array{0:list<string>,1:list<mixed>}
     */
    private function hubFilter(?string $type, bool $viewerAuthenticated): array
    {
        if ($type !== null && !in_array($type, ['video', 'audio'], true)) {
            throw new InvalidArgumentException('Unknown hub media type: ' . $type);
        }

        // Application-computed boolean (never request data), inlined as a
        // literal: a bound parameter compares as a string on some drivers
        // (SQLite testing backend), which would silently break `? = 1`.
        $authFlag = $viewerAuthenticated ? '1' : '0';

        $where = [
            'm.`media_type` IN (\'video\', \'audio\')',
            'c.`status` = \'published\'',
            'c.`published_at` IS NOT NULL',
            'c.`published_at` <= ?',
            "(l.`content_id` IS NULL OR l.`requires_login` = 0 OR {$authFlag} = 1)",
        ];
        $params = [date('Y-m-d H:i:s')];

        if ($type !== null) {
            $where[] = 'm.`media_type` = ?';
            $params[] = $type;
        }

        return [$where, $params];
    }
}
