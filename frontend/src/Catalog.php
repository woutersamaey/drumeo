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
        $cached = $this->cache->get('catalog:v7');
        if (is_array($cached) && isset($cached['lessons'])) {
            self::$memo = $cached;
            return $cached;
        }
        $built = $this->build();
        $this->cache->set('catalog:v7', $built, 600);
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
        $raw = $this->readJson($this->resolveCatalogPath()) ?? [];
        $lessons = [];
        foreach (is_array($raw['lessons'] ?? null) ? $raw['lessons'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lesson = $this->hydrate($row);
            if ($lesson === null) {
                continue;
            }
            $lessons[(string) $lesson['id']] = $lesson;
        }

        $intro = null;
        if (is_array($raw['intro'] ?? null)) {
            $intro = $this->hydrate($raw['intro']);
            if ($intro !== null) {
                $intro['pathSlug'] = null;
                $intro['pathTitle'] = 'The Method';
                $lessons[(string) $intro['id']] = $intro;
            }
        }

        $order = [];
        $orderIds = [];
        if (is_array($intro)) {
            $order[] = $this->thin($intro);
            $orderIds[(string) $intro['id']] = true;
        }

        $paths = [];
        foreach (is_array($raw['paths'] ?? null) ? $raw['paths'] : [] as $pathInfo) {
            if (!is_array($pathInfo)) {
                continue;
            }
            $title = $this->loc($pathInfo['title'] ?? '');
            $diff = $this->loc($pathInfo['difficulty'] ?? '');
            $desc = $this->loc($pathInfo['description'] ?? '');
            $path = [
                'id' => (int) ($pathInfo['id'] ?? 0),
                'slug' => (string) ($pathInfo['slug'] ?? ''),
                'title' => $title['en'] !== '' ? $title['en'] : (string) ($pathInfo['slug'] ?? ''),
                'titleNl' => $title['nl'] ?? '',
                'difficulty' => $diff['en'],
                'difficultyNl' => $diff['nl'] ?? '',
                'description' => $desc['en'],
                'descriptionNl' => $desc['nl'] ?? '',
                'resources' => [],
                'videoCount' => 0,
                'skillPacks' => [],
                'lessons' => [],
            ];
            $pathLessons = [];
            foreach (is_array($pathInfo['order'] ?? null) ? $pathInfo['order'] : [] as $lid) {
                $id = (string) (int) $lid;
                $lesson = $lessons[$id] ?? null;
                if ($lesson === null) {
                    continue;
                }
                $lesson['pathSlug'] = $path['slug'];
                $lesson['pathTitle'] = $path['title'];
                $lesson['pathTitleNl'] = $path['titleNl'] !== '' ? $path['titleNl'] : null;
                $lesson['pathId'] = $path['id'];
                $lessons[$id] = $lesson;
                $pathLessons[] = $this->thin($lesson);
            }
            $packs = [];
            foreach (is_array($pathInfo['packs'] ?? null) ? $pathInfo['packs'] : [] as $packInfo) {
                if (!is_array($packInfo)) {
                    continue;
                }
                $packLoc = $this->loc($packInfo['title'] ?? '');
                $packLessons = [];
                foreach (is_array($packInfo['lessons'] ?? null) ? $packInfo['lessons'] : [] as $lid) {
                    $id = (string) (int) $lid;
                    foreach ($pathLessons as $thin) {
                        if ((string) $thin['id'] === $id) {
                            $packLessons[] = $thin;
                            break;
                        }
                    }
                }
                $packs[] = [
                    'id' => isset($packInfo['id']) ? (int) $packInfo['id'] : null,
                    'title' => $packLoc['en'] !== '' ? $packLoc['en'] : 'More',
                    'titleNl' => $packLoc['nl'] ?? 'Meer',
                    'lessons' => $packLessons,
                ];
            }
            $path['skillPacks'] = $packs;
            $path['lessons'] = $pathLessons;
            foreach ($pathLessons as $thin) {
                $id = (string) $thin['id'];
                if (!isset($orderIds[$id])) {
                    $orderIds[$id] = true;
                    $order[] = $thin;
                }
            }
            $path['videoCount'] = count($path['lessons']);
            if ($path['lessons'] !== []) {
                $path['posterVimeoId'] = $path['lessons'][0]['vimeoId'] ?? null;
            }
            $paths[] = $path;
        }

        $total = count($order);
        foreach ($order as $i => $item) {
            $id = (string) $item['id'];
            $n = $i + 1;
            $prev = $i > 0 ? $order[$i - 1]['id'] : null;
            $next = $i + 1 < $total ? $order[$i + 1]['id'] : null;
            $lessons[$id]['n'] = $n;
            $lessons[$id]['prevId'] = $prev;
            $lessons[$id]['nextId'] = $next;
            $order[$i]['n'] = $n;
            $order[$i]['prevId'] = $prev;
            $order[$i]['nextId'] = $next;
        }
        foreach ($paths as $pi => $path) {
            $paths[$pi]['n'] = $pi + 1;
            foreach ($path['lessons'] as $li => $l) {
                $id = (string) $l['id'];
                $paths[$pi]['lessons'][$li]['n'] = $lessons[$id]['n'] ?? null;
                $paths[$pi]['lessons'][$li]['prevId'] = $lessons[$id]['prevId'] ?? null;
                $paths[$pi]['lessons'][$li]['nextId'] = $lessons[$id]['nextId'] ?? null;
            }
            foreach ($path['skillPacks'] as $si => $pack) {
                foreach ($pack['lessons'] as $li => $l) {
                    $id = (string) $l['id'];
                    $paths[$pi]['skillPacks'][$si]['lessons'][$li]['n'] = $lessons[$id]['n'] ?? null;
                }
            }
        }
        if (is_array($intro)) {
            $intro['n'] = $lessons[(string) $intro['id']]['n'] ?? 1;
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

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id === 0) {
            return null;
        }
        $seconds = (int) ($row['seconds'] ?? 0);
        $role = (string) ($row['role'] ?? 'lesson');
        $title = $this->loc($row['title'] ?? 'Lesson');
        $diff = $this->loc($row['difficulty'] ?? '');
        $desc = $this->loc($row['description'] ?? null);
        $pack = is_array($row['pack'] ?? null) ? $row['pack'] : [];
        $packLoc = $this->loc($pack['title'] ?? null);
        $packTitle = $packLoc['en'] !== '' ? $packLoc['en'] : null;
        if ($packTitle === null) {
            $packTitle = match ($role) {
                'path-intro', 'method-intro' => 'Welcome',
                default => null,
            };
        }
        return [
            'id' => $id,
            'title' => $title['en'] !== '' ? $title['en'] : 'Lesson',
            'titleNl' => $title['nl'] ?? '',
            'role' => $role,
            'vimeoId' => (string) ($row['vimeoId'] ?? ''),
            'seconds' => $seconds,
            'length' => $this->fmt($seconds),
            'difficulty' => $diff['en'],
            'difficultyNl' => $diff['nl'] ?? '',
            'description' => $desc['en'] !== '' ? $desc['en'] : null,
            'descriptionNl' => $desc['nl'],
            'instructor' => is_string($row['instructor'] ?? null) ? $row['instructor'] : null,
            'cmsThumb' => is_string($row['thumb'] ?? null) ? $row['thumb'] : null,
            'skillPackId' => isset($pack['id']) ? (int) $pack['id'] : null,
            'skillPackTitle' => $packTitle,
            'skillPackTitleNl' => $packLoc['nl'] ?? ($packTitle === 'Welcome' ? 'Welkom' : null),
            'resources' => [],
        ];
    }

    /** @param array<string,mixed> $lesson @return array<string,mixed> */
    private function thin(array $lesson): array
    {
        return [
            'id' => $lesson['id'],
            'n' => $lesson['n'] ?? null,
            'title' => $lesson['title'],
            'titleNl' => $lesson['titleNl'] ?? null,
            'vimeoId' => $lesson['vimeoId'],
            'seconds' => $lesson['seconds'],
            'length' => $lesson['length'],
            'role' => $lesson['role'],
            'skillPackId' => $lesson['skillPackId'] ?? null,
            'skillPackTitle' => $lesson['skillPackTitle'] ?? null,
            'skillPackTitleNl' => $lesson['skillPackTitleNl'] ?? null,
            'instructor' => $lesson['instructor'] ?? null,
            'pathSlug' => $lesson['pathSlug'] ?? null,
            'pathTitle' => $lesson['pathTitle'] ?? null,
            'pathTitleNl' => $lesson['pathTitleNl'] ?? null,
            'difficulty' => $lesson['difficulty'] ?? null,
            'difficultyNl' => $lesson['difficultyNl'] ?? null,
        ];
    }

    /**
     * @return array{en:string,nl:?string}
     */
    private function loc(mixed $value, mixed $legacyNl = null): array
    {
        if (is_array($value) && (array_key_exists('en', $value) || array_key_exists('nl', $value))) {
            $en = $value['en'] ?? '';
            $en = $en === null ? '' : (string) $en;
            $nl = $value['nl'] ?? null;
            $nl = ($nl === null || $nl === '') ? null : (string) $nl;
            return ['en' => $en, 'nl' => $nl];
        }
        $en = is_string($value) ? $value : '';
        $nl = is_string($legacyNl) && $legacyNl !== '' ? $legacyNl : null;
        return ['en' => $en, 'nl' => $nl];
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

    private function resolveCatalogPath(): string
    {
        $file = $this->config->catalogPath;
        if (is_file($file)) {
            return $file;
        }
        $fallback = dirname(__DIR__, 2) . '/catalog.json';
        return is_file($fallback) ? $fallback : $file;
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
