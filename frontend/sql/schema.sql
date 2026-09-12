CREATE DATABASE IF NOT EXISTS drumeo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE drumeo;

CREATE TABLE IF NOT EXISTS profiles (
  id TINYINT UNSIGNED PRIMARY KEY,
  slug VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(64) NOT NULL,
  hide_future TINYINT(1) NOT NULL DEFAULT 1,
  coach_enabled TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO profiles (id, slug, name, coach_enabled) VALUES
  (1, 'vic', 'Vic', 0),
  (2, 'lenn', 'Lenn', 0),
  (3, 'wouter', 'Wouter', 1),
  (4, 'arthur', 'Arthur', 0)
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

CREATE TABLE IF NOT EXISTS coach_calibration (
  profile_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  noise_rms DOUBLE NOT NULL DEFAULT 0,
  hit_rms DOUBLE NOT NULL DEFAULT 0,
  latency_ms DOUBLE NULL,
  sample_rate INT NOT NULL DEFAULT 48000,
  hits INT NOT NULL DEFAULT 0,
  body JSON NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_coach_cal_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coach_recordings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  profile_id TINYINT UNSIGNED NOT NULL,
  lesson_id INT NOT NULL,
  vimeo_id VARCHAR(32) NOT NULL,
  duration_sec DOUBLE NOT NULL DEFAULT 0,
  bytes INT NOT NULL DEFAULT 0,
  mime VARCHAR(64) NOT NULL DEFAULT 'audio/mp4',
  path VARCHAR(512) NOT NULL DEFAULT '',
  video_offset_sec DOUBLE NOT NULL DEFAULT 0,
  status VARCHAR(16) NOT NULL DEFAULT 'uploading',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_coach_rec_profile (profile_id, created_at),
  KEY idx_coach_rec_status (status, id),
  CONSTRAINT fk_coach_rec_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coach_evaluations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recording_id BIGINT UNSIGNED NOT NULL,
  engine VARCHAR(32) NOT NULL,
  score DOUBLE NULL,
  summary JSON NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'ready',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_coach_eval (recording_id, engine),
  KEY idx_coach_eval_rec (recording_id),
  CONSTRAINT fk_coach_eval_rec FOREIGN KEY (recording_id) REFERENCES coach_recordings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coach_cal_sessions (
  id VARCHAR(40) NOT NULL PRIMARY KEY,
  profile_id TINYINT UNSIGNED NOT NULL,
  path VARCHAR(512) NOT NULL DEFAULT '',
  meta_path VARCHAR(512) NOT NULL DEFAULT '',
  mime VARCHAR(64) NOT NULL DEFAULT 'audio/mp4',
  bytes INT NOT NULL DEFAULT 0,
  duration_sec DOUBLE NOT NULL DEFAULT 0,
  feedback TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_coach_cal_sess (profile_id, created_at),
  CONSTRAINT fk_coach_cal_sess_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
) ENGINE=InnoDB;
