<?php

declare(strict_types=1);

namespace Drumeo\App;

use PDO;

final class Progress
{
    public const WATCHED_RATIO = 0.85;
    public const HISTORY_RATIO = 0.33;
    public const BUCKET_SEC = 3;
    /** Real media length differs from catalog seconds by well under a second. */
    public const DURATION_TOLERANCE = 2.0;
    /** Duration ceiling for a lesson without catalog seconds. */
    public const MAX_UNKNOWN_DURATION = 14400.0;
    /** Bucket cap when the duration is unknown. */
    public const DEFAULT_BUCKET_CAP = 400;
    /** Per-save play time cap when the duration is unknown. */
    public const MAX_UNKNOWN_DELTA = 1200.0;
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
        $orderSet = self::orderIdSet($this->catalog->orderedLessons());
        $pdo = $this->db->pdo();
        $rows = $pdo->query(
            'SELECT p.id, p.slug, p.name, p.hide_future, COALESCE(s.last_audio_index, 1) AS last_audio_index,
                    (SELECT UNIX_TIMESTAMP(MAX(w.updated_at)) FROM watch_progress w WHERE w.profile_id = p.id) AS last_played
             FROM profiles p
             LEFT JOIN profile_state s ON s.profile_id = p.id
             ORDER BY p.id'
        )->fetchAll() ?: [];
        $watched = [];
        foreach ($pdo->query('SELECT profile_id, lesson_id FROM watch_progress WHERE watched = 1')->fetchAll() ?: [] as $w) {
            $watched[(int) $w['profile_id']][] = (int) $w['lesson_id'];
        }
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['hideFuture'] = ((int) ($row['hide_future'] ?? 1)) !== 0;
            $row['lastAudioIndex'] = ((int) $row['last_audio_index'] === 1) ? 1 : 0;
            // Loose lessons are not part of the Method count shown as "x / 322".
            $row['watchedCount'] = self::countInOrder($watched[$row['id']] ?? [], $orderSet);
            $row['lessonCount'] = count($orderSet);
            $row['lastPlayed'] = $row['last_played'] !== null ? (int) $row['last_played'] : null;
            unset($row['last_audio_index'], $row['last_played'], $row['hide_future']);
        }
        unset($row);
        return $rows;
    }

    /**
     * Lesson id → progress row, as used by snapshot() and the visibility checks.
     *
     * @return array<string,array<string,mixed>>
     */
    public function progressMap(int $profileId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lesson_id, vimeo_id, position_sec, duration_sec, watched, UNIX_TIMESTAMP(updated_at) AS updated
             FROM watch_progress WHERE profile_id = ?'
        );
        $stmt->execute([$profileId]);
        $byLesson = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $byLesson[(string) $row['lesson_id']] = [
                'lessonId' => (int) $row['lesson_id'],
                'vimeoId' => $row['vimeo_id'],
                'position' => (float) $row['position_sec'],
                'duration' => (float) $row['duration_sec'],
                'watched' => (bool) $row['watched'],
                'updated' => (int) $row['updated'],
            ];
        }
        return $byLesson;
    }

    /** @return array<string,mixed> */
    public function snapshot(int $profileId): array
    {
        $pdo = $this->db->pdo();

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

        $stateStmt = $pdo->prepare('SELECT last_audio_index, path_view FROM profile_state WHERE profile_id = ?');
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
            'progress' => $this->progressMap($profileId),
            'ratings' => $ratingMap,
            'latestScore' => $latest,
            'notes' => $notes,
            'lastAudioIndex' => is_array($state) ? ((int) $state['last_audio_index'] === 1 ? 1 : 0) : 1,
            'pathView' => (is_array($state) && ($state['path_view'] ?? '') === 'skill') ? 'skill' : 'order',
        ];
    }

    public function saveNote(int $profileId, int $lessonId, string $body): string
    {
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
            'INSERT INTO lesson_notes (profile_id, lesson_id, body) VALUES (?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE body = new.body'
        )->execute([$profileId, $lessonId, $body]);
        return $body;
    }

    /**
     * Merge a progress ping into the stored row under a row lock, so a reset or a
     * second ping between the read and the write cannot be lost.
     *
     * $vimeoId and $duration must come from the catalog (see clampDuration()).
     *
     * @param list<mixed> $playedBuckets
     * @return array{watched: bool, position: float}
     */
    public function saveProgress(int $profileId, int $lessonId, string $vimeoId, float $position, float $duration, float $playedDelta = 0.0, array $playedBuckets = []): array
    {
        $pdo = $this->db->pdo();
        // Make sure the row exists, so the locking read takes a row lock rather
        // than a gap lock (two gap locks on a new key deadlock on insert).
        $pdo->prepare(
            'INSERT INTO watch_progress (profile_id, lesson_id, vimeo_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE lesson_id = lesson_id'
        )->execute([$profileId, $lessonId, $vimeoId]);

        $pdo->beginTransaction();
        try {
            $existing = $pdo->prepare('SELECT played_buckets, watched FROM watch_progress WHERE profile_id = ? AND lesson_id = ? FOR UPDATE');
            $existing->execute([$profileId, $lessonId]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            $storedJson = is_array($row) && is_string($row['played_buckets'] ?? null) ? $row['played_buckets'] : null;
            $prevBuckets = self::mergeBuckets($storedJson, [], $duration);
            $merged = self::mergeBuckets($storedJson, $playedBuckets, $duration);
            $watched = (is_array($row) && !empty($row['watched'])) || self::qualifiesWatched(count($merged), $duration);
            $bucketGain = (float) (max(0, count($merged) - count($prevBuckets)) * self::BUCKET_SEC);
            $coverage = (float) (count($merged) * self::BUCKET_SEC);
            if ($duration > 0) {
                $coverage = min($coverage, $duration);
            }
            $pdo->prepare(
                'UPDATE watch_progress
                 SET vimeo_id = ?, position_sec = ?, duration_sec = ?, watched = ?, played_sec = ?, played_buckets = ?
                 WHERE profile_id = ? AND lesson_id = ?'
            )->execute([
                $vimeoId,
                $position,
                $duration,
                $watched ? 1 : 0,
                $coverage,
                json_encode($merged, JSON_THROW_ON_ERROR),
                $profileId,
                $lessonId,
            ]);
            $this->recordPlay($profileId, $lessonId, self::playDelta($playedDelta, $bucketGain, $duration));
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['watched' => $watched, 'position' => $position];
    }

    /**
     * The duration to judge a ping by: the catalog's seconds, nudged by at most
     * DURATION_TOLERANCE towards what the player reports. Without catalog seconds
     * the client's value is used, within a sane ceiling.
     */
    public static function clampDuration(float $client, int $catalogSeconds): float
    {
        $client = (is_finite($client) && $client > 0) ? $client : 0.0;
        if ($catalogSeconds <= 0) {
            return min($client, self::MAX_UNKNOWN_DURATION);
        }
        if ($client <= 0) {
            return (float) $catalogSeconds;
        }
        $low = max(0.0, $catalogSeconds - self::DURATION_TOLERANCE);
        return min(max($client, $low), $catalogSeconds + self::DURATION_TOLERANCE);
    }

    public static function clampPosition(float $position, float $duration): float
    {
        if (!is_finite($position) || $position < 0) {
            return 0.0;
        }
        return $duration > 0 ? min($position, $duration) : $position;
    }

    /** How many buckets one ping may carry: every bucket of the lesson, plus slack for rounding at the end. */
    public static function bucketCap(float $duration): int
    {
        if ($duration <= 0) {
            return self::DEFAULT_BUCKET_CAP;
        }
        return self::maxBucketIndex($duration) + 1 + 4;
    }

    /** Seconds of play to credit for one ping: the larger of the client delta and new bucket coverage, capped. */
    public static function playDelta(float $playedDelta, float $bucketGain, float $duration): float
    {
        $delta = max(0.0, is_finite($playedDelta) ? $playedDelta : 0.0, $bucketGain);
        return min($delta, $duration > 0 ? $duration : self::MAX_UNKNOWN_DELTA);
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
            'INSERT INTO profile_state (profile_id, last_audio_index) VALUES (?, ?) AS new
             ON DUPLICATE KEY UPDATE last_audio_index = new.last_audio_index'
        )->execute([$profileId, $audioIndex]);
        return $audioIndex;
    }

    public function setPathView(int $profileId, string $view): string
    {
        $view = $view === 'skill' ? 'skill' : 'order';
        $this->db->pdo()->prepare(
            'INSERT INTO profile_state (profile_id, path_view) VALUES (?, ?) AS new
             ON DUPLICATE KEY UPDATE path_view = new.path_view'
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
    public function resume(array $snap): ?array
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

    /**
     * Set of lesson ids in the Method order (intro + paths[].order).
     *
     * @param list<int|array<string,mixed>> $order
     * @return array<int,true>
     */
    public static function orderIdSet(array $order): array
    {
        $set = [];
        foreach ($order as $lesson) {
            $id = self::orderLessonId($lesson);
            if ($id > 0) {
                $set[$id] = true;
            }
        }
        return $set;
    }

    /**
     * Distinct lesson ids that belong to the Method order.
     *
     * @param list<int|string> $lessonIds
     * @param array<int,true> $orderSet
     */
    public static function countInOrder(array $lessonIds, array $orderSet): int
    {
        $seen = [];
        foreach ($lessonIds as $id) {
            $id = (int) $id;
            if (isset($orderSet[$id])) {
                $seen[$id] = true;
            }
        }
        return count($seen);
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

    /** Add play time to today's (Europe/Brussels) row. Zero seconds writes nothing, so it never counts as a practice day. */
    public function recordPlay(int $profileId, int $lessonId, float $playedSec): void
    {
        if (!is_finite($playedSec) || $playedSec <= 0) {
            return;
        }
        $day = (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('Y-m-d');
        $this->db->pdo()->prepare(
            'INSERT INTO play_events (profile_id, lesson_id, played_on, played_sec)
             VALUES (?, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE played_sec = play_events.played_sec + new.played_sec'
        )->execute([$profileId, $lessonId, $day, $playedSec]);
    }

    /** @return array<string,mixed> */
    public function stats(int $profileId): array
    {
        $tz = new \DateTimeZone(self::TZ);
        $today = new \DateTimeImmutable('today', $tz);
        $daysMeta = $this->rollingDays($today);
        $from = $daysMeta[0]['date'];
        $to = $daysMeta[6]['date'];
        $todayKey = $today->format('Y-m-d');
        $orderSet = self::orderIdSet($this->catalog->orderedLessons());

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
                'lessonsStarted' => $this->lessonsStarted($pid, $orderSet),
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

        $daysStmt = $pdo->prepare('SELECT COUNT(DISTINCT played_on) FROM play_events WHERE profile_id = ? AND played_sec > 0');
        $daysStmt->execute([$profileId]);
        $daysPracticed = (int) $daysStmt->fetchColumn();

        $weekInfo = $this->week($profileId, $this->snapshot($profileId));

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
        $plays = $pdo->prepare(
            'SELECT lesson_id, played_on FROM play_events
             WHERE profile_id = ? AND played_on BETWEEN ? AND ? AND played_sec > 0'
        );
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

    /** All-time play time, from the same table as the daily and weekly totals. */
    private function practiceTotal(int $profileId): float
    {
        $stmt = $this->db->pdo()->prepare('SELECT COALESCE(SUM(played_sec), 0) FROM play_events WHERE profile_id = ?');
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

    /** @param array<int,true> $orderSet */
    private function lessonsStarted(int $profileId, array $orderSet): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT lesson_id FROM watch_progress WHERE profile_id = ? AND (played_sec > 0 OR position_sec > 0 OR watched = 1)');
        $stmt->execute([$profileId]);
        return self::countInOrder($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [], $orderSet);
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
