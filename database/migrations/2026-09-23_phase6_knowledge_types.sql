-- =============================================================
-- Migration: Phase 6 — knowledge content types (books, lessons, research)
-- Target:    MySQL 5.7+ / MariaDB 10.2+ (InnoDB, utf8mb4)
-- Applies to: databases installed with the Phase 2/3/4/5 schema.sql.
-- Fresh installs do NOT need this file (the current schema.sql already
-- contains the final structure).
--
-- What it does (additive only, no data is touched):
--   1. Widens the `content_type` ENUM of `contents`, `events` and
--      `reports` to also accept 'book', 'lesson' and 'research'.
--      - ENUM values are only ADDED at the end of the list, so existing
--        stored values keep their meaning; MySQL/MariaDB perform this as
--        a metadata change, no row rewrite.
--      - `events`/`reports` must repeat the parent's ENUM verbatim for
--        the composite foreign key to stay valid; their CHECK
--        constraints still pin them to 'event' / 'report'.
--   2. Creates the three 1:1 extension tables `books`, `research` and
--      `lessons` (same composite-FK + CHECK pattern as `events`).
--
-- Re-run behaviour: the ALTER ... MODIFY statements are idempotent in
-- effect (applying the same definition twice changes nothing), and the
-- CREATE TABLE statements are IF NOT EXISTS. Running the file twice is
-- therefore safe, though unnecessary.
--
-- Backup first (standard practice for any structural change):
--     mysqldump -u USER -p DATABASE > backup-before-phase6.sql
-- Apply:
--     mysql -u USER -p DATABASE < database/migrations/2026-09-23_phase6_knowledge_types.sql
-- =============================================================

SET NAMES utf8mb4;

-- 1) Widen content_type ENUMs (values appended; existing rows unchanged)
ALTER TABLE `contents`
    MODIFY `content_type` ENUM('article','news','event','report','book','lesson','research') NOT NULL;

ALTER TABLE `events`
    MODIFY `content_type` ENUM('article','news','event','report','book','lesson','research') NOT NULL DEFAULT 'event';

ALTER TABLE `reports`
    MODIFY `content_type` ENUM('article','news','event','report','book','lesson','research') NOT NULL DEFAULT 'report';

-- 2) Knowledge extension tables
CREATE TABLE IF NOT EXISTS `books` (
    `content_id`   BIGINT UNSIGNED NOT NULL,
    `content_type` ENUM('article','news','event','report','book','lesson','research') NOT NULL DEFAULT 'book',
    `author`       VARCHAR(250) NULL DEFAULT NULL,
    PRIMARY KEY (`content_id`),
    KEY `idx_books_content_type` (`content_id`, `content_type`),
    KEY `idx_books_author` (`author`),
    CONSTRAINT `chk_books_type` CHECK (`content_type` = 'book'),
    CONSTRAINT `fk_books_content`
        FOREIGN KEY (`content_id`, `content_type`) REFERENCES `contents` (`id`, `content_type`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `research` (
    `content_id`   BIGINT UNSIGNED NOT NULL,
    `content_type` ENUM('article','news','event','report','book','lesson','research') NOT NULL DEFAULT 'research',
    `author`       VARCHAR(250) NULL DEFAULT NULL,
    PRIMARY KEY (`content_id`),
    KEY `idx_research_content_type` (`content_id`, `content_type`),
    KEY `idx_research_author` (`author`),
    CONSTRAINT `chk_research_type` CHECK (`content_type` = 'research'),
    CONSTRAINT `fk_research_content`
        FOREIGN KEY (`content_id`, `content_type`) REFERENCES `contents` (`id`, `content_type`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lessons` (
    `content_id`     BIGINT UNSIGNED NOT NULL,
    `content_type`   ENUM('article','news','event','report','book','lesson','research') NOT NULL DEFAULT 'lesson',
    `sort_order`     INT        NOT NULL DEFAULT 0,
    `requires_login` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`content_id`),
    KEY `idx_lessons_order` (`sort_order`),
    KEY `idx_lessons_content_type` (`content_id`, `content_type`),
    CONSTRAINT `chk_lessons_type` CHECK (`content_type` = 'lesson'),
    CONSTRAINT `chk_lessons_requires_login` CHECK (`requires_login` IN (0, 1)),
    CONSTRAINT `fk_lessons_content`
        FOREIGN KEY (`content_id`, `content_type`) REFERENCES `contents` (`id`, `content_type`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
