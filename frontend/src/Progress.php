<?php

declare(strict_types=1);

namespace Drumeo\App;

use PDO;

final class Progress
{
    public const WATCHED_RATIO = 0.85;
    public const HISTORY_RATIO = 0.33;
    public const BUCKET_SEC = 3;
    private const TZ = 'Europe/Brussels';

    public function __construct(
        private readonly Database $db,
        private readonly Catalog $catalog,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function profileBySlug(string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, slug, name, hide_future FROM profiles WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['hideFuture'] = ((int) ($row['hide_future'] ?? 1)) !== 0;
        unset($row['hide_future']);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function profiles(): array
    {
        $total = count($this->catalog->orderedLessons());
        $rows = $this->db->pdo()->query(
            'SELECT p.id, p.slug, p.name, p.hide_future, COALESCE(s.last_audio_index, 1) AS last_audio_index,
                    (SELECT COUNT(*) FROM watch_progress w WHERE w.profile_id = p.id AND w.watched = 1) AS watched_count,
                    (SELECT UNIX_TIMESTAMP(MAX(w.updated_at)) FROM watch_progress w WHERE w.profile_id = p.id) AS last_played
             FROM profiles p
             LEFT JOIN profile_state s ON s.profile_id = p.id
             ORDER BY p.id'
        )->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['hideFuture'] = ((int) ($row['hide_future'] ?? 1)) !== 0;
            $row['lastAudioIndex'] = ((int) $row['last_audio_index'] === 1) ? 1 : 0;
            $row['watchedCount'] = (int) ($row['watched_count'] ?? 0);
            $row['lessonCount'] = $total;
            $row['lastPlayed'] = $row['last_played'] !== null ? (int) $row['last_played'] : null;
            unset($row['last_audio_index'], $row['watched_count'], $row['last_played'], $row['hide_future']);
        }
        unset($row);
        return $rows;
    }

    public function ensureSchema(): void
    {
        $pdo = $this->db->pdo();
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
        $this->ensureColumn('watch_progress', 'played_sec', 'played_sec DOUBLE NOT NULL DEFAULT 0');
        $this->ensureColumn('watch_progress', 'played_buckets', 'played_buckets TEXT NULL');
        $this->ensureColumn('play_events', 'played_sec', 'played_sec DOUBLE NOT NULL DEFAULT 0');
        $this->ensureColumn('profile_state', 'path_view', "path_view VARCHAR(16) NOT NULL DEFAULT 'order'");
        $this->ensureColumn('profiles', 'hide_future', 'hide_future TINYINT(1) NOT NULL DEFAULT 1');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lesson_notes (
                profile_id TINYINT UNSIGNED NOT NULL,
                lesson_id INT NOT NULL,
                body TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (profile_id, lesson_id)
             ) ENGINE=InnoDB'
        );
        $this->backfillPlayedSec();
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

        $stateStmt = $pdo->prepare('SELECT last_lesson_id, last_audio_index, path_view FROM profile_state WHERE profile_id = ?');
        $stateStmt->execute([$profileId]);
        $state = $stateStmt->fetch();

        $notesStmt = $pdo->prepare('SELECT lesson_id, body FROM lesson_notes WHERE profile_id = ?');
        $notesStmt->execute([$profileId]);
        $notes = [];
        foreach ($notesStmt->fetchAll() ?: [] as $row) {
            $body = trim((string) $row['body']);
            if ($body === '') {
                continue;
            }
            $notes[(string) $row['lesson_id']] = $body;
        }

        return [
            'progress' => $byLesson,
            'ratings' => $ratingMap,
            'latestScore' => $latest,
            'notes' => $notes,
            'lastLessonId' => ($state && $state['last_lesson_id'] !== null) ? (int) $state['last_lesson_id'] : null,
            'lastAudioIndex' => $state ? ((int) $state['last_audio_index'] === 1 ? 1 : 0) : 1,
            'pathView' => (is_array($state) && ($state['path_view'] ?? '') === 'skill') ? 'skill' : 'order',
        ];
    }

    public function saveNote(int $profileId, int $lessonId, string $body): string
    {
        $this->ensureSchema();
        $body = trim($body);
        if (preg_match('/^.{0,2000}/us', $body, $m)) {
            $body = $m[0];
        }
        $pdo = $this->db->pdo();
        if ($body === '') {
            $pdo->prepare('DELETE FROM lesson_notes WHERE profile_id = ? AND lesson_id = ?')
                ->execute([$profileId, $lessonId]);
            return '';
        }
        $pdo->prepare(
            'INSERT INTO lesson_notes (profile_id, lesson_id, body) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE body = VALUES(body)'
        )->execute([$profileId, $lessonId, $body]);
        return $body;
    }

    /**
     * @param list<int|float|string> $playedBuckets
     * @return array{watched: bool, position: float}
     */
    public function saveProgress(int $profileId, int $lessonId, string $vimeoId, float $position, float $duration, float $playedDelta = 0.0, array $playedBuckets = []): array
    {
        $this->ensureSchema();
        $delta = max(0.0, min(30.0, $playedDelta));
        $pdo = $this->db->pdo();
        $existing = $pdo->prepare('SELECT played_buckets, watched FROM watch_progress WHERE profile_id = ? AND lesson_id = ?');
        $existing->execute([$profileId, $lessonId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $storedJson = is_array($row) && is_string($row['played_buckets'] ?? null) ? $row['played_buckets'] : null;
        $merged = self::mergeBuckets($storedJson, $playedBuckets, $duration);
        $watched = self::qualifiesWatched(count($merged), $duration) || (is_array($row) && !empty($row['watched']));
        $bucketJson = json_encode($merged, JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare(
            'INSERT INTO watch_progress (profile_id, lesson_id, vimeo_id, position_sec, duration_sec, watched, played_sec, played_buckets)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                vimeo_id = VALUES(vimeo_id),
                position_sec = IF(watch_progress.watched = 1 AND VALUES(watched) = 0, watch_progress.position_sec, VALUES(position_sec)),
                duration_sec = VALUES(duration_sec),
                watched = IF(VALUES(watched) = 1, 1, watch_progress.watched),
                played_sec = VALUES(played_sec),
                played_buckets = VALUES(played_buckets)'
        );
        $stmt->execute([
            $profileId,
            $lessonId,
            $vimeoId,
            $position,
            $duration,
            $watched ? 1 : 0,
            count($merged) * self::BUCKET_SEC,
            $bucketJson,
        ]);
        $pdo->prepare(
            'INSERT INTO profile_state (profile_id, last_lesson_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_lesson_id = VALUES(last_lesson_id)'
        )->execute([$profileId, $lessonId]);
        $this->recordPlay($profileId, $lessonId, $delta);
        return ['watched' => $watched, 'position' => $position];
    }

    public function markWatched(int $profileId, int $lessonId, string $vimeoId, float $duration): void
    {
        $this->saveProgress($profileId, $lessonId, $vimeoId, max($duration, 0), $duration, 0.0, []);
    }

    public static function requiredBucketCount(float $duration): int
    {
        if ($duration <= 0) {
            return 0;
        }
        $total = (int) ceil($duration / self::BUCKET_SEC);
        return (int) ceil($total * self::WATCHED_RATIO);
    }

    public static function qualifiesWatched(int $uniqueBuckets, float $duration): bool
    {
        $need = self::requiredBucketCount($duration);
        return $need > 0 && $uniqueBuckets >= $need;
    }

    public static function isFollowed(?array $progress): bool
    {
        if (!is_array($progress)) {
            return false;
        }
        if (!empty($progress['watched'])) {
            return true;
        }
        $duration = (float) ($progress['duration'] ?? 0);
        if ($duration <= 0) {
            return false;
        }
        $position = (float) ($progress['position'] ?? 0);
        return ($position / $duration) >= self::HISTORY_RATIO;
    }

    /**
     * @param list<mixed> $incoming
     * @return list<int>
     */
    public static function mergeBuckets(?string $storedJson, array $incoming, float $duration): array
    {
        $stored = [];
        if (is_string($storedJson) && $storedJson !== '') {
            $decoded = json_decode($storedJson, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        $max = self::maxBucketIndex($duration);
        $uniq = [];
        foreach (array_merge($stored, $incoming) as $b) {
            if (!is_numeric($b)) {
                continue;
            }
            $i = (int) $b;
            if ($i < 0 || ($max >= 0 && $i > $max)) {
                continue;
            }
            $uniq[$i] = true;
        }
        $keys = array_map('intval', array_keys($uniq));
        sort($keys, SORT_NUMERIC);
        return $keys;
    }

    public static function maxBucketIndex(float $duration): int
    {
        if ($duration <= 0) {
            return -1;
        }
        return (int) floor(($duration - 0.001) / self::BUCKET_SEC);
    }

    public function resetLesson(int $profileId, int $lessonId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE watch_progress SET position_sec = 0, watched = 0, played_sec = 0, played_buckets = NULL WHERE profile_id = ? AND lesson_id = ?'
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

    public function setPathView(int $profileId, string $view): string
    {
        $view = $view === 'skill' ? 'skill' : 'order';
        $this->db->pdo()->prepare(
            'INSERT INTO profile_state (profile_id, path_view) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE path_view = VALUES(path_view)'
        )->execute([$profileId, $view]);
        return $view;
    }

    /**
     * Highest catalog index that is fully watched. -1 if none.
     * Earlier gaps stay unwatched; we do not rewind past this point.
     *
     * @param list<int|array<string,mixed>> $order
     * @param array<int|string, mixed> $progressByLesson
     */
    public static function furthestWatchedIndex(array $order, array $progressByLesson): int
    {
        $last = -1;
        foreach ($order as $i => $lesson) {
            $id = self::orderLessonId($lesson);
            if ($id <= 0) {
                continue;
            }
            $row = self::progressFor($progressByLesson, $id);
            if ($row !== null && !empty($row['watched'])) {
                $last = (int) $i;
            }
        }
        return $last;
    }

    /**
     * Next lesson after the furthest fully watched one. Unwatched lessons
     * before that point are not treated as the resume target.
     *
     * @param list<int|array<string,mixed>> $order
     * @param array<int|string, mixed> $progressByLesson
     */
    public static function firstUnwatchedId(array $order, array $progressByLesson): ?int
    {
        $from = self::furthestWatchedIndex($order, $progressByLesson) + 1;
        $n = count($order);
        for ($i = $from; $i < $n; $i++) {
            $id = self::orderLessonId($order[$i]);
            if ($id <= 0) {
                continue;
            }
            $row = self::progressFor($progressByLesson, $id);
            if ($row === null || empty($row['watched'])) {
                return $id;
            }
        }
        return null;
    }

    /**
     * @param list<int|array<string,mixed>> $order
     * @param array<string,mixed> $snap
     * @return array{lessonId:int,position:int,reason:string}|null
     */
    public static function resumeFromOrder(array $order, array $snap): ?array
    {
        if ($order === []) {
            return null;
        }
        $progress = is_array($snap['progress'] ?? null) ? $snap['progress'] : [];
        $unwatchedId = self::firstUnwatchedId($order, $progress);
        if ($unwatchedId === null) {
            $lastId = self::orderLessonId($order[array_key_last($order)]);
            return [
                'lessonId' => $lastId,
                'position' => 0,
                'reason' => 'complete',
            ];
        }
        $firstId = self::orderLessonId($order[0]);
        $anyWatched = false;
        foreach ($progress as $row) {
            if (is_array($row) && !empty($row['watched'])) {
                $anyWatched = true;
                break;
            }
        }
        $reason = ($unwatchedId === $firstId && !$anyWatched) ? 'start' : 'next';
        return [
            'lessonId' => $unwatchedId,
            'position' => 0,
            'reason' => $reason,
        ];
    }

    /** @param array<string,mixed> $snap */
    public function resume(int $profileId, array $snap): ?array
    {
        return self::resumeFromOrder($this->catalog->orderedLessons(), $snap);
    }

    /**
     * @param list<int|array<string,mixed>> $order
     * @param array<int|string, mixed> $progressByLesson
     * @return list<int>
     */
    public static function visibleIds(array $order, array $progressByLesson, bool $hideFuture): array
    {
        $nextId = $hideFuture ? self::firstUnwatchedId($order, $progressByLesson) : null;
        $ids = [];
        foreach ($order as $lesson) {
            $id = self::orderLessonId($lesson);
            if ($id <= 0) {
                continue;
            }
            $ids[] = $id;
            if ($hideFuture && $nextId !== null && $id === $nextId) {
                break;
            }
        }
        return $ids;
    }

    /**
     * @param array<string,mixed> $all
     * @param list<int> $visibleIds
     * @return array<string,mixed>
     */
    public static function filterCatalogData(array $all, array $visibleIds): array
    {
        $allow = [];
        foreach ($visibleIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $allow[$id] = true;
            }
        }

        $keepLessons = static function (array $lessons) use ($allow): array {
            $out = [];
            foreach ($lessons as $lesson) {
                if (!is_array($lesson)) {
                    continue;
                }
                $id = (int) ($lesson['id'] ?? 0);
                if (isset($allow[$id])) {
                    $out[] = $lesson;
                }
            }
            return $out;
        };

        $intro = $all['intro'] ?? null;
        if (is_array($intro)) {
            $introId = (int) ($intro['id'] ?? 0);
            if (!isset($allow[$introId])) {
                $intro = null;
            }
        } else {
            $intro = null;
        }

        $order = $keepLessons(is_array($all['order'] ?? null) ? $all['order'] : []);

        $paths = [];
        foreach (is_array($all['paths'] ?? null) ? $all['paths'] : [] as $path) {
            if (!is_array($path)) {
                continue;
            }
            $pathLessons = $keepLessons(is_array($path['lessons'] ?? null) ? $path['lessons'] : []);
            if ($pathLessons === []) {
                continue;
            }
            $packs = [];
            foreach (is_array($path['skillPacks'] ?? null) ? $path['skillPacks'] : [] as $pack) {
                if (!is_array($pack)) {
                    continue;
                }
                $packLessons = $keepLessons(is_array($pack['lessons'] ?? null) ? $pack['lessons'] : []);
                if ($packLessons === []) {
                    continue;
                }
                $pack['lessons'] = $packLessons;
                $packs[] = $pack;
            }
            $path['lessons'] = $pathLessons;
            $path['skillPacks'] = $packs;
            $path['videoCount'] = count($pathLessons);
            $path['posterVimeoId'] = $pathLessons[0]['vimeoId'] ?? null;
            $paths[] = $path;
        }

        $out = $all;
        $out['intro'] = $intro;
        $out['order'] = $order;
        $out['paths'] = $paths;
        if (isset($all['lessons']) && is_array($all['lessons'])) {
            $lessons = [];
            foreach ($all['lessons'] as $key => $lesson) {
                if (!is_array($lesson)) {
                    continue;
                }
                $id = (int) ($lesson['id'] ?? $key);
                if (isset($allow[$id])) {
                    $lessons[$key] = $lesson;
                }
            }
            $out['lessons'] = $lessons;
        }
        return $out;
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

    public function recordPlay(int $profileId, int $lessonId, float $playedSec = 0.0): void
    {
        $this->ensureSchema();
        $day = (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('Y-m-d');
        $this->db->pdo()->prepare(
            'INSERT INTO play_events (profile_id, lesson_id, played_on, played_sec)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE played_sec = play_events.played_sec + VALUES(played_sec)'
        )->execute([$profileId, $lessonId, $day, max(0.0, $playedSec)]);
    }

    /** @return array<string,mixed> */
    public function stats(int $profileId): array
    {
        $this->ensureSchema();
        $tz = new \DateTimeZone(self::TZ);
        $today = new \DateTimeImmutable('today', $tz);
        $daysMeta = $this->rollingDays($today);
        $from = $daysMeta[0]['date'];
        $to = $daysMeta[6]['date'];
        $todayKey = $today->format('Y-m-d');

        $profiles = $this->profiles();
        $compare = [];
        $meRow = null;
        foreach ($profiles as $p) {
            $pid = (int) $p['id'];
            $row = [
                'id' => $pid,
                'slug' => $p['slug'],
                'name' => $p['name'],
                'isMe' => $pid === $profileId,
                'practiceSec' => $this->practiceTotal($pid),
                'practiceWeekSec' => $this->practiceBetween($pid, $from, $to),
                'lessonsWatched' => (int) $p['watchedCount'],
                'lessonsStarted' => $this->lessonsStarted($pid),
                'emojis' => $this->emojiStats($pid),
            ];
            $compare[] = $row;
            if ($pid === $profileId) {
                $meRow = $row;
            }
        }

        $week = [];
        foreach ($daysMeta as $meta) {
            $week[$meta['date']] = $meta + [
                'practiceSec' => 0.0,
                'scores' => [],
            ];
        }

        $pdo = $this->db->pdo();
        $plays = $pdo->prepare(
            'SELECT played_on, SUM(played_sec) AS sec FROM play_events
             WHERE profile_id = ? AND played_on BETWEEN ? AND ? GROUP BY played_on'
        );
        $plays->execute([$profileId, $from, $to]);
        foreach ($plays->fetchAll() ?: [] as $row) {
            $key = (string) $row['played_on'];
            if (isset($week[$key])) {
                $week[$key]['practiceSec'] = (float) $row['sec'];
            }
        }
        $ratings = $pdo->prepare('SELECT score, UNIX_TIMESTAMP(created_at) AS created FROM ratings WHERE profile_id = ?');
        $ratings->execute([$profileId]);
        foreach ($ratings->fetchAll() ?: [] as $row) {
            $key = (new \DateTimeImmutable('@' . (int) $row['created']))->setTimezone($tz)->format('Y-m-d');
            if (!isset($week[$key])) {
                continue;
            }
            $score = (int) $row['score'];
            if ($score >= 1 && $score <= 4) {
                $week[$key]['scores'][] = $score;
            }
        }

        $daysStmt = $pdo->prepare('SELECT COUNT(DISTINCT played_on) FROM play_events WHERE profile_id = ?');
        $daysStmt->execute([$profileId]);
        $daysPracticed = (int) $daysStmt->fetchColumn();

        $snap = $this->snapshot($profileId);
        $weekInfo = $this->week($profileId, $snap);

        return [
            'me' => [
                'profile' => $meRow ? [
                    'id' => $meRow['id'],
                    'slug' => $meRow['slug'],
                    'name' => $meRow['name'],
                ] : null,
                'practiceSec' => $meRow['practiceSec'] ?? 0.0,
                'practiceTodaySec' => $week[$todayKey]['practiceSec'] ?? 0.0,
                'practiceWeekSec' => $meRow['practiceWeekSec'] ?? 0.0,
                'daysPracticed' => $daysPracticed,
                'lessonsStarted' => $meRow['lessonsStarted'] ?? 0,
                'lessonsWatched' => $meRow['lessonsWatched'] ?? 0,
                'streak' => (int) ($weekInfo['streak'] ?? 0),
                'emojis' => $meRow['emojis'] ?? $this->emojiStats($profileId),
                'week' => array_values($week),
            ],
            'profiles' => $compare,
        ];
    }

    /** @param array<string,mixed> $snap @return array<string,mixed> */
    public function week(int $profileId, array $snap): array
    {
        $this->ensureSchema();
        $this->backfillPlays($profileId, $snap);
        $tz = new \DateTimeZone(self::TZ);
        $today = new \DateTimeImmutable('today', $tz);
        $daysMeta = $this->rollingDays($today);
        $from = $daysMeta[0]['date'];
        $to = $daysMeta[6]['date'];

        $byDay = [];
        foreach ($daysMeta as $meta) {
            $byDay[$meta['date']] = $meta + [
                'played' => false,
                'scores' => [],
                'lessonIds' => [],
            ];
        }

        $progressByLesson = is_array($snap['progress'] ?? null) ? $snap['progress'] : [];

        $pdo = $this->db->pdo();
        $plays = $pdo->prepare('SELECT lesson_id, played_on FROM play_events WHERE profile_id = ? AND played_on BETWEEN ? AND ?');
        $plays->execute([$profileId, $from, $to]);
        foreach ($plays->fetchAll() ?: [] as $row) {
            $key = (string) $row['played_on'];
            if (!isset($byDay[$key])) {
                continue;
            }
            $id = (int) $row['lesson_id'];
            if (!self::isFollowed(self::progressFor($progressByLesson, $id))) {
                continue;
            }
            $byDay[$key]['played'] = true;
            if (!in_array($id, $byDay[$key]['lessonIds'], true)) {
                $byDay[$key]['lessonIds'][] = $id;
            }
        }

        $ratings = $pdo->prepare('SELECT lesson_id, score, UNIX_TIMESTAMP(created_at) AS created FROM ratings WHERE profile_id = ?');
        $ratings->execute([$profileId]);
        foreach ($ratings->fetchAll() ?: [] as $row) {
            $key = (new \DateTimeImmutable('@' . (int) $row['created']))->setTimezone($tz)->format('Y-m-d');
            if (!isset($byDay[$key])) {
                continue;
            }
            $id = (int) $row['lesson_id'];
            if (!self::isFollowed(self::progressFor($progressByLesson, $id))) {
                continue;
            }
            $score = (int) $row['score'];
            if ($score >= 1 && $score <= 4) {
                $byDay[$key]['scores'][] = $score;
            }
            if (!in_array($id, $byDay[$key]['lessonIds'], true)) {
                $byDay[$key]['lessonIds'][] = $id;
            }
            $byDay[$key]['played'] = true;
        }

        $days = array_values($byDay);
        $streak = 0;
        for ($i = count($days) - 1; $i >= 0; $i--) {
            if (!empty($days[$i]['played'])) {
                $streak++;
            } else {
                break;
            }
        }

        return [
            'days' => $days,
            'streak' => $streak,
            'playedToday' => !empty($days[count($days) - 1]['played']),
        ];
    }

    /**
     * Last 7 days, oldest first (left) and today last (right).
     *
     * @return list<array{date:string,label:string,labelShort:string,isToday:bool,isFuture:bool}>
     */
    private function rollingDays(\DateTimeImmutable $today): array
    {
        $weekday = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
        $days = [];
        for ($ago = 6; $ago >= 0; $ago--) {
            $d = $today->modify('-' . $ago . ' days');
            $label = match ($ago) {
                0 => 'vandaag',
                1 => 'gisteren',
                2 => 'eergisteren',
                default => $weekday[(int) $d->format('w')],
            };
            $labelShort = match ($ago) {
                0 => 'nu',
                1 => 'gister',
                2 => 'eerg.',
                default => $label,
            };
            $days[] = [
                'date' => $d->format('Y-m-d'),
                'label' => $label,
                'labelShort' => $labelShort,
                'isToday' => $ago === 0,
                'isFuture' => false,
            ];
        }
        return $days;
    }

    /** @param array<string,mixed> $snap */
    private function backfillPlays(int $profileId, array $snap): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM play_events WHERE profile_id = ?');
        $stmt->execute([$profileId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
        $tz = new \DateTimeZone(self::TZ);
        $ins = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO play_events (profile_id, lesson_id, played_on) VALUES (?, ?, ?)'
        );
        foreach ($snap['progress'] as $row) {
            $ts = (int) ($row['updated'] ?? 0);
            if ($ts <= 0) {
                continue;
            }
            $day = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d');
            $ins->execute([$profileId, (int) $row['lessonId'], $day]);
        }
        foreach ($snap['ratings'] as $lid => $list) {
            foreach ($list as $r) {
                $ts = (int) ($r['at'] ?? 0);
                if ($ts <= 0) {
                    continue;
                }
                $day = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d');
                $ins->execute([$profileId, (int) $lid, $day]);
            }
        }
    }

    private function ensureColumn(string $table, string $column, string $ddl): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->pdo()->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN ' . $ddl);
        }
    }

    private function backfillPlayedSec(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            'UPDATE watch_progress
             SET played_sec = IF(watched = 1, GREATEST(duration_sec, position_sec), GREATEST(position_sec, 0))
             WHERE played_sec = 0 AND (position_sec > 0 OR watched = 1 OR duration_sec > 0)'
        );
        $ids = $pdo->query('SELECT id FROM profiles')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $id) {
            $pid = (int) $id;
            $count = $pdo->prepare('SELECT COUNT(*) FROM play_events WHERE profile_id = ?');
            $count->execute([$pid]);
            if ((int) $count->fetchColumn() === 0) {
                $this->backfillPlays($pid, $this->snapshot($pid));
            }
        }
        $rows = $pdo->query(
            'SELECT profile_id, COALESCE(SUM(played_sec), 0) AS sec, COUNT(*) AS n
             FROM play_events GROUP BY profile_id'
        )->fetchAll() ?: [];
        $copy = $pdo->prepare(
            'UPDATE play_events e
             INNER JOIN (
                SELECT profile_id, lesson_id, MAX(played_on) AS d
                FROM play_events WHERE profile_id = ?
                GROUP BY profile_id, lesson_id
             ) t ON t.profile_id = e.profile_id AND t.lesson_id = e.lesson_id AND t.d = e.played_on
             INNER JOIN watch_progress w ON w.profile_id = e.profile_id AND w.lesson_id = e.lesson_id
             SET e.played_sec = w.played_sec
             WHERE e.profile_id = ? AND e.played_sec = 0 AND w.played_sec > 0'
        );
        foreach ($rows as $row) {
            if ((float) $row['sec'] > 0 || (int) $row['n'] === 0) {
                continue;
            }
            $pid = (int) $row['profile_id'];
            $copy->execute([$pid, $pid]);
        }
    }

    private function practiceTotal(int $profileId): float
    {
        $stmt = $this->db->pdo()->prepare('SELECT COALESCE(SUM(played_sec), 0) FROM watch_progress WHERE profile_id = ?');
        $stmt->execute([$profileId]);
        return (float) $stmt->fetchColumn();
    }

    private function practiceBetween(int $profileId, string $from, string $to): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(played_sec), 0) FROM play_events WHERE profile_id = ? AND played_on BETWEEN ? AND ?'
        );
        $stmt->execute([$profileId, $from, $to]);
        return (float) $stmt->fetchColumn();
    }

    private function lessonsStarted(int $profileId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM watch_progress WHERE profile_id = ? AND (played_sec > 0 OR position_sec > 0 OR watched = 1)');
        $stmt->execute([$profileId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function emojiStats(int $profileId): array
    {
        $all = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $latest = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $first = [];
        $last = [];
        $counts = [];
        $stmt = $this->db->pdo()->prepare('SELECT lesson_id, score FROM ratings WHERE profile_id = ? ORDER BY id ASC');
        $stmt->execute([$profileId]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $score = (int) $row['score'];
            if ($score < 1 || $score > 4) {
                continue;
            }
            $all[$score]++;
            $lid = (string) $row['lesson_id'];
            if (!isset($first[$lid])) {
                $first[$lid] = $score;
            }
            $last[$lid] = $score;
            $counts[$lid] = ($counts[$lid] ?? 0) + 1;
        }
        foreach ($last as $score) {
            $latest[$score]++;
        }
        $improved = 0;
        $declined = 0;
        $sumLast = 0;
        $sumFirst = 0;
        $lessons = [];
        foreach ($last as $lid => $score) {
            $sumLast += $score;
            $sumFirst += $first[$lid];
            if ($score > $first[$lid]) {
                $improved++;
            } elseif ($score < $first[$lid]) {
                $declined++;
            }
            $lessons[] = [
                'lessonId' => (int) $lid,
                'first' => $first[$lid],
                'latest' => $score,
                'count' => $counts[$lid] ?? 1,
            ];
        }
        $n = count($last);
        return [
            'all' => $all,
            'latest' => $latest,
            'total' => array_sum($all),
            'lessonsRated' => $n,
            'avgLatest' => $n ? round($sumLast / $n, 2) : 0,
            'avgFirst' => $n ? round($sumFirst / $n, 2) : 0,
            'improved' => $improved,
            'declined' => $declined,
            'lessons' => $lessons,
        ];
    }

    /** @param mixed $lesson */
    private static function orderLessonId(mixed $lesson): int
    {
        if (is_array($lesson)) {
            return (int) ($lesson['id'] ?? 0);
        }
        return is_numeric($lesson) ? (int) $lesson : 0;
    }

    /**
     * @param array<int|string, mixed> $progressByLesson
     * @return array<string,mixed>|null
     */
    private static function progressFor(array $progressByLesson, int $id): ?array
    {
        $row = $progressByLesson[(string) $id] ?? $progressByLesson[$id] ?? null;
        return is_array($row) ? $row : null;
    }
}
