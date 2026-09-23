-- =============================================================
-- seed.sql — MINIMAL test/development data (Phase 2)
--
-- !! TEST / DEVELOPMENT DATA ONLY — DO NOT RUN IN PRODUCTION !!
--
-- Purpose: give the automated tests and local development just enough
-- rows to exercise every relationship and query path once:
--     * a topic with one sub-topic
--     * one published article, one published news item,
--       one published event, one published report
--     * one draft article (so draft/published filtering is testable)
--     * one image, one audio and one video media row
--     * a report gallery with two images
--     * contextual audio attached to the article and video to the news
--     * one related-content pair
-- Nothing more: no filler text, no fake archive.
--
-- Every row is recognisable as test data by its `test-` slug prefix or
-- `dev/` media path, so it can be removed with the cleanup block at the
-- bottom of this file.
--
-- Install (after schema.sql):
--     mysql -u USER -p DATABASE < database/seed.sql
--
-- Re-run behaviour: INSERT ... ON DUPLICATE KEY UPDATE keeps the rows
-- at their seeded values, so running this file twice does not create
-- duplicates and does not fail.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- Topics
-- -------------------------------------------------------------
INSERT INTO `topics` (`slug`, `title`, `description`, `sort_order`, `is_active`) VALUES
    ('test-eteqadi', 'اعتقادی', 'موضوع آزمایشی برای توسعه و تست.', 1, 1),
    ('test-akhlaqi', 'اخلاقی', 'موضوع آزمایشی غیرفعال برای تست فیلتر is_active.', 2, 0)
ON DUPLICATE KEY UPDATE
    `title` = VALUES(`title`),
    `description` = VALUES(`description`),
    `sort_order` = VALUES(`sort_order`),
    `is_active` = VALUES(`is_active`);

-- A sub-topic, to exercise topics.parent_id
INSERT INTO `topics` (`parent_id`, `slug`, `title`, `description`, `sort_order`, `is_active`)
SELECT `id`, 'test-tafsir', 'تفسیر', 'زیرموضوع آزمایشی.', 1, 1
FROM `topics` WHERE `slug` = 'test-eteqadi'
ON DUPLICATE KEY UPDATE
    `parent_id` = VALUES(`parent_id`),
    `title` = VALUES(`title`);

-- -------------------------------------------------------------
-- Media (paths point at dev/ so they are obviously not real uploads)
-- -------------------------------------------------------------
INSERT INTO `media` (`media_type`, `disk_path`, `original_name`, `mime_type`, `file_size`, `title`, `alt_text`, `duration_seconds`) VALUES
    ('image', 'uploads/images/dev/test-cover.jpg',   'test-cover.jpg',   'image/jpeg', 102400, 'تصویر آزمایشی جلد',  'تصویر آزمایشی', NULL),
    ('image', 'uploads/images/dev/test-report-1.jpg','test-report-1.jpg','image/jpeg', 204800, 'تصویر اول گزارش',    'نمای اول',      NULL),
    ('image', 'uploads/images/dev/test-report-2.jpg','test-report-2.jpg','image/jpeg', 215040, 'تصویر دوم گزارش',    'نمای دوم',      NULL),
    ('audio', 'uploads/audio/dev/test-lecture.mp3',  'test-lecture.mp3', 'audio/mpeg', 512000, 'سخنرانی آزمایشی',    NULL,            360),
    ('video', 'uploads/video/dev/test-clip.mp4',     'test-clip.mp4',    'video/mp4',  1048576,'کلیپ آزمایشی',       NULL,            120)
ON DUPLICATE KEY UPDATE
    `media_type` = VALUES(`media_type`),
    `original_name` = VALUES(`original_name`),
    `mime_type` = VALUES(`mime_type`),
    `file_size` = VALUES(`file_size`),
    `title` = VALUES(`title`),
    `alt_text` = VALUES(`alt_text`),
    `duration_seconds` = VALUES(`duration_seconds`);

-- -------------------------------------------------------------
-- Contents — one published row per type, plus one draft
-- -------------------------------------------------------------
INSERT INTO `contents` (`content_type`, `topic_id`, `slug`, `title`, `summary`, `body`, `cover_media_id`, `status`, `published_at`)
SELECT
    'article',
    (SELECT `id` FROM `topics` WHERE `slug` = 'test-eteqadi'),
    'test-maqale-nemune',
    'مقاله‌ی نمونه‌ی آزمایشی',
    'خلاصه‌ی کوتاه مقاله‌ی آزمایشی برای بررسی نمایش و جست‌وجو.',
    'متن کامل مقاله‌ی آزمایشی. این محتوا فقط برای توسعه و تست است و محتوای واقعی سایت نیست.',
    (SELECT `id` FROM `media` WHERE `disk_path` = 'uploads/images/dev/test-cover.jpg'),
    'published',
    '2026-01-10 09:00:00'
ON DUPLICATE KEY UPDATE
    `title` = VALUES(`title`),
    `summary` = VALUES(`summary`),
    `body` = VALUES(`body`),
    `topic_id` = VALUES(`topic_id`),
    `cover_media_id` = VALUES(`cover_media_id`),
    `status` = VALUES(`status`),
    `published_at` = VALUES(`published_at`);

INSERT INTO `contents` (`content_type`, `topic_id`, `slug`, `title`, `summary`, `body`, `status`, `published_at`)
SELECT
    'article',
    (SELECT `id` FROM `topics` WHERE `slug` = 'test-eteqadi'),
    'test-maqale-pishnevis',
    'پیش‌نویس آزمایشی',
    'این مقاله پیش‌نویس است و نباید در فهرست عمومی دیده شود.',
    'متن پیش‌نویس آزمایشی.',
    'draft',
    NULL
ON DUPLICATE KEY UPDATE
    `title` = VALUES(`title`),
    `summary` = VALUES(`summary`),
    `status` = VALUES(`status`),
    `published_at` = VALUES(`published_at`);

INSERT INTO `contents` (`content_type`, `topic_id`, `slug`, `title`, `summary`, `body`, `status`, `published_at`)
SELECT
    'news',
    (SELECT `id` FROM `topics` WHERE `slug` = 'test-eteqadi'),
    'test-khabar-nemune',
    'خبر نمونه‌ی آزمایشی',
    'خلاصه‌ی خبر آزمایشی.',
    'متن کامل خبر آزمایشی. فقط برای تست.',
    'published',
    '2026-02-05 12:30:00'
ON DUPLICATE KEY UPDATE
    `title` = VALUES(`title`),
    `summary` = VALUES(`summary`),
    `status` = VALUES(`status`),
    `published_at` = VALUES(`published_at`);

INSERT INTO `contents` (`content_type`, `topic_id`, `slug`, `title`, `summary`, `body`, `status`, `published_at`)
SELECT
    'event',
    (SELECT `id` FROM `topics` WHERE `slug` = 'test-eteqadi'),
    'test-ruydad-nemune',
    'رویداد نمونه‌ی آزمایشی',
    'خلاصه‌ی رویداد آزمایشی.',
    'توضیح رویداد آزمایشی. فقط برای تست.',
    'published',
    '2026-03-01 08:00:00'
ON DUPLICATE KEY UPDATE
    `title` = VALUES(`title`),
    `summary` = VALUES(`summary`),
    `status` = VALUES(`status`),
    `published_at` = VALUES(`published_at`);

INSERT INTO `contents` (`content_type`, `topic_id`, `slug`, `title`, `summary`, `body`, `status`, `published_at`)
SELECT
    'report',
    (SELECT `id` FROM `topics` WHERE `slug` = 'test-eteqadi'),
    'test-gozaresh-nemune',
    'گزارش نمونه‌ی آزمایشی',
    'خلاصه‌ی گزارش آزمایشی با چند تصویر.',
    'متن گزارش آزمایشی. فقط برای تست روابط تصاویر.',
    'published',
    '2026-03-10 18:45:00'
ON DUPLICATE KEY UPDATE
    `title` = VALUES(`title`),
    `summary` = VALUES(`summary`),
    `status` = VALUES(`status`),
    `published_at` = VALUES(`published_at`);

-- -------------------------------------------------------------
-- Type-specific extensions
-- -------------------------------------------------------------
INSERT INTO `events` (`content_id`, `content_type`, `starts_at`, `ends_at`, `location`)
SELECT `id`, 'event', '2026-03-20 09:00:00', '2026-03-20 12:00:00', 'سالن اجتماعات (آزمایشی)'
FROM `contents` WHERE `content_type` = 'event' AND `slug` = 'test-ruydad-nemune'
ON DUPLICATE KEY UPDATE
    `starts_at` = VALUES(`starts_at`),
    `ends_at` = VALUES(`ends_at`),
    `location` = VALUES(`location`);

INSERT INTO `reports` (`content_id`, `content_type`, `event_date`, `location`)
SELECT `id`, 'report', '2026-03-09', 'محل برگزاری (آزمایشی)'
FROM `contents` WHERE `content_type` = 'report' AND `slug` = 'test-gozaresh-nemune'
ON DUPLICATE KEY UPDATE
    `event_date` = VALUES(`event_date`),
    `location` = VALUES(`location`);

-- -------------------------------------------------------------
-- Report gallery — proves a report can carry MANY images
-- -------------------------------------------------------------
INSERT INTO `report_images` (`report_id`, `media_id`, `media_type`, `caption`, `sort_order`)
SELECT r.`content_id`, m.`id`, 'image', 'تصویر اول گزارش آزمایشی', 1
FROM `reports` r
JOIN `contents` c ON c.`id` = r.`content_id` AND c.`slug` = 'test-gozaresh-nemune'
JOIN `media` m ON m.`disk_path` = 'uploads/images/dev/test-report-1.jpg'
ON DUPLICATE KEY UPDATE
    `caption` = VALUES(`caption`),
    `sort_order` = VALUES(`sort_order`);

INSERT INTO `report_images` (`report_id`, `media_id`, `media_type`, `caption`, `sort_order`)
SELECT r.`content_id`, m.`id`, 'image', 'تصویر دوم گزارش آزمایشی', 2
FROM `reports` r
JOIN `contents` c ON c.`id` = r.`content_id` AND c.`slug` = 'test-gozaresh-nemune'
JOIN `media` m ON m.`disk_path` = 'uploads/images/dev/test-report-2.jpg'
ON DUPLICATE KEY UPDATE
    `caption` = VALUES(`caption`),
    `sort_order` = VALUES(`sort_order`);

-- -------------------------------------------------------------
-- Contextual media — audio on the article, video on the news item
-- (this is how audio/video reach the site: attached, not standalone)
-- -------------------------------------------------------------
INSERT INTO `content_media` (`content_id`, `media_id`, `role`, `sort_order`)
SELECT c.`id`, m.`id`, 'audio', 1
FROM `contents` c
JOIN `media` m ON m.`disk_path` = 'uploads/audio/dev/test-lecture.mp3'
WHERE c.`content_type` = 'article' AND c.`slug` = 'test-maqale-nemune'
ON DUPLICATE KEY UPDATE
    `role` = VALUES(`role`),
    `sort_order` = VALUES(`sort_order`);

INSERT INTO `content_media` (`content_id`, `media_id`, `role`, `sort_order`)
SELECT c.`id`, m.`id`, 'video', 1
FROM `contents` c
JOIN `media` m ON m.`disk_path` = 'uploads/video/dev/test-clip.mp4'
WHERE c.`content_type` = 'news' AND c.`slug` = 'test-khabar-nemune'
ON DUPLICATE KEY UPDATE
    `role` = VALUES(`role`),
    `sort_order` = VALUES(`sort_order`);

-- -------------------------------------------------------------
-- Related content — article -> news
-- -------------------------------------------------------------
INSERT INTO `content_relations` (`content_id`, `related_content_id`, `sort_order`)
SELECT a.`id`, n.`id`, 1
FROM `contents` a
JOIN `contents` n ON n.`content_type` = 'news' AND n.`slug` = 'test-khabar-nemune'
WHERE a.`content_type` = 'article' AND a.`slug` = 'test-maqale-nemune'
ON DUPLICATE KEY UPDATE `sort_order` = VALUES(`sort_order`);

-- =============================================================
-- Cleanup (removes ONLY this file's test data).
-- Foreign keys cascade, so deleting the content and media rows also
-- clears events, reports, report_images, content_media and
-- content_relations.
--
--   DELETE FROM `contents` WHERE `slug` LIKE 'test-%';
--   DELETE FROM `media`    WHERE `disk_path` LIKE 'uploads/%/dev/%';
--   DELETE FROM `topics`   WHERE `slug` LIKE 'test-%';
-- =============================================================
