-- =============================================================
-- schema.sql — single source of the database structure
-- Phase 2: content schema (topics, contents, events, reports,
--          report images, media, content/media + related links).
--
-- Target:   MySQL 5.7+ / MariaDB 10.2+ (shared hosting, InfinityFree)
-- Engine:   InnoDB (transactions + real foreign keys)
-- Charset:  utf8mb4 / utf8mb4_unicode_ci (full Persian support)
--
-- Install (the database itself must already exist — shared hosting
-- panels create it for you):
--     mysql -u USER -p DATABASE < database/schema.sql
--
-- Re-run behaviour (intentional and documented):
--   * every statement is CREATE TABLE IF NOT EXISTS, so running this
--     file twice against the same database is a safe no-op: nothing is
--     dropped and no data is truncated;
--   * this is NOT a migration tool. It creates missing tables only; it
--     never alters a table that already exists. Structural changes to
--     an installed database need their own documented migration plan
--     (see docs/DATABASE.md).
--
-- Table order matters: every table is created after the tables it
-- references, so the foreign keys install cleanly without ever
-- disabling foreign_key_checks:
--     topics -> media -> contents -> events -> reports
--            -> report_images -> content_media -> content_relations
--
-- CHECK constraints are enforced by MySQL 8.0.16+ and MariaDB 10.2+.
-- Older servers parse and ignore them, so the data layer
-- (app/Repositories) enforces the same rules in PHP as well.
--
-- Rule: apply schema changes here only — never edit the live database
-- structure by hand.
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- topics — the shared taxonomy used by every content type.
-- parent_id allows one optional level of sub-topics later without a
-- migration; it is nullable and self-referencing. Removing a parent
-- lifts its children to the root (SET NULL) instead of deleting them.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `topics` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `parent_id`   INT UNSIGNED  NULL DEFAULT NULL,
    `slug`        VARCHAR(160)  NOT NULL,
    `title`       VARCHAR(160)  NOT NULL,
    `description` VARCHAR(500)  NULL DEFAULT NULL,
    `sort_order`  INT           NOT NULL DEFAULT 0,
    `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_topics_slug` (`slug`),
    KEY `idx_topics_parent` (`parent_id`),
    KEY `idx_topics_active_order` (`is_active`, `sort_order`),
    CONSTRAINT `fk_topics_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `topics` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- media — one registry row per uploaded file.
-- Audio and video are deliberately NOT standalone site sections: they
-- live here and reach the public site by being attached to a parent
-- content row through `content_media`.
-- UNIQUE (id, media_type) is what lets child tables demand a specific
-- media type through a composite foreign key (see report_images).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `media` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `media_type`       ENUM('image','audio','video','document') NOT NULL,
    `disk_path`        VARCHAR(255)    NOT NULL,
    `original_name`    VARCHAR(255)    NULL DEFAULT NULL,
    `mime_type`        VARCHAR(120)    NULL DEFAULT NULL,
    `file_size`        BIGINT UNSIGNED NULL DEFAULT NULL,
    `title`            VARCHAR(250)    NULL DEFAULT NULL,
    `alt_text`         VARCHAR(250)    NULL DEFAULT NULL,
    `duration_seconds` INT UNSIGNED    NULL DEFAULT NULL,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_media_disk_path` (`disk_path`),
    UNIQUE KEY `uq_media_id_type` (`id`, `media_type`),
    KEY `idx_media_type_created` (`media_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- contents — the shared spine of every public content item.
--
-- `content_type` separates articles, news, events and reports. Every
-- field they have in common (slug, title, summary, body, status,
-- published_at, topic, cover image) is stored here exactly once, so
-- media attachments, related content and search all hang on ONE real
-- foreign key instead of an unenforceable polymorphic reference.
-- Type-specific fields live in the 1:1 extension tables `events` and
-- `reports` (see docs/DATABASE.md for the full rationale).
--
-- Slugs are unique per content type because public URLs are
-- type-scoped: /articles/<slug>, /news/<slug>, ...
-- UNIQUE (id, content_type) is the key the extension tables reference,
-- so an extension row can never attach to the wrong content type.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contents` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `content_type`   ENUM('article','news','event','report') NOT NULL,
    `topic_id`       INT UNSIGNED    NULL DEFAULT NULL,
    `slug`           VARCHAR(190)    NOT NULL,
    `title`          VARCHAR(250)    NOT NULL,
    `summary`        VARCHAR(500)    NULL DEFAULT NULL,
    `body`           MEDIUMTEXT      NULL DEFAULT NULL,
    `cover_media_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `status`         ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `published_at`   DATETIME        NULL DEFAULT NULL,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_contents_type_slug` (`content_type`, `slug`),
    UNIQUE KEY `uq_contents_id_type` (`id`, `content_type`),
    KEY `idx_contents_listing` (`content_type`, `status`, `published_at`),
    KEY `idx_contents_status_published` (`status`, `published_at`),
    KEY `idx_contents_topic` (`topic_id`, `status`, `published_at`),
    KEY `idx_contents_slug` (`slug`),
    KEY `idx_contents_cover` (`cover_media_id`),
    CONSTRAINT `fk_contents_topic`
        FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_contents_cover_media`
        FOREIGN KEY (`cover_media_id`) REFERENCES `media` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- events — 1:1 extension of a `contents` row of type 'event'.
--
-- `content_type` repeats the parent's column with the IDENTICAL type
-- (a foreign key requires identical column types) and is pinned to
-- 'event' by a CHECK, so the composite foreign key can only ever match
-- a parent whose content_type is 'event'. Deleting the content row
-- deletes this row with it — no orphans.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `events` (
    `content_id`   BIGINT UNSIGNED NOT NULL,
    `content_type` ENUM('article','news','event','report') NOT NULL DEFAULT 'event',
    `starts_at`    DATETIME        NOT NULL,
    `ends_at`      DATETIME        NULL DEFAULT NULL,
    `location`     VARCHAR(250)    NULL DEFAULT NULL,
    PRIMARY KEY (`content_id`),
    KEY `idx_events_starts_at` (`starts_at`),
    KEY `idx_events_content_type` (`content_id`, `content_type`),
    CONSTRAINT `chk_events_type` CHECK (`content_type` = 'event'),
    CONSTRAINT `chk_events_date_range` CHECK (`ends_at` IS NULL OR `ends_at` >= `starts_at`),
    CONSTRAINT `fk_events_content`
        FOREIGN KEY (`content_id`, `content_type`) REFERENCES `contents` (`id`, `content_type`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- reports — 1:1 extension of a `contents` row of type 'report'.
-- Same identical-type + CHECK pattern as `events`. It also gives
-- `report_images` a concrete parent table to hang its foreign key on.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reports` (
    `content_id`   BIGINT UNSIGNED NOT NULL,
    `content_type` ENUM('article','news','event','report') NOT NULL DEFAULT 'report',
    `event_date`   DATE            NULL DEFAULT NULL,
    `location`     VARCHAR(250)    NULL DEFAULT NULL,
    PRIMARY KEY (`content_id`),
    KEY `idx_reports_event_date` (`event_date`),
    KEY `idx_reports_content_type` (`content_id`, `content_type`),
    CONSTRAINT `chk_reports_type` CHECK (`content_type` = 'report'),
    CONSTRAINT `fk_reports_content`
        FOREIGN KEY (`content_id`, `content_type`) REFERENCES `contents` (`id`, `content_type`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- report_images — the ordered photo gallery of one report.
--
-- A report can hold many images; each gallery entry points at a row in
-- `media`, so file metadata is never duplicated. The composite foreign
-- key on (media_id, media_type) plus the CHECK guarantees that only
-- rows whose media_type is 'image' can enter a gallery.
-- Deleting the report or the media row removes the gallery entry, so
-- orphan gallery rows cannot survive.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `report_images` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `report_id`  BIGINT UNSIGNED NOT NULL,
    `media_id`   BIGINT UNSIGNED NOT NULL,
    `media_type` ENUM('image','audio','video','document') NOT NULL DEFAULT 'image',
    `caption`    VARCHAR(250)    NULL DEFAULT NULL,
    `sort_order` INT             NOT NULL DEFAULT 0,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_report_images_report_media` (`report_id`, `media_id`),
    KEY `idx_report_images_order` (`report_id`, `sort_order`),
    KEY `idx_report_images_media` (`media_id`, `media_type`),
    CONSTRAINT `chk_report_images_media_type` CHECK (`media_type` = 'image'),
    CONSTRAINT `fk_report_images_report`
        FOREIGN KEY (`report_id`) REFERENCES `reports` (`content_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_report_images_media`
        FOREIGN KEY (`media_id`, `media_type`) REFERENCES `media` (`id`, `media_type`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- content_media — attaches media (audio, video, documents, extra
-- images) to any content row. This is how contextual audio/video
-- reaches an article, a news item, an event or a report.
--
-- The primary key (content_id, media_id) is also the uniqueness rule:
-- a media file can be attached to the same content row only once.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `content_media` (
    `content_id` BIGINT UNSIGNED NOT NULL,
    `media_id`   BIGINT UNSIGNED NOT NULL,
    `role`       ENUM('attachment','audio','video','document') NOT NULL DEFAULT 'attachment',
    `sort_order` INT             NOT NULL DEFAULT 0,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`content_id`, `media_id`),
    KEY `idx_content_media_media` (`media_id`),
    KEY `idx_content_media_order` (`content_id`, `sort_order`),
    CONSTRAINT `fk_content_media_content`
        FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_content_media_media`
        FOREIGN KEY (`media_id`) REFERENCES `media` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- content_relations — "related content" between two content rows.
-- Directed (content_id -> related_content_id) with its own ordering,
-- so an editor controls exactly what is shown under each item.
-- The CHECK blocks self-references on servers that enforce CHECK; the
-- data layer rejects them everywhere.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `content_relations` (
    `content_id`         BIGINT UNSIGNED NOT NULL,
    `related_content_id` BIGINT UNSIGNED NOT NULL,
    `sort_order`         INT             NOT NULL DEFAULT 0,
    `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`content_id`, `related_content_id`),
    KEY `idx_content_relations_related` (`related_content_id`),
    KEY `idx_content_relations_order` (`content_id`, `sort_order`),
    CONSTRAINT `chk_content_relations_not_self` CHECK (`content_id` <> `related_content_id`),
    CONSTRAINT `fk_content_relations_content`
        FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_content_relations_related`
        FOREIGN KEY (`related_content_id`) REFERENCES `contents` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
