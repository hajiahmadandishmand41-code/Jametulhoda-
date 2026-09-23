<?php

declare(strict_types=1);

/**
 * ReportRepository — Phase 2.
 *
 * Owns the report side of the schema: the `reports` extension row and its
 * `report_images` gallery (the blueprint's "reports support multiple
 * images"). The shared content columns stay in ContentRepository.
 *
 * Creating a report touches two tables, so it runs inside a transaction:
 * either both rows exist or neither does.
 */
final class ReportRepository extends BaseRepository
{
    protected string $table = 'reports';

    /**
     * Create a report: the shared `contents` row plus its `reports` row.
     *
     * @param array<string,mixed> $data slug, title, summary, body, topic_id,
     *                                  cover_media_id, status, published_at,
     *                                  event_date, location
     * @return int the new content id (reports share the content's id)
     */
    public function create(array $data): int
    {
        return db_transaction(static function () use ($data): int {
            $contents = new ContentRepository();
            $contentId = $contents->create($data + ['content_type' => 'report']);

            db_insert('reports', [
                'content_id'   => $contentId,
                'content_type' => 'report',
                'event_date'   => $data['event_date'] ?? null,
                'location'     => $data['location'] ?? null,
            ]);

            return $contentId;
        });
    }

    /**
     * A report with its shared content columns joined in.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $contentId): ?array
    {
        return db_one(
            'SELECT c.*, r.`event_date`, r.`location`
             FROM `reports` r
             JOIN `contents` c ON c.`id` = r.`content_id`
             WHERE r.`content_id` = ?
             LIMIT 1',
            [$contentId]
        );
    }

    /**
     * A report looked up by its public slug.
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return db_one(
            'SELECT c.*, r.`event_date`, r.`location`
             FROM `reports` r
             JOIN `contents` c ON c.`id` = r.`content_id`
             WHERE c.`slug` = ?
             LIMIT 1',
            [$slug]
        );
    }

    /**
     * Create or update the report-specific row for an existing content row.
     *
     * @param array<string,mixed> $data event_date, location
     */
    public function save(int $contentId, array $data): void
    {
        $payload = [
            'event_date' => trim((string) ($data['event_date'] ?? '')) ?: null,
            'location' => trim((string) ($data['location'] ?? '')) ?: null,
        ];
        $exists = (int) db_value('SELECT COUNT(*) FROM `reports` WHERE `content_id` = ?', [$contentId], 0) > 0;
        if ($exists) {
            db_update('reports', $payload, ['content_id' => $contentId]);
            return;
        }
        db_insert('reports', $payload + ['content_id' => $contentId, 'content_type' => 'report']);
    }

    /**
     * Update the report-specific columns.
     *
     * @param array<string,mixed> $data event_date, location
     */
    public function update(int $contentId, array $data): bool
    {
        $payload = array_intersect_key($data, array_flip(['event_date', 'location']));

        if ($payload === []) {
            return false;
        }

        return db_update('reports', $payload, ['content_id' => $contentId]) > 0;
    }

    /**
     * Delete a report by removing its parent content row; the foreign keys
     * cascade to `reports`, `report_images`, `content_media` and
     * `content_relations`, so nothing is left orphaned.
     */
    public function delete(int $contentId): bool
    {
        return db_delete('contents', ['id' => $contentId]) > 0;
    }

    /* -----------------------------------------------------------------
     | Gallery (report -> many images)
     * ----------------------------------------------------------------- */

    /**
     * Add an image to a report's gallery and return the gallery row id.
     * Adding the same image twice updates its caption/order instead of
     * failing on the unique key.
     *
     * @throws InvalidArgumentException when the media row is not an image
     */
    public function addImage(int $reportId, int $mediaId, ?string $caption = null, int $sortOrder = 0): int
    {
        $type = db_value('SELECT `media_type` FROM `media` WHERE `id` = ?', [$mediaId]);
        if ($type === null) {
            throw new InvalidArgumentException('Media not found: ' . $mediaId);
        }
        if ($type !== 'image') {
            // Also blocked by the composite FK + CHECK in the schema.
            throw new InvalidArgumentException('Only image media can be added to a report gallery.');
        }

        $existingId = db_value(
            'SELECT `id` FROM `report_images` WHERE `report_id` = ? AND `media_id` = ?',
            [$reportId, $mediaId]
        );

        if ($existingId !== null) {
            db_update(
                'report_images',
                ['caption' => $caption, 'sort_order' => $sortOrder],
                ['id' => (int) $existingId]
            );

            return (int) $existingId;
        }

        return db_insert('report_images', [
            'report_id'  => $reportId,
            'media_id'   => $mediaId,
            'media_type' => 'image',
            'caption'    => $caption,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * Remove one image from a report's gallery (the media row itself stays).
     */
    public function removeImage(int $reportId, int $mediaId): bool
    {
        return db_delete('report_images', [
            'report_id' => $reportId,
            'media_id'  => $mediaId,
        ]) > 0;
    }

    /**
     * A report's gallery, in display order, with the media metadata joined.
     *
     * @return list<array<string,mixed>>
     */
    public function images(int $reportId): array
    {
        return db_all(
            'SELECT ri.`id`, ri.`caption`, ri.`sort_order`,
                    m.`id` AS `media_id`, m.`disk_path`, m.`alt_text`, m.`title`, m.`mime_type`
             FROM `report_images` ri
             JOIN `media` m ON m.`id` = ri.`media_id`
             WHERE ri.`report_id` = ?
             ORDER BY ri.`sort_order` ASC, ri.`id` ASC',
            [$reportId]
        );
    }

    /**
     * How many images a report currently has.
     */
    public function imageCount(int $reportId): int
    {
        return (int) db_value(
            'SELECT COUNT(*) FROM `report_images` WHERE `report_id` = ?',
            [$reportId],
            0
        );
    }

    /**
     * Replace a report's whole gallery in one transaction.
     *
     * @param list<array{media_id:int,caption?:string|null,sort_order?:int}> $images
     */
    public function syncImages(int $reportId, array $images): void
    {
        db_transaction(function () use ($reportId, $images): void {
            db_delete('report_images', ['report_id' => $reportId]);

            $order = 0;
            foreach ($images as $image) {
                $this->addImage(
                    $reportId,
                    (int) $image['media_id'],
                    $image['caption'] ?? null,
                    (int) ($image['sort_order'] ?? ++$order)
                );
            }
        });
    }
}
