<?php

declare(strict_types=1);

namespace Drumeo\App;

use PDO;

final class Progress
{
    public const WATCHED_TAIL_SEC = 15;
    public const DISMISS_TAIL_SEC = 5;

    public function __construct(
        private readonly Database $db,
        private readonly Catalog $catalog,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function profileBySlug(string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, slug, name FROM profiles WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function profiles(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT p.id, p.slug, p.name, COALESCE(s.last_audio_index, 1) AS last_audio_index
             FROM profiles p
             LEFT JOIN profile_state s ON s.profile_id = p.id
             ORDER BY p.id'
        )->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['lastAudioIndex'] = ((int) $row['last_audio_index'] === 1) ? 1 : 0;
            unset($row['last_audio_index']);
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    public function snapshot(int $profileId): array
    {
        $pdo = $this->db->pdo();
        $progress = $pdo->prepare('SELECT lesson_id, vimeo_id, position_sec, duration_sec, watched, UNIX_TIMESTAMP(updated_at) AS updated FROM watch_progress WHERE profile_id = ?');
        $progress->execute([$profileId]);
        $byLesson = [];
        foreach ($progress->fetchAll() ?: [] as $row) {
            $byLesson[(string) $row['lesson_id']] = [
                'lessonId' => (int) $row['lesson_id'],
                'vimeoId' => $row['vimeo_id'],
                'position' => (float) $row['position_sec'],
                'duration' => (float) $row['duration_sec'],
                'watched' => (bool) $row['watched'],
                'updated' => (int) $row['updated'],
            ];
        }

        $ratings = $pdo->prepare('SELECT lesson_id, score, UNIX_TIMESTAMP(created_at) AS created FROM ratings WHERE profile_id = ? ORDER BY id ASC');
        $ratings->execute([$profileId]);
        $ratingMap = [];
        $latest = [];
        foreach ($ratings->fetchAll() ?: [] as $row) {
            $lid = (string) $row['lesson_id'];
            $ratingMap[$lid][] = [
                'score' => (int) $row['score'],
                'at' => (int) $row['created'],
            ];
            $latest[$lid] = (int) $row['score'];
        }

        $stateStmt = $pdo->prepare('SELECT last_lesson_id, last_audio_index, compat_mode FROM profile_state WHERE profile_id = ?');
        $stateStmt->execute([$profileId]);
        $state = $stateStmt->fetch();

        return [
            'progress' => $byLesson,
            'ratings' => $ratingMap,
            'latestScore' => $latest,
            'lastLessonId' => ($state && $state['last_lesson_id'] !== null) ? (int) $state['last_lesson_id'] : null,
            'lastAudioIndex' => $state ? ((int) $state['last_audio_index'] === 1 ? 1 : 0) : 1,
            'compatMode' => (bool) ($state['compat_mode'] ?? 0),
        ];
    }

    public function saveProgress(int $profileId, int $lessonId, string $vimeoId, float $position, float $duration): array
    {
        $watched = $duration > 0 && $position >= max(0, $duration - self::WATCHED_TAIL_SEC);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO watch_progress (profile_id, lesson_id, vimeo_id, position_sec, duration_sec, watched)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                vimeo_id = VALUES(vimeo_id),
                position_sec = IF(watch_progress.watched = 1 AND VALUES(watched) = 0, watch_progress.position_sec, VALUES(position_sec)),
                duration_sec = VALUES(duration_sec),
                watched = IF(VALUES(watched) = 1, 1, watch_progress.watched)'
        );
        $stmt->execute([$profileId, $lessonId, $vimeoId, $position, $duration, $watched ? 1 : 0]);
        $pdo->prepare(
            'INSERT INTO profile_state (profile_id, last_lesson_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_lesson_id = VALUES(last_lesson_id)'
        )->execute([$profileId, $lessonId]);
        return ['watched' => $watched, 'position' => $position];
    }

    public function markWatched(int $profileId, int $lessonId, string $vimeoId, float $duration): void
    {
        $this->saveProgress($profileId, $lessonId, $vimeoId, max($duration, 0), $duration);
        $this->db->pdo()->prepare(
            'UPDATE watch_progress SET watched = 1, position_sec = GREATEST(position_sec, duration_sec) WHERE profile_id = ? AND lesson_id = ?'
        )->execute([$profileId, $lessonId]);
    }

    public function resetLesson(int $profileId, int $lessonId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE watch_progress SET position_sec = 0, watched = 0 WHERE profile_id = ? AND lesson_id = ?'
        )->execute([$profileId, $lessonId]);
    }

    public function addRating(int $profileId, int $lessonId, int $score): void
    {
        $score = max(1, min(4, $score));
        $this->db->pdo()->prepare(
            'INSERT INTO ratings (profile_id, lesson_id, score) VALUES (?, ?, ?)'
        )->execute([$profileId, $lessonId, $score]);
    }

    public function setAudio(int $profileId, int $audioIndex): int
    {
        $audioIndex = $audioIndex === 1 ? 1 : 0;
        $this->db->pdo()->prepare(
            'INSERT INTO profile_state (profile_id, last_audio_index) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_audio_index = VALUES(last_audio_index)'
        )->execute([$profileId, $audioIndex]);
        return $audioIndex;
    }

    public function setCompat(int $profileId, bool $on): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO profile_state (profile_id, compat_mode) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE compat_mode = VALUES(compat_mode)'
        )->execute([$profileId, $on ? 1 : 0]);
    }

    /** @param array<string,mixed> $snap */
    public function resume(int $profileId, array $snap): ?array
    {
        $order = $this->catalog->orderedLessons();
        if ($order === []) {
            return null;
        }
        $lastId = $snap['lastLessonId'];
        if ($lastId === null) {
            $latest = 0;
            foreach ($snap['progress'] as $row) {
                if ($row['updated'] >= $latest) {
                    $latest = $row['updated'];
                    $lastId = $row['lessonId'];
                }
            }
        }
        if ($lastId === null) {
            $first = $order[0];
            return [
                'lessonId' => $first['id'],
                'position' => 0,
                'reason' => 'start',
            ];
        }
        $p = $snap['progress'][(string) $lastId] ?? null;
        $lesson = $this->catalog->lesson($lastId);
        $duration = (float) ($p['duration'] ?? $lesson['seconds'] ?? 0);
        $position = (float) ($p['position'] ?? 0);
        $watched = (bool) ($p['watched'] ?? false);
        $remaining = $duration > 0 ? $duration - $position : 999;
        if ($watched || $remaining <= self::DISMISS_TAIL_SEC) {
            $nextId = $this->nextUnwatched($lastId, $snap);
            if ($nextId === null) {
                return [
                    'lessonId' => $lastId,
                    'position' => 0,
                    'reason' => 'complete',
                ];
            }
            return [
                'lessonId' => $nextId,
                'position' => 0,
                'reason' => 'next',
            ];
        }
        return [
            'lessonId' => $lastId,
            'position' => $position,
            'reason' => 'continue',
        ];
    }

    /** @param array<string,mixed> $snap @return list<array<string,mixed>> */
    public function practice(array $snap): array
    {
        $out = [];
        foreach ($this->catalog->orderedLessons() as $lesson) {
            $id = (string) $lesson['id'];
            if (!isset($snap['latestScore'][$id])) {
                continue;
            }
            if ((int) $snap['latestScore'][$id] >= 4) {
                continue;
            }
            $out[] = $lesson + [
                'latestScore' => (int) $snap['latestScore'][$id],
                'ratings' => $snap['ratings'][$id] ?? [],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $snap */
    private function nextUnwatched(int $afterId, array $snap): ?int
    {
        $order = $this->catalog->orderedLessons();
        $seen = false;
        foreach ($order as $lesson) {
            if (!$seen) {
                if ((int) $lesson['id'] === $afterId) {
                    $seen = true;
                }
                continue;
            }
            $row = $snap['progress'][(string) $lesson['id']] ?? null;
            if (!$row || !$row['watched']) {
                return (int) $lesson['id'];
            }
        }
        foreach ($order as $lesson) {
            $row = $snap['progress'][(string) $lesson['id']] ?? null;
            if (!$row || !$row['watched']) {
                return (int) $lesson['id'];
            }
        }
        return null;
    }
}
