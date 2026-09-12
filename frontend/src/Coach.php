<?php

declare(strict_types=1);

namespace Drumeo\App;

use PDO;

final class Coach
{
    public const FROM_LESSON = 1;
    public const ENGINES = ['hybrid', 'onset_match', 'onset_dtw', 'tempo', 'envelope', 'bands'];

    public function __construct(
        private readonly Database $db,
        private readonly Catalog $catalog,
        private readonly Config $config,
    ) {
    }

    public function ensureSchema(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS coach_calibration (
                profile_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                noise_rms DOUBLE NOT NULL DEFAULT 0,
                hit_rms DOUBLE NOT NULL DEFAULT 0,
                latency_ms DOUBLE NULL,
                sample_rate INT NOT NULL DEFAULT 48000,
                hits INT NOT NULL DEFAULT 0,
                body JSON NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_coach_cal_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
             ) ENGINE=InnoDB'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS coach_recordings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                profile_id TINYINT UNSIGNED NOT NULL,
                lesson_id INT NOT NULL,
                vimeo_id VARCHAR(32) NOT NULL,
                duration_sec DOUBLE NOT NULL DEFAULT 0,
                bytes INT NOT NULL DEFAULT 0,
                mime VARCHAR(64) NOT NULL DEFAULT "audio/mp4",
                path VARCHAR(512) NOT NULL DEFAULT "",
                video_offset_sec DOUBLE NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT "uploading",
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_coach_rec_profile (profile_id, created_at),
                KEY idx_coach_rec_status (status, id),
                CONSTRAINT fk_coach_rec_profile FOREIGN KEY (profile_id) REFERENCES profiles(id)
             ) ENGINE=InnoDB'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS coach_evaluations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                recording_id BIGINT UNSIGNED NOT NULL,
                engine VARCHAR(32) NOT NULL,
                score DOUBLE NULL,
                summary JSON NULL,
                status VARCHAR(16) NOT NULL DEFAULT "ready",
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_coach_eval (recording_id, engine),
                KEY idx_coach_eval_rec (recording_id),
                CONSTRAINT fk_coach_eval_rec FOREIGN KEY (recording_id) REFERENCES coach_recordings(id) ON DELETE CASCADE
             ) ENGINE=InnoDB'
        );
    }

    public function forLesson(?array $lesson): bool
    {
        $n = (int) ($lesson['n'] ?? 0);
        return $n >= self::FROM_LESSON;
    }

    /** @return array<string,mixed>|null */
    public function calibration(int $profileId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT noise_rms, hit_rms, latency_ms, sample_rate, hits, UNIX_TIMESTAMP(updated_at) AS updated
             FROM coach_calibration WHERE profile_id = ?'
        );
        $stmt->execute([$profileId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'noiseRms' => (float) $row['noise_rms'],
            'hitRms' => (float) $row['hit_rms'],
            'latencyMs' => $row['latency_ms'] !== null ? (float) $row['latency_ms'] : null,
            'sampleRate' => (int) $row['sample_rate'],
            'hits' => (int) $row['hits'],
            'updated' => (int) $row['updated'],
        ];
    }

    public function saveCalibration(int $profileId, float $noiseRms, float $hitRms, int $hits, int $sampleRate, ?float $latencyMs = null): array
    {
        $this->db->pdo()->prepare(
            'INSERT INTO coach_calibration (profile_id, noise_rms, hit_rms, latency_ms, sample_rate, hits)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                noise_rms = VALUES(noise_rms),
                hit_rms = VALUES(hit_rms),
                latency_ms = VALUES(latency_ms),
                sample_rate = VALUES(sample_rate),
                hits = VALUES(hits)'
        )->execute([$profileId, $noiseRms, $hitRms, $latencyMs, $sampleRate, $hits]);
        return $this->calibration($profileId) ?? [];
    }

    /** @return array<string,mixed> */
    public function startRecording(int $profileId, int $lessonId, string $vimeoId, float $videoOffset): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO coach_recordings (profile_id, lesson_id, vimeo_id, video_offset_sec, status)
             VALUES (?, ?, ?, ?, "uploading")'
        )->execute([$profileId, $lessonId, $vimeoId, $videoOffset]);
        $id = (int) $pdo->lastInsertId();
        return $this->recording($profileId, $id) ?? ['id' => $id, 'status' => 'uploading'];
    }

    public function saveUpload(int $profileId, int $id, string $tmp, string $mime, float $duration): ?array
    {
        $row = $this->recording($profileId, $id);
        if ($row === null) {
            return null;
        }
        $dir = rtrim($this->config->recordingsDir, '/') . '/' . $profileId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('could not create recordings dir');
        }
        $ext = str_contains($mime, 'webm') ? 'webm' : (str_contains($mime, 'wav') ? 'wav' : 'm4a');
        $dest = $dir . '/' . $id . '.' . $ext;
        if (!move_uploaded_file($tmp, $dest)) {
            if (!is_file($tmp) || !rename($tmp, $dest) && !copy($tmp, $dest)) {
                throw new \RuntimeException('could not store recording');
            }
        }
        @chmod($dest, 0664);
        $bytes = is_file($dest) ? (int) filesize($dest) : 0;
        $this->db->pdo()->prepare(
            'UPDATE coach_recordings
             SET path = ?, mime = ?, bytes = ?, duration_sec = ?, status = "queued"
             WHERE id = ? AND profile_id = ?'
        )->execute([$dest, $mime, $bytes, $duration, $id, $profileId]);
        return $this->recording($profileId, $id);
    }

    /** @return array<string,mixed>|null */
    public function recording(int $profileId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, profile_id, lesson_id, vimeo_id, duration_sec, bytes, mime, path, video_offset_sec, status,
                    UNIX_TIMESTAMP(created_at) AS created
             FROM coach_recordings WHERE id = ? AND profile_id = ?'
        );
        $stmt->execute([$id, $profileId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->presentRecording($row, true) : null;
    }

    /** @return list<array<string,mixed>> */
    public function recordings(int $profileId, int $limit = 80): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, profile_id, lesson_id, vimeo_id, duration_sec, bytes, mime, path, video_offset_sec, status,
                    UNIX_TIMESTAMP(created_at) AS created
             FROM coach_recordings
             WHERE profile_id = ? AND status <> "uploading"
             ORDER BY id DESC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([$profileId]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[] = $this->presentRecording($row, false);
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function presentRecording(array $row, bool $withEvals): array
    {
        $id = (int) $row['id'];
        $lessonId = (int) $row['lesson_id'];
        $lesson = $this->catalog->lesson($lessonId);
        $item = [
            'id' => $id,
            'lessonId' => $lessonId,
            'vimeoId' => (string) $row['vimeo_id'],
            'duration' => (float) $row['duration_sec'],
            'bytes' => (int) $row['bytes'],
            'mime' => (string) $row['mime'],
            'status' => (string) $row['status'],
            'videoOffset' => (float) $row['video_offset_sec'],
            'created' => (int) $row['created'],
            'title' => is_array($lesson) ? (string) ($lesson['title'] ?? '') : '',
            'titleNl' => is_array($lesson) ? ($lesson['titleNl'] ?? null) : null,
            'n' => is_array($lesson) ? ($lesson['n'] ?? null) : null,
            'hybrid' => null,
            'evaluations' => [],
        ];
        $evals = $this->evaluations($id);
        $item['evaluations'] = $withEvals ? $evals : [];
        foreach ($evals as $e) {
            if (($e['engine'] ?? '') === 'hybrid') {
                $item['hybrid'] = $e['score'];
                if (!$withEvals) {
                    $item['comments'] = is_array($e['summary']['comments'] ?? null) ? $e['summary']['comments'] : [];
                }
            }
        }
        return $item;
    }

    /** @return list<array<string,mixed>> */
    public function evaluations(int $recordingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT engine, score, summary, status, UNIX_TIMESTAMP(created_at) AS created
             FROM coach_evaluations WHERE recording_id = ? ORDER BY FIELD(engine, "hybrid","onset_match","onset_dtw","tempo","envelope","bands","activity")'
        );
        $stmt->execute([$recordingId]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $sum = $row['summary'];
            if (is_string($sum) && $sum !== '') {
                $decoded = json_decode($sum, true);
                $sum = is_array($decoded) ? $decoded : [];
            } else {
                $sum = [];
            }
            $out[] = [
                'engine' => (string) $row['engine'],
                'score' => $row['score'] !== null ? (float) $row['score'] : null,
                'summary' => $sum,
                'status' => (string) $row['status'],
                'created' => (int) $row['created'],
            ];
        }
        return $out;
    }

    public function recordingFile(int $profileId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT path, mime FROM coach_recordings WHERE id = ? AND profile_id = ?'
        );
        $stmt->execute([$id, $profileId]);
        $row = $stmt->fetch();
        if (!is_array($row) || !is_file((string) $row['path'])) {
            return null;
        }
        return ['path' => (string) $row['path'], 'mime' => (string) $row['mime']];
    }

    /** @return array<string,mixed>|null */
    public function teacherRef(string $vimeoId): ?array
    {
        $file = rtrim($this->config->recordingsDir, '/') . '/refs/' . $vimeoId . '.json';
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    /** @return array<string,mixed> */
    public function bootstrap(int $profileId): array
    {
        $recent = $this->recordings($profileId, 8);
        $pending = 0;
        foreach ($recent as $r) {
            if (in_array($r['status'], ['queued', 'analyzing', 'uploading'], true)) {
                $pending++;
            }
        }
        return [
            'enabled' => true,
            'fromLesson' => self::FROM_LESSON,
            'calibration' => $this->calibration($profileId),
            'recent' => $recent,
            'pending' => $pending,
        ];
    }
}
