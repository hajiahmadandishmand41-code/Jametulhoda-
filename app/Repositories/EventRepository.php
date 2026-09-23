<?php

declare(strict_types=1);

/**
 * EventRepository — Phase 2.
 *
 * Owns the `events` extension table (dates and location); the shared content
 * columns stay in ContentRepository. Creating an event writes two tables, so
 * it runs inside a transaction.
 */
final class EventRepository extends BaseRepository
{
    protected string $table = 'events';

    /**
     * Create an event: the shared `contents` row plus its `events` row.
     *
     * @param array<string,mixed> $data slug, title, summary, body, topic_id,
     *                                  status, published_at, starts_at,
     *                                  ends_at, location
     * @return int the new content id (events share the content's id)
     */
    public function create(array $data): int
    {
        $startsAt = (string) ($data['starts_at'] ?? '');
        if ($startsAt === '') {
            throw new InvalidArgumentException('An event needs a start date (starts_at).');
        }

        $endsAt = $data['ends_at'] ?? null;
        if (is_string($endsAt) && $endsAt !== '' && $endsAt < $startsAt) {
            // Also blocked by chk_events_date_range on servers enforcing CHECK.
            throw new InvalidArgumentException('An event cannot end before it starts.');
        }

        return db_transaction(static function () use ($data, $startsAt, $endsAt): int {
            $contents = new ContentRepository();
            $contentId = $contents->create($data + ['content_type' => 'event']);

            db_insert('events', [
                'content_id'   => $contentId,
                'content_type' => 'event',
                'starts_at'    => $startsAt,
                'ends_at'      => $endsAt !== '' ? $endsAt : null,
                'location'     => $data['location'] ?? null,
            ]);

            return $contentId;
        });
    }

    /**
     * An event with its shared content columns joined in.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $contentId): ?array
    {
        return db_one(
            'SELECT c.*, e.`starts_at`, e.`ends_at`, e.`location`
             FROM `events` e
             JOIN `contents` c ON c.`id` = e.`content_id`
             WHERE e.`content_id` = ?
             LIMIT 1',
            [$contentId]
        );
    }

    /**
     * An event looked up by its public slug.
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return db_one(
            'SELECT c.*, e.`starts_at`, e.`ends_at`, e.`location`
             FROM `events` e
             JOIN `contents` c ON c.`id` = e.`content_id`
             WHERE c.`slug` = ?
             LIMIT 1',
            [$slug]
        );
    }

    /**
     * Published events that have not started yet, soonest first.
     *
     * @return list<array<string,mixed>>
     */
    public function upcoming(int $limit = 10, int $offset = 0): array
    {
        [$limit, $offset] = $this->paging($limit, $offset);
        $now = date('Y-m-d H:i:s');

        return db_all(
            sprintf(
                'SELECT c.*, e.`starts_at`, e.`ends_at`, e.`location`
                 FROM `events` e
                 JOIN `contents` c ON c.`id` = e.`content_id`
                 WHERE c.`status` = \'published\'
                   AND c.`published_at` IS NOT NULL
                   AND c.`published_at` <= ?
                   AND e.`starts_at` >= ?
                 ORDER BY e.`starts_at` ASC
                 LIMIT %d OFFSET %d',
                $limit,
                $offset
            ),
            [$now, $now]
        );
    }

    /**
     * Published events that already happened, most recent first.
     *
     * @return list<array<string,mixed>>
     */
    public function past(int $limit = 10, int $offset = 0): array
    {
        [$limit, $offset] = $this->paging($limit, $offset);
        $now = date('Y-m-d H:i:s');

        return db_all(
            sprintf(
                'SELECT c.*, e.`starts_at`, e.`ends_at`, e.`location`
                 FROM `events` e
                 JOIN `contents` c ON c.`id` = e.`content_id`
                 WHERE c.`status` = \'published\'
                   AND c.`published_at` IS NOT NULL
                   AND c.`published_at` <= ?
                   AND e.`starts_at` < ?
                 ORDER BY e.`starts_at` DESC
                 LIMIT %d OFFSET %d',
                $limit,
                $offset
            ),
            [$now, $now]
        );
    }

    /**
     * Create or update the event-specific row for an existing content row.
     *
     * @param array<string,mixed> $data starts_at, ends_at, location
     */
    public function save(int $contentId, array $data): void
    {
        $startsAt = (string) ($data['starts_at'] ?? '');
        if ($startsAt === '') {
            throw new InvalidArgumentException('زمان شروع رویداد الزامی است.');
        }
        $endsAt = (string) ($data['ends_at'] ?? '');
        if ($endsAt !== '' && $endsAt < $startsAt) {
            throw new InvalidArgumentException('زمان پایان نمی‌تواند قبل از شروع باشد.');
        }
        $payload = [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt !== '' ? $endsAt : null,
            'location' => trim((string) ($data['location'] ?? '')) ?: null,
        ];
        $exists = (int) db_value('SELECT COUNT(*) FROM `events` WHERE `content_id` = ?', [$contentId], 0) > 0;
        if ($exists) {
            db_update('events', $payload, ['content_id' => $contentId]);
            return;
        }
        db_insert('events', $payload + ['content_id' => $contentId, 'content_type' => 'event']);
    }

    /**
     * Update the event-specific columns.
     *
     * @param array<string,mixed> $data starts_at, ends_at, location
     */
    public function update(int $contentId, array $data): bool
    {
        $payload = array_intersect_key($data, array_flip(['starts_at', 'ends_at', 'location']));

        if ($payload === []) {
            return false;
        }

        return db_update('events', $payload, ['content_id' => $contentId]) > 0;
    }

    /**
     * Delete an event by removing its parent content row; foreign keys
     * cascade to `events`, `content_media` and `content_relations`.
     */
    public function delete(int $contentId): bool
    {
        return db_delete('contents', ['id' => $contentId]) > 0;
    }
}
