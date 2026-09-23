<?php

declare(strict_types=1);

/**
 * TopicRepository — Phase 2.
 *
 * Read/write access to the shared taxonomy (`topics`). Kept to what the
 * schema and its tests actually need; listing UI and admin CRUD belong to
 * later phases.
 */
final class TopicRepository extends BaseRepository
{
    protected string $table = 'topics';

    /**
     * Find a topic by its slug.
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return db_one('SELECT * FROM `topics` WHERE `slug` = ? LIMIT 1', [$slug]);
    }

    /**
     * Active topics in display order.
     *
     * @return list<array<string,mixed>>
     */
    public function allActive(): array
    {
        return db_all(
            'SELECT * FROM `topics`
             WHERE `is_active` = 1
             ORDER BY `sort_order` ASC, `title` ASC'
        );
    }

    /**
     * Direct children of a topic, in display order.
     *
     * @return list<array<string,mixed>>
     */
    public function children(int $parentId): array
    {
        return db_all(
            'SELECT * FROM `topics`
             WHERE `parent_id` = ?
             ORDER BY `sort_order` ASC, `title` ASC',
            [$parentId]
        );
    }

    /**
     * Create a topic and return its id.
     *
     * @param array<string,mixed> $data slug, title, description, parent_id,
     *                                  sort_order, is_active
     */
    public function create(array $data): int
    {
        return db_insert('topics', [
            'parent_id'   => $data['parent_id'] ?? null,
            'slug'        => (string) ($data['slug'] ?? ''),
            'title'       => (string) ($data['title'] ?? ''),
            'description' => $data['description'] ?? null,
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'is_active'   => (int) (bool) ($data['is_active'] ?? true),
        ]);
    }

    /**
     * Update a topic; only the provided columns are written.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $allowed = ['parent_id', 'slug', 'title', 'description', 'sort_order', 'is_active'];
        $payload = array_intersect_key($data, array_flip($allowed));

        if ($payload === []) {
            return false;
        }

        return db_update('topics', $payload, ['id' => $id]) > 0;
    }

    /**
     * True when the slug is already taken (optionally ignoring one id).
     */
    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        if ($ignoreId === null) {
            return (int) db_value('SELECT COUNT(*) FROM `topics` WHERE `slug` = ?', [$slug], 0) > 0;
        }

        return (int) db_value(
            'SELECT COUNT(*) FROM `topics` WHERE `slug` = ? AND `id` <> ?',
            [$slug, $ignoreId],
            0
        ) > 0;
    }
}
