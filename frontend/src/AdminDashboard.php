<?php

declare(strict_types=1);

namespace Drumeo\App;

final class AdminDashboard
{
    public const ADMIN_SLUG = 'wouter';
    private const VIDEO_CACHE_KEY = 'admin:video:v1';
    private const LIST_CAP = 30;

    public function __construct(
        private readonly Config $config,
        private readonly VideoAdminClient $video,
        private readonly Catalog $catalog,
        private readonly Progress $progress,
        private readonly RedisCache $cache,
        private readonly Database $db,
    ) {
    }

    /** @param array<string,mixed>|null $profile */
    public static function isAdmin(?array $profile): bool
    {
        return is_array($profile) && ($profile['slug'] ?? '') === self::ADMIN_SLUG;
    }

    /** @return array<string,mixed> */
    public function overview(bool $fresh = false): array
    {
        $video = null;
        $videoError = null;
        $cached = $fresh ? null : $this->cache->get(self::VIDEO_CACHE_KEY);
        if (is_array($cached) && isset($cached['source']) && is_array($cached['source'])) {
            $video = $cached;
        } else {
            try {
                $video = $this->video->overview();
                $this->cache->set(self::VIDEO_CACHE_KEY, $video, 20);
            } catch (\Throwable $e) {
                $videoError = $e->getMessage();
            }
        }

        $lessons = $this->catalog->all()['lessons'] ?? [];
        if (!is_array($lessons)) {
            $lessons = [];
        }
        $catalog = null;
        if (is_array($video)) {
            $ids = $video['source']['ids'] ?? [];
            $catalog = self::catalogReport($lessons, is_array($ids) ? $ids : []);
            $video = self::annotateVideo($video, $lessons);
        }

        $encoder = is_array($video) && is_array($video['encoder'] ?? null) ? $video['encoder'] : [];

        return [
            'generatedAt' => is_array($video) && is_string($video['now'] ?? null) ? $video['now'] : gmdate('c'),
            'videoError' => $videoError,
            'video' => $video,
            'catalog' => $catalog,
            'images' => [
                'cache' => DirStats::of($this->config->imageCacheDir),
                'thumbs' => DirStats::of($this->config->thumbsDir),
            ],
            'database' => $this->database(),
            'services' => [
                'mysql' => true,
                'redis' => $this->cache->ping(),
                'video' => $videoError === null && is_array($video),
                'ffmpeg' => is_array($video) ? ($video['ffmpeg'] ?? null) : null,
                'encoder' => isset($encoder['encoder']) ? $encoder['encoder'] : null,
                'encoderRequested' => isset($encoder['requested']) ? $encoder['requested'] : null,
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'memoryLimit' => (string) (ini_get('memory_limit') ?: ''),
            ],
            'profiles' => $this->profileRows(),
        ];
    }

    /** @return array<string,mixed> */
    public function cleanup(): array
    {
        $this->cache->del(self::VIDEO_CACHE_KEY);
        $result = $this->video->cleanup();
        $this->cache->del(self::VIDEO_CACHE_KEY);
        return $result;
    }

    /**
     * @param iterable<mixed> $lessons
     * @param list<mixed> $sourceIds
     * @return array<string,mixed>
     */
    public static function catalogReport(iterable $lessons, array $sourceIds): array
    {
        /** @var array<string,array<string,mixed>> $catalogIds */
        $catalogIds = [];
        $lessonCount = 0;
        foreach ($lessons as $lesson) {
            if (!is_array($lesson)) {
                continue;
            }
            $lessonCount++;
            $id = (string) ($lesson['vimeoId'] ?? '');
            if ($id === '') {
                continue;
            }
            $catalogIds[$id] = $lesson;
        }
        $sourceSet = [];
        foreach ($sourceIds as $id) {
            $id = (string) $id;
            if ($id !== '') {
                $sourceSet[$id] = true;
            }
        }

        $missing = [];
        foreach ($catalogIds as $id => $lesson) {
            if (!isset($sourceSet[$id])) {
                $missing[] = self::lessonRef($lesson);
            }
        }
        $extra = [];
        foreach (array_keys($sourceSet) as $id) {
            if (!isset($catalogIds[$id])) {
                $extra[] = $id;
            }
        }
        sort($extra, SORT_STRING);

        return [
            'lessonCount' => $lessonCount,
            'sourceCount' => count($sourceSet),
            'withSource' => count($catalogIds) - count($missing),
            'missingCount' => count($missing),
            'extraCount' => count($extra),
            'missing' => array_slice($missing, 0, self::LIST_CAP),
            'extra' => array_slice($extra, 0, self::LIST_CAP),
        ];
    }

    /**
     * Attach lesson titles and drop the raw source-id list before it reaches the browser.
     *
     * @param array<string,mixed> $video
     * @param iterable<mixed> $lessons
     * @return array<string,mixed>
     */
    public static function annotateVideo(array $video, iterable $lessons): array
    {
        /** @var array<string,array<string,mixed>> $by */
        $by = [];
        foreach ($lessons as $lesson) {
            if (!is_array($lesson)) {
                continue;
            }
            $id = (string) ($lesson['vimeoId'] ?? '');
            if ($id !== '') {
                $by[$id] = $lesson;
            }
        }

        if (isset($video['cleanup']['items']) && is_array($video['cleanup']['items'])) {
            $video['cleanup']['items'] = self::mapItems($video['cleanup']['items'], $by, 'id');
        }
        if (isset($video['cache']['largest']) && is_array($video['cache']['largest'])) {
            $video['cache']['largest'] = self::mapItems($video['cache']['largest'], $by, 'id');
        }
        if (isset($video['queue']) && is_array($video['queue'])) {
            $video['queue'] = self::mapItems($video['queue'], $by, 'videoId');
        }
        if (isset($video['jobs']['active']) && is_array($video['jobs']['active'])) {
            $video['jobs']['active'] = self::mapItems($video['jobs']['active'], $by, 'videoId');
        }
        if (isset($video['jobs']['recent']) && is_array($video['jobs']['recent'])) {
            $video['jobs']['recent'] = self::mapItems($video['jobs']['recent'], $by, 'videoId');
        }
        if (isset($video['source']) && is_array($video['source'])) {
            unset($video['source']['ids']);
        }
        return $video;
    }

    /**
     * @param list<mixed> $items
     * @param array<string,array<string,mixed>> $by
     * @return list<array<string,mixed>>
     */
    private static function mapItems(array $items, array $by, string $field): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string) ($item[$field] ?? $item['id'] ?? $item['videoId'] ?? '');
            $lesson = $by[$key] ?? null;
            if (is_array($lesson)) {
                $item['title'] = $lesson['title'] ?? null;
                $item['titleNl'] = $lesson['titleNl'] ?? null;
                $item['lessonId'] = $lesson['id'] ?? null;
                $item['n'] = $lesson['n'] ?? null;
            }
            $out[] = $item;
        }
        return $out;
    }

    /** @param array<string,mixed> $lesson @return array<string,mixed> */
    private static function lessonRef(array $lesson): array
    {
        return [
            'lessonId' => $lesson['id'] ?? null,
            'vimeoId' => (string) ($lesson['vimeoId'] ?? ''),
            'title' => $lesson['title'] ?? null,
            'titleNl' => $lesson['titleNl'] ?? null,
            'n' => $lesson['n'] ?? null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function profileRows(): array
    {
        $profiles = $this->progress->profiles();
        $week = [];
        try {
            $start = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Brussels')))
                ->modify('-6 days')
                ->format('Y-m-d');
            $stmt = $this->db->pdo()->prepare(
                'SELECT profile_id, COALESCE(SUM(played_sec), 0) AS sec
                 FROM play_events WHERE played_on >= ? GROUP BY profile_id'
            );
            $stmt->execute([$start]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                if (is_array($row)) {
                    $week[(int) $row['profile_id']] = (float) $row['sec'];
                }
            }
        } catch (\Throwable) {
            $week = [];
        }
        foreach ($profiles as &$profile) {
            $profile['weekSec'] = $week[(int) $profile['id']] ?? 0.0;
        }
        unset($profile);
        return $profiles;
    }

    /** @return array<string,mixed> */
    private function database(): array
    {
        try {
            $pdo = $this->db->pdo();
            $version = $pdo->query('SELECT VERSION() AS version')->fetch();
            $tables = $pdo->query(
                'SELECT table_name AS table_name, table_rows AS table_rows,
                        data_length AS data_length, index_length AS index_length
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                 ORDER BY (data_length + index_length) DESC'
            )->fetchAll() ?: [];
            $out = [];
            $bytes = 0;
            foreach ($tables as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $data = (int) ($row['data_length'] ?? 0);
                $index = (int) ($row['index_length'] ?? 0);
                $bytes += $data + $index;
                $out[] = [
                    'name' => (string) ($row['table_name'] ?? ''),
                    'rows' => (int) ($row['table_rows'] ?? 0),
                    'bytes' => $data + $index,
                ];
            }
            return [
                'version' => is_array($version) ? (string) ($version['version'] ?? '') : '',
                'bytes' => $bytes,
                'tables' => $out,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'version' => '',
                'bytes' => 0,
                'tables' => [],
                'error' => $e->getMessage(),
            ];
        }
    }
}
