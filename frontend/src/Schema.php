<?php

declare(strict_types=1);

namespace Drumeo\App;

use PDO;
use PDOException;

/**
 * Versioned, run-once migrations. sql/schema.sql is the full schema for a fresh
 * install; these steps bring older databases up to it and are idempotent, so a
 * fresh install simply records them on its first request.
 *
 * Up to date costs one query per request (only on routes that use the DB).
 */
final class Schema
{
    public const VERSION = 2;
    private const LOCK = 'drumeo:schema-migrate';

    private bool $checked = false;

    public function __construct(
        private readonly Database $db,
        private readonly Catalog $catalog,
    ) {
    }

    public function migrate(): void
    {
        if ($this->checked) {
            return;
        }
        $pdo = $this->db->pdo();
        if (self::currentVersion($pdo) >= self::VERSION) {
            $this->checked = true;
            return;
        }
        if ((int) $pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 10)")->fetchColumn() !== 1) {
            throw new \RuntimeException('schema migration lock timeout');
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_version (
                    version INT UNSIGNED NOT NULL PRIMARY KEY,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ) ENGINE=InnoDB'
            );
            $version = self::currentVersion($pdo);
            foreach ($this->steps() as $target => $step) {
                if ($target <= $version) {
                    continue;
                }
                $step($pdo);
                $pdo->prepare('INSERT INTO schema_version (version) VALUES (?)')->execute([$target]);
                error_log('[drumeo] schema migrated to v' . $target);
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
        $this->checked = true;
    }

    private static function currentVersion(PDO $pdo): int
    {
        try {
            return (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_version')->fetchColumn();
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return 0;
            }
            throw $e;
        }
    }

    /** @return array<int, callable(PDO):void> */
    private function steps(): array
    {
        return [
            1 => fn (PDO $pdo) => $this->baseline($pdo),
            2 => fn (PDO $pdo) => $this->arthur($pdo),
        ];
    }

    /** Tables and columns that used to be added on every request. */
    private function baseline(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS play_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                profile_id TINYINT UNSIGNED NOT NULL,
                lesson_id INT NOT NULL,
                played_on DATE NOT NULL,
                played_sec DOUBLE NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_play_day (profile_id, lesson_id, played_on),
                KEY idx_profile_day (profile_id, played_on)
             ) ENGINE=InnoDB'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lesson_notes (
                profile_id TINYINT UNSIGNED NOT NULL,
                lesson_id INT NOT NULL,
                body TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (profile_id, lesson_id),
                CONSTRAINT fk_notes_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
             ) ENGINE=InnoDB'
        );
        self::ensureColumn($pdo, 'watch_progress', 'played_sec', 'played_sec DOUBLE NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'watch_progress', 'played_buckets', 'played_buckets TEXT NULL');
        self::ensureColumn($pdo, 'play_events', 'played_sec', 'played_sec DOUBLE NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'profile_state', 'path_view', "path_view VARCHAR(16) NOT NULL DEFAULT 'order'");
        self::ensureColumn($pdo, 'profiles', 'hide_future', 'hide_future TINYINT(1) NOT NULL DEFAULT 1');
    }

    /** Arthur starts with the Method intro already watched. */
    private function arthur(PDO $pdo): void
    {
        $pdo->exec("INSERT IGNORE INTO profiles (id, slug, name, hide_future) VALUES (4, 'arthur', 'Arthur', 1)");
        $watched = $pdo->query('SELECT COUNT(*) FROM watch_progress WHERE profile_id = 4 AND watched = 1');
        if ((int) $watched->fetchColumn() > 0) {
            return;
        }
        $first = $this->catalog->orderedLessons()[0] ?? null;
        if (!is_array($first) || (int) ($first['id'] ?? 0) <= 0) {
            error_log('[drumeo] schema v2: catalog has no intro; Arthur starts without a watched lesson');
            return;
        }
        $duration = max(1.0, (float) ($first['seconds'] ?? 0));
        $pdo->prepare(
            'INSERT INTO watch_progress (profile_id, lesson_id, vimeo_id, position_sec, duration_sec, watched, played_sec)
             VALUES (4, ?, ?, ?, ?, 1, ?) AS new
             ON DUPLICATE KEY UPDATE watched = 1, position_sec = new.position_sec, duration_sec = new.duration_sec'
        )->execute([(int) $first['id'], (string) ($first['vimeoId'] ?? ''), $duration, $duration, $duration]);
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $ddl): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN ' . $ddl);
        }
    }
}
