-- Central site identity; one unique row per key, no credentials.
CREATE TABLE IF NOT EXISTS `site_settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` TEXT NOT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
