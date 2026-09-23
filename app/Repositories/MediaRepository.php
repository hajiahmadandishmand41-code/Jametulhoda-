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
}
