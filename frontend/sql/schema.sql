-- Full schema for a fresh install (run by MySQL's docker-entrypoint-initdb.d).
-- Older databases are brought up to this by src/Schema.php. Its steps are
-- idempotent: on a fresh install they find nothing to change, record their
-- versions in schema_version, and give Arthur the watched Method intro (the
-- intro id comes from catalog.json).

CREATE DATABASE IF NOT EXISTS drumeo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE drumeo;

CREATE TABLE IF NOT EXISTS schema_version (
  version INT UNSIGNED NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profiles (
  id TINYINT UNSIGNED PRIMARY KEY,
  slug VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(64) NOT NULL,
  hide_future TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO profiles (id, slug, name) VALUES
  (1, 'vic', 'Vic'),
  (2, 'lenn', 'Lenn'),
  (3, 'wouter', 'Wouter'),
  (4, 'arthur', 'Arthur')
AS new
ON DUPLICATE KEY UPDATE name = new.name;

CREATE TABLE IF NOT EXISTS watch_progress (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  profile_id TINYINT UNSIGNED NOT NULL,
  lesson_id INT NOT NULL,
  vimeo_id VARCHAR(32) NOT NULL,
  position_sec DOUBLE NOT NULL DEFAULT 0,
  duration_sec DOUBLE NOT NULL DEFAULT 0,
  watched TINYINT(1) NOT NULL DEFAULT 0,
  -- Unique bucket coverage in seconds (BUCKET_SEC per 3 s bucket), capped at the duration.
  played_sec DOUBLE NOT NULL DEFAULT 0,
  played_buckets TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_profile_lesson (profile_id, lesson_id),
  KEY idx_profile_updated (profile_id, updated_at),
  CONSTRAINT fk_progress_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ratings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  profile_id TINYINT UNSIGNED NOT NULL,
  lesson_id INT NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_profile_lesson (profile_id, lesson_id),
  CONSTRAINT fk_ratings_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
) ENGINE=InnoDB;

-- Play time per profile, lesson and day (Europe/Brussels). The source for every
-- practice total: today, this week and all-time. Rows only exist for days with
-- more than 0 seconds of play.
CREATE TABLE IF NOT EXISTS play_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  profile_id TINYINT UNSIGNED NOT NULL,
  lesson_id INT NOT NULL,
  played_on DATE NOT NULL,
  played_sec DOUBLE NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_play_day (profile_id, lesson_id, played_on),
  KEY idx_profile_day (profile_id, played_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lesson_notes (
  profile_id TINYINT UNSIGNED NOT NULL,
  lesson_id INT NOT NULL,
  body TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (profile_id, lesson_id),
  CONSTRAINT fk_notes_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profile_state (
  profile_id TINYINT UNSIGNED PRIMARY KEY,
  -- Legacy: no longer written or read. Kept so an older image can still run against this database.
  last_lesson_id INT NULL,
  last_audio_index INT NOT NULL DEFAULT 1,
  -- Legacy: unused.
  compat_mode TINYINT(1) NOT NULL DEFAULT 0,
  path_view VARCHAR(16) NOT NULL DEFAULT 'order',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_state_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
) ENGINE=InnoDB;
