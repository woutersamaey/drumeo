<?php

declare(strict_types=1);

namespace Drumeo\App;

final class DirStats
{
    /** @return array{bytes:int,files:int,exists:bool} */
    public static function of(string $dir): array
    {
        $dir = rtrim($dir, '/');
        if ($dir === '' || !is_dir($dir)) {
            return ['bytes' => 0, 'files' => 0, 'exists' => false];
        }
        $bytes = 0;
        $files = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
                $files++;
            }
        }
        return ['bytes' => $bytes, 'files' => $files, 'exists' => true];
    }
}
