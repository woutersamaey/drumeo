<?php

declare(strict_types=1);

namespace Drumeo\Video\Store;

use Drumeo\Video\Config;
use Drumeo\Video\VideoId;

/**
 * Resolves SOURCE_DIR/{id}.mkv and NAS-style "title [id].mkv" names.
 * Public API identity remains the video id.
 */
final class SourceStore
{
    /** @var array<string,string>|null */
    private ?array $index = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pathFor(string $id): ?string
    {
        VideoId::assert($id);
        $direct = rtrim($this->config->sourceDir, '/') . '/' . $id . '.mkv';
        if (is_file($direct)) {
            return $direct;
        }
        $index = $this->index();
        return $index[$id] ?? null;
    }

    public function exists(string $id): bool
    {
        $path = $this->pathFor($id);
        return $path !== null && is_file($path);
    }

    public function stat(string $id): ?array
    {
        $path = $this->pathFor($id);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $size = filesize($path);
        $mtime = filemtime($path);
        if ($size === false || $mtime === false) {
            return null;
        }
        return [
            'path' => $path,
            'size' => $size,
            'mtimeMs' => (int) round($mtime * 1000),
        ];
    }

    /** @return list<string> */
    public function listIds(): array
    {
        $ids = array_map(static fn ($id) => VideoId::normalize($id), array_keys($this->index()));
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function refresh(): void
    {
        $this->index = null;
        $this->index();
    }

    /** @return array<string,string> id => absolute path */
    public function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $dir = $this->config->sourceDir;
        $cache = $this->config->sourceIndexPath();
        $dirMtime = is_dir($dir) ? (filemtime($dir) ?: 0) : 0;
        if (is_file($cache)) {
            $cached = AtomicJson::read($cache);
            if (is_array($cached)
                && (int) ($cached['dirMtime'] ?? 0) === $dirMtime
                && is_array($cached['files'] ?? null)
            ) {
                $files = [];
                foreach ($cached['files'] as $id => $path) {
                    if (is_string($path) && VideoId::isValid($id) && is_file($path)) {
                        $files[VideoId::normalize($id)] = $path;
                    }
                }
                $this->index = $files;
                return $this->index;
            }
        }

        $files = [];
        if (is_dir($dir)) {
            $handle = opendir($dir);
            if ($handle !== false) {
                while (($entry = readdir($handle)) !== false) {
                    if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                        continue;
                    }
                    if (!str_ends_with(strtolower($entry), '.mkv')) {
                        continue;
                    }
                    $id = VideoId::extractFromFilename($entry);
                    if ($id === null || !VideoId::isValid($id)) {
                        continue;
                    }
                    $files[VideoId::normalize($id)] = $dir . '/' . $entry;
                }
                closedir($handle);
            }
        }

        $this->index = $files;
        try {
            AtomicJson::write($cache, [
                'dirMtime' => $dirMtime,
                'files' => $files,
                'scannedAt' => gmdate('c'),
            ]);
        } catch (\Throwable) {
            // index cache is optional
        }
        return $this->index;
    }
}
