<?php

declare(strict_types=1);

namespace Drumeo\Video\Store;

use Drumeo\Video\Config;
use Drumeo\Video\Hls\MasterPlaylist;
use Drumeo\Video\Hls\MediaPlaylist;
use Drumeo\Video\Recipe\Recipe;
use Drumeo\Video\VideoId;

final class HlsCache
{
    public function __construct(private readonly Config $config)
    {
    }

    public function variantDir(string $id, string $recipe, int $audioIndex): string
    {
        VideoId::assert($id);
        $this->assertRecipe($recipe);
        if ($audioIndex < 0) {
            throw new \InvalidArgumentException('Invalid audio index');
        }
        return rtrim($this->config->cacheDir, '/') . '/' . $id . '/' . $recipe . '/' . $audioIndex;
    }

    public function playlistPath(string $id, string $recipe, int $audioIndex): string
    {
        return $this->variantDir($id, $recipe, $audioIndex) . '/index.m3u8';
    }

    public function masterPath(string $id, string $recipe, int $audioIndex): string
    {
        return $this->variantDir($id, $recipe, $audioIndex) . '/master.m3u8';
    }

    public function relativeMaster(string $id, string $recipe, int $audioIndex): string
    {
        return $id . '/' . $recipe . '/' . $audioIndex . '/master.m3u8';
    }

    public function publicUrl(string $id, string $recipe, int $audioIndex): string
    {
        $url = $this->config->publicHlsBase . '/' . $this->relativeMaster($id, $recipe, $audioIndex);
        $index = $this->playlistPath($id, $recipe, $audioIndex);
        if (is_file($index)) {
            $url .= '?v=' . (int) filemtime($index);
        }
        return $url;
    }

    public function inspect(string $id, string $recipe, int $audioIndex): array
    {
        $dir = $this->variantDir($id, $recipe, $audioIndex);
        $playlist = $dir . '/index.m3u8';
        $master = $dir . '/master.m3u8';
        $exists = is_file($playlist);
        $info = [
            'dir' => $dir,
            'exists' => $exists && is_file($master),
            'hasPlaylist' => $exists,
            'hasMaster' => is_file($master),
            'hasEndlist' => false,
            'segmentCount' => 0,
            'durationReadySec' => 0.0,
        ];
        if (!$exists) {
            $info['segmentCount'] = $this->countSegments($dir);
            return $info;
        }
        $parsed = MediaPlaylist::parse(file_get_contents($playlist) ?: '');
        $info['hasEndlist'] = $parsed['endlist'];
        $info['durationReadySec'] = $parsed['duration'];
        $segCount = $parsed['segmentCount'];
        if ($segCount === 0) {
            $segCount = $this->countSegments($dir);
        }
        $info['segmentCount'] = $segCount;
        return $info;
    }

    /**
     * The files form a finished playlist. Whether the encode succeeded is the
     * variant state in the metadata JSON, which only the worker sets to ready.
     */
    public function playable(string $id, string $recipe, int $audioIndex): bool
    {
        $info = $this->inspect($id, $recipe, $audioIndex);
        return $info['hasPlaylist'] && $info['hasEndlist'] && $info['segmentCount'] >= 1;
    }

    /**
     * Convert a finished EVENT playlist into seekable VOD and cache-bust the master URI.
     * Without $force, no-op while FFmpeg is still writing (no ENDLIST yet).
     */
    public function finalizeForVod(string $id, string $recipe, int $audioIndex, bool $force = false): void
    {
        $path = $this->playlistPath($id, $recipe, $audioIndex);
        if (!is_file($path)) {
            return;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return;
        }
        $parsed = MediaPlaylist::parse($raw);
        if (!$force && !$parsed['endlist']) {
            return;
        }
        $vod = MediaPlaylist::toVod($raw);
        $token = $this->segmentVersion($id, $recipe, $audioIndex);
        if ($token !== null) {
            $vod = MediaPlaylist::withSegmentVersion($vod, $token);
        }
        if ($vod !== $raw) {
            AtomicJson::writeFile($path, $vod);
        }
        $this->bustMasterUri($id, $recipe, $audioIndex);
    }

    /**
     * Stable for one encode, different after the variant directory is rebuilt.
     * A source mtime/size change deletes that directory before the next encode.
     */
    private function segmentVersion(string $id, string $recipe, int $audioIndex): ?string
    {
        $seg = $this->variantDir($id, $recipe, $audioIndex) . '/seg_000.ts';
        if (!is_file($seg)) {
            return null;
        }
        $mtime = filemtime($seg);
        if ($mtime === false) {
            return null;
        }
        return (string) $mtime;
    }

    private function bustMasterUri(string $id, string $recipe, int $audioIndex): void
    {
        $master = $this->masterPath($id, $recipe, $audioIndex);
        $index = $this->playlistPath($id, $recipe, $audioIndex);
        if (!is_file($master) || !is_file($index)) {
            return;
        }
        $raw = file_get_contents($master);
        if ($raw === false) {
            return;
        }
        $uri = 'index.m3u8?v=' . (int) filemtime($index);
        $updated = MasterPlaylist::withMediaUri($raw, $uri);
        if ($updated !== $raw) {
            AtomicJson::writeFile($master, $updated);
        }
    }

    public function deleteVariant(string $id, string $recipe, int $audioIndex): void
    {
        $dir = $this->variantDir($id, $recipe, $audioIndex);
        $this->assertDeletable($dir);
        $this->rmTree($dir);
        $recipeDir = dirname($dir);
        $this->removeIfEmpty($recipeDir);
        $this->removeIfEmpty(dirname($recipeDir));
    }

    public function deleteAllFor(string $id): void
    {
        VideoId::assert($id);
        $dir = rtrim($this->config->cacheDir, '/') . '/' . $id;
        $this->assertDeletable($dir);
        $this->rmTree($dir);
    }

    /** @return list<int> audio indexes that have a cache directory for this recipe */
    public function audioIndexesOnDisk(string $id, string $recipe): array
    {
        VideoId::assert($id);
        $this->assertRecipe($recipe);
        $out = [];
        foreach ($this->scandir(rtrim($this->config->cacheDir, '/') . '/' . $id . '/' . $recipe) as $audio) {
            if (ctype_digit($audio)) {
                $out[] = (int) $audio;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Older builds wrote ffmpeg.log next to the segments (publicly served).
     * Logs now live in METADATA_DIR/logs; remove the leftovers.
     */
    public function removeLegacyLogs(): int
    {
        $n = 0;
        foreach ($this->listVariantDirs() as $dir) {
            if (is_file($dir['path'] . '/ffmpeg.log') && @unlink($dir['path'] . '/ffmpeg.log')) {
                $n++;
            }
        }
        return $n;
    }

    public function cacheBytes(): int
    {
        return $this->dirBytes($this->config->cacheDir);
    }

    /** @return list<array{id:string,recipe:string,audioIndex:int,path:string,mtime:int,bytes:int}> */
    public function listVariantDirs(): array
    {
        $root = rtrim($this->config->cacheDir, '/');
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        foreach ($this->scandir($root) as $id) {
            if (!VideoId::isValid($id) || !is_dir($root . '/' . $id)) {
                continue;
            }
            foreach ($this->scandir($root . '/' . $id) as $recipe) {
                if (!$this->isRecipe($recipe)) {
                    continue;
                }
                foreach ($this->scandir($root . '/' . $id . '/' . $recipe) as $audio) {
                    if (!ctype_digit($audio)) {
                        continue;
                    }
                    $path = $root . '/' . $id . '/' . $recipe . '/' . $audio;
                    if (!is_dir($path)) {
                        continue;
                    }
                    $out[] = [
                        'id' => $id,
                        'recipe' => $recipe,
                        'audioIndex' => (int) $audio,
                        'path' => $path,
                        'mtime' => filemtime($path) ?: 0,
                        'bytes' => $this->dirBytes($path),
                    ];
                }
            }
        }
        return $out;
    }

    private function countSegments(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach ($this->scandir($dir) as $name) {
            if (preg_match('/^seg_\d+\.ts$/', $name)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Deletion targets must sit strictly inside CACHE_DIR and never inside or
     * above SOURCE_DIR, whatever the mounts or symlinks look like.
     */
    private function assertDeletable(string $path): void
    {
        $cache = realpath($this->config->cacheDir);
        if ($cache === false) {
            throw new \RuntimeException('Cache directory missing');
        }
        if (!file_exists($path) && !is_link($path)) {
            return; // nothing on disk to delete
        }
        $parent = realpath(dirname($path));
        if ($parent === false) {
            return;
        }
        // Where the entry itself lives; a final symlink is not followed (rmTree unlinks links).
        $target = $parent . '/' . basename($path);
        if (is_dir($target) && !is_link($target)) {
            $target = realpath($target) ?: $target;
        }
        if ($target === $cache || !str_starts_with($target . '/', $cache . '/')) {
            throw new \RuntimeException('Refusing to delete outside CACHE_DIR: ' . $path);
        }
        $source = realpath($this->config->sourceDir);
        if ($source !== false
            && (str_starts_with($target . '/', $source . '/') || str_starts_with($source . '/', $target . '/'))
        ) {
            throw new \RuntimeException('Refusing to delete inside SOURCE_DIR: ' . $path);
        }
    }

    private function rmTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmTree($path . '/' . $item);
        }
        @rmdir($path);
    }

    private function removeIfEmpty(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        $rest = array_diff($items, ['.', '..']);
        if ($rest === []) {
            @rmdir($dir);
        }
    }

    private function dirBytes(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $bytes = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        } catch (\UnexpectedValueException|\RuntimeException) {
            // directory removed while counting (cleanup or a rebuild); partial count is fine
        }
        return $bytes;
    }

    /** @return list<string> */
    private function scandir(string $dir): array
    {
        $items = @scandir($dir);
        if ($items === false) {
            return [];
        }
        return array_values(array_filter($items, fn ($n) => $n !== '.' && $n !== '..'));
    }

    private function assertRecipe(string $recipe): void
    {
        if (!$this->isRecipe($recipe)) {
            throw new \InvalidArgumentException('Invalid recipe');
        }
    }

    private function isRecipe(string $recipe): bool
    {
        return Recipe::isValid($recipe);
    }
}
