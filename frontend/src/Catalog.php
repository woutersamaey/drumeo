<?php

declare(strict_types=1);

namespace Drumeo\App;

final class Catalog
{
    /** Bump only for a change in the cached shape that the key below would not catch. */
    private const CATALOG_KEY = 'catalog:v9';
    private const CATALOG_TTL = 600;
    private const MEDIA_KEY = 'media-index:v4';
    private const MEDIA_TTL = 900;

    /** @var array<string,mixed>|null */
    private ?array $memo = null;
    /** @var array<string,string>|null */
    private ?array $mediaMemo = null;

    public function __construct(
        private readonly Config $config,
        private readonly RedisCache $cache,
    ) {
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $path = $this->resolveCatalogPath();
        $key = self::catalogCacheKey($path);
        $cached = $this->cache->get($key);
        if (is_array($cached) && isset($cached['lessons'], $cached['loose'])) {
            return $this->memo = $cached;
        }
        $raw = self::readJson($path);
        if ($raw === null) {
            // Do not cache a missing or broken catalog; the next request retries.
            error_log('[drumeo] catalog unreadable: ' . $path);
            return $this->memo = $this->build([]);
        }
        $built = $this->build($raw);
        $this->cache->set($key, $built, self::CATALOG_TTL);
        return $this->memo = $built;
    }

    /**
     * Redis key for the built catalog. It changes whenever catalog.json or this
     * class changes, so an edit or deploy never needs a manual DEL.
     */
    public static function catalogCacheKey(string $path): string
    {
        clearstatcache(true, $path);
        $file = is_file($path) ? filemtime($path) . '-' . filesize($path) : 'missing';
        $code = (string) (filemtime(__FILE__) ?: 0);
        return self::CATALOG_KEY . ':' . substr(sha1($file . '|' . $code), 0, 12);
    }

    /** @return array<string,mixed>|null */
    public function lesson(int $id): ?array
    {
        return $this->all()['lessons'][(string) $id] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function orderedLessons(): array
    {
        return $this->all()['order'];
    }

    /** @return array<string,bool> */
    public function availableVideos(): array
    {
        $out = [];
        foreach (array_keys($this->mediaIndex()) as $id) {
            $out[(string) $id] = true;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function build(array $raw): array
    {
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
                'videoCount' => 0,
                'skillPacks' => [],
                'lessons' => [],
            ];
            $pathLessons = [];
            $thinById = [];
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
                $thin = $this->thin($lesson);
                $pathLessons[] = $thin;
                $thinById[$id] = $thin;
            }
            $packs = [];
            foreach (is_array($pathInfo['packs'] ?? null) ? $pathInfo['packs'] : [] as $packInfo) {
                if (!is_array($packInfo)) {
                    continue;
                }
                $packLoc = $this->loc($packInfo['title'] ?? '');
                $packLessons = [];
                foreach (is_array($packInfo['lessons'] ?? null) ? $packInfo['lessons'] : [] as $lid) {
                    $thin = $thinById[(string) (int) $lid] ?? null;
                    if ($thin !== null) {
                        $packLessons[] = $thin;
                    }
                }
                $packTitle = $packLoc['en'] !== '' ? $packLoc['en'] : null;
                $packs[] = [
                    'id' => isset($packInfo['id']) ? (int) $packInfo['id'] : null,
                    'title' => $packTitle ?? 'More',
                    'titleNl' => $packLoc['nl'] ?? $packTitle ?? 'Meer',
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

        $loose = [];
        foreach (is_array($raw['loose'] ?? null) ? $raw['loose'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lesson = $this->hydrate($row);
            if ($lesson === null) {
                continue;
            }
            $id = (string) $lesson['id'];
            if (isset($orderIds[$id])) {
                continue;
            }
            $lesson['role'] = 'loose';
            $lesson['pathSlug'] = null;
            $lesson['pathTitle'] = 'Loose lessons';
            $lesson['pathTitleNl'] = 'Losse lessen';
            $lesson['n'] = null;
            $lesson['prevId'] = null;
            $lesson['nextId'] = null;
            $lessons[$id] = $lesson;
            $loose[] = $this->thin($lesson);
        }

        return [
            'intro' => $intro,
            'paths' => $paths,
            'lessons' => $lessons,
            'order' => $order,
            'loose' => $loose,
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
            'skillPackId' => isset($pack['id']) ? (int) $pack['id'] : null,
            'skillPackTitle' => $packTitle,
            'skillPackTitleNl' => $packLoc['nl'] ?? ($packTitle === 'Welcome' ? 'Welkom' : null),
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
    private function loc(mixed $value): array
    {
        if (is_array($value) && (array_key_exists('en', $value) || array_key_exists('nl', $value))) {
            $en = $value['en'] ?? '';
            $en = $en === null ? '' : (string) $en;
            $nl = $value['nl'] ?? null;
            $nl = ($nl === null || $nl === '') ? null : (string) $nl;
            return ['en' => $en, 'nl' => $nl];
        }
        return ['en' => is_string($value) ? $value : '', 'nl' => null];
    }

    private function fmt(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m . ':' . str_pad((string) $s, 2, '0', STR_PAD_LEFT);
    }

    /** @return array<string,mixed>|null */
    private static function readJson(string $path): ?array
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

    /**
     * Video id → NAS path. The key includes the directory mtime, so adding or
     * removing an MKV rebuilds the index without a manual DEL. A failed or
     * empty scan (NAS not mounted) is never cached.
     *
     * @return array<string,string>
     */
    private function mediaIndex(): array
    {
        if ($this->mediaMemo !== null) {
            return $this->mediaMemo;
        }
        $dir = rtrim($this->config->mediaDir, '/');
        clearstatcache(true, $dir);
        $mtime = ($dir !== '' && is_dir($dir)) ? filemtime($dir) : false;
        if ($mtime === false) {
            error_log('[drumeo] media dir unavailable: ' . $dir);
            return $this->mediaMemo = [];
        }
        $key = self::MEDIA_KEY . ':' . $mtime;
        $cached = $this->cache->get($key);
        if (is_array($cached) && $cached !== []) {
            return $this->mediaMemo = $cached;
        }
        $map = self::scanMedia($dir);
        if ($map === null) {
            error_log('[drumeo] media dir unreadable: ' . $dir);
            return $this->mediaMemo = [];
        }
        if ($map !== []) {
            $this->cache->set($key, $map, self::MEDIA_TTL);
        }
        return $this->mediaMemo = $map;
    }

    /**
     * @return array<string,string>|null null when the directory cannot be read
     */
    public static function scanMedia(string $dir): ?array
    {
        $handle = is_dir($dir) ? @opendir($dir) : false;
        if ($handle === false) {
            return null;
        }
        $map = [];
        while (($entry = readdir($handle)) !== false) {
            if (str_starts_with($entry, '.')) {
                continue;
            }
            if (preg_match('/\[([a-zA-Z0-9_-]+)\]\.mkv$/i', $entry, $m) !== 1
                && preg_match('/^([a-zA-Z0-9_-]+)\.mkv$/i', $entry, $m) !== 1
            ) {
                continue;
            }
            $map[$m[1]] = $dir . '/' . $entry;
        }
        closedir($handle);
        return $map;
    }
}
