<?php

declare(strict_types=1);

namespace Drumeo\App;

final class Catalog
{
    /** @var array<string,mixed>|null */
    private static ?array $memo = null;

    public function __construct(
        private readonly Config $config,
        private readonly RedisCache $cache,
    ) {
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $cached = $this->cache->get('catalog:v2');
        if (is_array($cached) && isset($cached['lessons'])) {
            self::$memo = $cached;
            return $cached;
        }
        $built = $this->build();
        $this->cache->set('catalog:v2', $built, 600);
        self::$memo = $built;
        return $built;
    }

    /** @return array<string,mixed>|null */
    public function lesson(int $id): ?array
    {
        return $this->all()['lessons'][(string) $id] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function path(string $slug): ?array
    {
        foreach ($this->all()['paths'] as $path) {
            if (($path['slug'] ?? '') === $slug) {
                return $path;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function orderedLessons(): array
    {
        return $this->all()['order'];
    }

    public function nextLessonId(?int $currentId): ?int
    {
        $order = $this->orderedLessons();
        if ($currentId === null) {
            return isset($order[0]) ? (int) $order[0]['id'] : null;
        }
        foreach ($order as $i => $lesson) {
            if ((int) $lesson['id'] === $currentId) {
                return isset($order[$i + 1]) ? (int) $order[$i + 1]['id'] : null;
            }
        }
        return null;
    }

    public function mediaPath(string $vimeoId, string $ext): ?string
    {
        $index = $this->mediaIndex();
        $key = $vimeoId . '.' . strtolower($ext);
        return $index[$key] ?? null;
    }

    /** @return array<string,bool> */
    public function availableVideos(): array
    {
        $out = [];
        foreach ($this->mediaIndex() as $key => $_) {
            if (str_ends_with($key, '.mkv')) {
                $out[substr($key, 0, -4)] = true;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function build(): array
    {
        $lessonsDir = $this->resolveLessonsDir();
        $indexFile = $lessonsDir . '/index.json';
        $index = $this->readJson($indexFile) ?? [];
        $intro = null;
        $paths = [];
        $lessons = [];
        $order = [];
        $orderIds = [];

        $introMeta = $index['method_intro'] ?? null;
        if (is_array($introMeta) && !empty($introMeta['file'])) {
            $introLesson = $this->loadLesson($lessonsDir, $introMeta['file'], $introMeta);
            if ($introLesson) {
                $introLesson['pathSlug'] = null;
                $introLesson['pathTitle'] = 'The Method';
                $intro = $introLesson;
                $lessons[(string) $introLesson['id']] = $introLesson;
                $thinIntro = $this->thin($introLesson);
                $order[] = $thinIntro;
                $orderIds[(string) $introLesson['id']] = true;
            }
        }

        foreach ($index['learning_paths'] ?? [] as $pathInfo) {
            if (!is_array($pathInfo)) {
                continue;
            }
            $folder = $lessonsDir . '/' . basename((string) ($pathInfo['slug'] ?? $pathInfo['folder'] ?? ''));
            $pathJson = $this->readJson($folder . '/_path.json');
            if ($pathJson === null) {
                $rel = (string) ($pathInfo['index'] ?? '');
                $pathJson = $this->readJson($lessonsDir . '/../' . $rel);
            }
            $lp = is_array($pathJson['learning_path'] ?? null) ? $pathJson['learning_path'] : $pathInfo;
            $path = [
                'id' => (int) ($lp['id'] ?? $pathInfo['id'] ?? 0),
                'slug' => (string) ($lp['slug'] ?? $pathInfo['slug'] ?? ''),
                'title' => (string) ($lp['title'] ?? $pathInfo['title'] ?? ''),
                'difficulty' => (string) ($lp['difficulty_string'] ?? $pathInfo['difficulty_string'] ?? ''),
                'description' => (string) ($lp['description'] ?? ''),
                'resources' => $pathJson['resources'] ?? [],
                'videoCount' => 0,
                'skillPacks' => [],
                'lessons' => [],
            ];
            $packs = [];
            foreach ($pathJson['videos'] ?? [] as $video) {
                if (!is_array($video)) {
                    continue;
                }
                $file = (string) ($video['file'] ?? '');
                $lesson = $this->loadLesson($lessonsDir, $file, $video);
                if (!$lesson) {
                    continue;
                }
                $lesson['pathSlug'] = $path['slug'];
                $lesson['pathTitle'] = $path['title'];
                $lesson['pathId'] = $path['id'];
                $packKey = $lesson['skillPackId'] !== null ? (string) $lesson['skillPackId'] : ($lesson['role'] === 'path-intro' ? 'intro' : 'other');
                if (!isset($packs[$packKey])) {
                    $packs[$packKey] = [
                        'id' => $lesson['skillPackId'],
                        'title' => $lesson['skillPackTitle'] ?? ($lesson['role'] === 'path-intro' ? 'Welcome' : 'More'),
                        'lessons' => [],
                    ];
                }
                $packs[$packKey]['lessons'][] = $this->thin($lesson);
                $lessons[(string) $lesson['id']] = $lesson;
            }
            $path['skillPacks'] = array_values($packs);
            $path['lessons'] = [];
            foreach ($path['skillPacks'] as $pack) {
                foreach ($pack['lessons'] as $thin) {
                    $path['lessons'][] = $thin;
                    $id = (string) $thin['id'];
                    if (!isset($orderIds[$id])) {
                        $orderIds[$id] = true;
                        $order[] = $thin;
                    }
                }
            }
            $path['videoCount'] = count($path['lessons']);
            if ($path['lessons'] !== []) {
                $path['posterVimeoId'] = $path['lessons'][0]['vimeoId'] ?? null;
            }
            $paths[] = $path;
        }

        foreach ($order as $i => $item) {
            $id = (string) $item['id'];
            $prev = $i > 0 ? $order[$i - 1]['id'] : null;
            $next = $i + 1 < count($order) ? $order[$i + 1]['id'] : null;
            $lessons[$id]['prevId'] = $prev;
            $lessons[$id]['nextId'] = $next;
            $order[$i]['prevId'] = $prev;
            $order[$i]['nextId'] = $next;
        }
        foreach ($paths as $pi => $path) {
            foreach ($path['lessons'] as $li => $l) {
                $id = (string) $l['id'];
                $paths[$pi]['lessons'][$li]['prevId'] = $lessons[$id]['prevId'] ?? null;
                $paths[$pi]['lessons'][$li]['nextId'] = $lessons[$id]['nextId'] ?? null;
            }
        }
        if (is_array($intro)) {
            $intro['prevId'] = $lessons[(string) $intro['id']]['prevId'] ?? null;
            $intro['nextId'] = $lessons[(string) $intro['id']]['nextId'] ?? null;
        }

        return [
            'intro' => $intro,
            'paths' => $paths,
            'lessons' => $lessons,
            'order' => $order,
        ];
    }

    /** @param array<string,mixed> $hint */
    private function loadLesson(string $lessonsDir, string $rel, array $hint): ?array
    {
        $rel = preg_replace('#^lessons/#', '', $rel) ?? $rel;
        $path = $lessonsDir . '/' . ltrim($rel, '/');
        if (!is_file($path)) {
            $base = basename($rel);
            $found = glob($lessonsDir . '/*/' . $base) ?: [];
            $path = $found[0] ?? $path;
        }
        $raw = $this->readJson($path);
        $lesson = is_array($raw['lesson'] ?? null) ? $raw['lesson'] : [];
        $video = is_array($raw['video'] ?? null) ? $raw['video'] : [];
        $hier = is_array($raw['hierarchy'] ?? null) ? $raw['hierarchy'] : [];
        $pack = is_array($hier['skill_pack'] ?? null) ? $hier['skill_pack'] : [];
        $id = (int) ($lesson['id'] ?? $hint['id'] ?? 0);
        if ($id === 0) {
            return null;
        }
        $vimeo = (string) ($video['vimeo_id'] ?? $hint['vimeo_id'] ?? '');
        $seconds = (int) ($lesson['length_in_seconds'] ?? $hint['seconds'] ?? 0);
        $role = (string) ($raw['role'] ?? $hint['role'] ?? 'lesson');
        $type = (string) ($lesson['type'] ?? $hint['type'] ?? $role);
        $packTitle = $pack['title'] ?? null;
        if ($packTitle === null) {
            $packTitle = match ($role) {
                'path-intro', 'method-intro' => 'Welcome',
                default => str_contains($type, 'song') ? 'Learn the Song' : null,
            };
        }
        $tracks = [];
        foreach ($video['audio_tracks_sampled'] ?? [] as $i => $label) {
            if (is_string($label)) {
                $tracks[] = ['index' => $i, 'label' => $label];
            }
        }
        return [
            'id' => $id,
            'title' => (string) ($lesson['title'] ?? $hint['title'] ?? 'Lesson'),
            'slug' => (string) ($lesson['slug'] ?? ''),
            'role' => $role,
            'type' => $type,
            'vimeoId' => $vimeo,
            'seconds' => $seconds,
            'length' => (string) ($lesson['length'] ?? $this->fmt($seconds)),
            'difficulty' => (string) ($lesson['difficulty_string'] ?? ''),
            'description' => $lesson['description'] ?? null,
            'instructor' => $lesson['instructor']['name'] ?? null,
            'instructorThumb' => $lesson['instructor']['thumbnail_url'] ?? null,
            'cmsThumb' => $lesson['thumbnail_url'] ?? ($video['urls']['poster'] ?? null),
            'skillPackId' => isset($pack['id']) ? (int) $pack['id'] : null,
            'skillPackTitle' => $packTitle,
            'resources' => $raw['path_resources'] ?? [],
            'audioLabels' => $tracks,
        ];
    }

    /** @param array<string,mixed> $lesson @return array<string,mixed> */
    private function thin(array $lesson): array
    {
        return [
            'id' => $lesson['id'],
            'title' => $lesson['title'],
            'vimeoId' => $lesson['vimeoId'],
            'seconds' => $lesson['seconds'],
            'length' => $lesson['length'],
            'role' => $lesson['role'],
            'type' => $lesson['type'],
            'skillPackTitle' => $lesson['skillPackTitle'] ?? null,
            'instructor' => $lesson['instructor'] ?? null,
            'pathSlug' => $lesson['pathSlug'] ?? null,
            'pathTitle' => $lesson['pathTitle'] ?? null,
        ];
    }

    private function fmt(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m . ':' . str_pad((string) $s, 2, '0', STR_PAD_LEFT);
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function resolveLessonsDir(): string
    {
        $dir = $this->config->lessonsDir;
        if (is_dir($dir)) {
            return rtrim($dir, '/');
        }
        $fallback = dirname(__DIR__, 2) . '/lessons';
        return is_dir($fallback) ? $fallback : $dir;
    }

    /** @return array<string,string> */
    private function mediaIndex(): array
    {
        static $local = null;
        if (is_array($local)) {
            return $local;
        }
        $cached = $this->cache->get('media-index:v1');
        if (is_array($cached)) {
            $local = $cached;
            return $local;
        }
        $fileCache = sys_get_temp_dir() . '/drumeo-media-index.json';
        if (is_file($fileCache) && (time() - (filemtime($fileCache) ?: 0)) < 900) {
            $decoded = json_decode((string) file_get_contents($fileCache), true);
            if (is_array($decoded)) {
                $local = $decoded;
                return $local;
            }
        }
        $dir = $this->config->mediaDir;
        $map = [];
        if (is_dir($dir)) {
            $handle = @opendir($dir);
            if ($handle !== false) {
                while (($entry = readdir($handle)) !== false) {
                    if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                        continue;
                    }
                    if (!preg_match('/\[([a-zA-Z0-9_-]+)\]\.(mkv|jpg|jpeg|png)$/i', $entry, $m)
                        && !preg_match('/^([a-zA-Z0-9_-]+)\.(mkv|jpg|jpeg|png)$/i', $entry, $m)
                    ) {
                        continue;
                    }
                    $id = $m[1];
                    $ext = strtolower($m[2] === 'jpeg' ? 'jpg' : $m[2]);
                    if ($ext === 'jpeg') {
                        $ext = 'jpg';
                    }
                    $map[$id . '.' . $ext] = $dir . '/' . $entry;
                }
                closedir($handle);
            }
        }
        $this->cache->set('media-index:v1', $map, 900);
        @file_put_contents($fileCache, json_encode($map));
        $local = $map;
        return $map;
    }
}
