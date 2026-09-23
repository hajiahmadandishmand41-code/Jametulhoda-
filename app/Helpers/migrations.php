<?php

declare(strict_types=1);

/** Shared explicit migration runner for installer and authenticated upgrades. */
function installer_ensure_migration_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `migration` VARCHAR(190) NOT NULL,
            `checksum` CHAR(64) NOT NULL,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_schema_migrations_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function installer_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() > 0;
}

function installer_column_type(PDO $pdo, string $table, string $column): string
{
    $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->execute([$table, $column]);
    $value = $stmt->fetchColumn();

    return is_string($value) ? $value : '';
}

function installer_phase6_satisfied(PDO $pdo): bool
{
    foreach (['books', 'lessons', 'research'] as $table) {
        if (!installer_table_exists($pdo, $table)) {
            return false;
        }
    }

    return str_contains(installer_column_type($pdo, 'contents', 'content_type'), 'book')
        && str_contains(installer_column_type($pdo, 'contents', 'content_type'), 'lesson')
        && str_contains(installer_column_type($pdo, 'contents', 'content_type'), 'research');
}

/** @return list<string> */
function installer_migration_files(): array
{
    $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);

    return array_values($files);
}

/** @return array{executed:int,recorded:int,skipped:int} */
function installer_apply_migrations(PDO $pdo): array
{
    installer_ensure_migration_table($pdo);
    $executed = 0;
    $recorded = 0;
    $skipped = 0;

    foreach (installer_migration_files() as $path) {
        $name = basename($path);
        $checksum = @hash_file('sha256', $path);
        if (!is_string($checksum)) {
            throw new RuntimeException('Migration file unreadable');
        }
        $stmt = $pdo->prepare('SELECT `checksum` FROM `schema_migrations` WHERE `migration` = ? LIMIT 1');
        $stmt->execute([$name]);
        $recordedChecksum = $stmt->fetchColumn();
        if ($recordedChecksum !== false) {
            if (!hash_equals((string) $recordedChecksum, $checksum)) {
                throw new RuntimeException('Migration checksum mismatch');
            }
            $skipped++;
            continue;
        }

        if ($name === '2026-09-23_phase6_knowledge_types.sql' && installer_phase6_satisfied($pdo)) {
            $insert = $pdo->prepare('INSERT INTO `schema_migrations` (`migration`, `checksum`) VALUES (?, ?)');
            $insert->execute([$name, $checksum]);
            $recorded++;
            continue;
        }

        $sql = @file_get_contents($path);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Migration file empty or unreadable');
        }
        foreach (sql_split_statements($sql) as $statement) {
            $pdo->exec($statement);
            $executed++;
        }
        $insert = $pdo->prepare('INSERT INTO `schema_migrations` (`migration`, `checksum`) VALUES (?, ?)');
        $insert->execute([$name, $checksum]);
        $recorded++;
    }

    return ['executed' => $executed, 'recorded' => $recorded, 'skipped' => $skipped];
}
