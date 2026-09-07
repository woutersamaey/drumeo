<?php

declare(strict_types=1);

namespace Drumeo\Video\Store;

use Drumeo\Video\Config;
use Drumeo\Video\Hls\MediaPlaylist;
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
        return $this->config->publicHlsBase . '/' . $this->relativeMaster($id, $recipe, $audioIndex);
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

    public function playable(string $id, string $recipe, int $audioIndex): bool
    {
        $info = $this->inspect($id, $recipe, $audioIndex);
        return $info['hasPlaylist'] && $info['segmentCount'] >= $this->config->prepareMinSegments;
    }

    public function deleteVariant(string $id, string $recipe, int $audioIndex): void
    {
        $dir = $this->variantDir($id, $recipe, $audioIndex);
        $this->rmTree($dir);
        $recipeDir = dirname($dir);
        $this->removeIfEmpty($recipeDir);
        $this->removeIfEmpty(dirname($recipeDir));
    }

    public function deleteAllFor(string $id): void
    {
        VideoId::assert($id);
        $dir = rtrim($this->config->cacheDir, '/') . '/' . $id;
        $this->rmTree($dir);
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
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
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
        return in_array($recipe, ['remux', 'audio_aac', 'avc_1080'], true);
    }
}
