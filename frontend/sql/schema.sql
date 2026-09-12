CREATE DATABASE IF NOT EXISTS drumeo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE drumeo;

CREATE TABLE IF NOT EXISTS profiles (
  id TINYINT UNSIGNED PRIMARY KEY,
  slug VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(64) NOT NULL,
  hide_future TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO profiles (id, slug, name) VALUES
  (1, 'vic', 'Vic'),
  (2, 'lenn', 'Lenn'),
  (3, 'wouter', 'Wouter')
ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE TABLE IF NOT EXISTS watch_progress (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  profile_id TINYINT UNSIGNED NOT NULL,
  lesson_id INT NOT NULL,
  vimeo_id VARCHAR(32) NOT NULL,
  position_sec DOUBLE NOT NULL DEFAULT 0,
  duration_sec DOUBLE NOT NULL DEFAULT 0,
  watched TINYINT(1) NOT NULL DEFAULT 0,
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
  last_lesson_id INT NULL,
  last_audio_index INT NOT NULL DEFAULT 1,
  compat_mode TINYINT(1) NOT NULL DEFAULT 0,
  path_view VARCHAR(16) NOT NULL DEFAULT 'order',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_state_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
) ENGINE=InnoDB;
