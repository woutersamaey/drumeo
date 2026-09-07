<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Jobs\FfmpegRunner;

final class FakeFfmpegRunner implements FfmpegRunner
{
    public int $starts = 0;
    /** @var list<list<string>> */
    public array $commands = [];
    /** @var array<int,array{running:bool,exitCode:?int,args:list<string>}> */
    public array $procs = [];
    private int $nextPid = 1000;
    public bool $autoFinish = false;
    public int $autoSegments = 4;

    public function start(array $args, string $logFile): int
    {
        $this->starts++;
        $this->commands[] = $args;
        $pid = $this->nextPid++;
        $this->procs[$pid] = ['running' => !$this->autoFinish, 'exitCode' => $this->autoFinish ? 0 : null, 'args' => $args];
        $outDir = $this->outDirFromArgs($args);
        if ($outDir !== null) {
            $this->writeFakeHls($outDir, $this->autoSegments, $this->autoFinish);
        }
        @file_put_contents($logFile, "fake ffmpeg pid {$pid}\n", FILE_APPEND);
        return $pid;
    }

    public function running(int $pid): bool
    {
        return $this->poll($pid)['running'];
    }

    public function poll(int $pid): array
    {
        $p = $this->procs[$pid] ?? ['running' => false, 'exitCode' => 1];
        return ['running' => $p['running'], 'exitCode' => $p['exitCode']];
    }

    public function stop(int $pid): void
    {
        if (isset($this->procs[$pid])) {
            $this->procs[$pid]['running'] = false;
            $this->procs[$pid]['exitCode'] = 1;
        }
    }

    public function finish(int $pid, int $exit = 0): void
    {
        if (!isset($this->procs[$pid])) {
            return;
        }
        $this->procs[$pid]['running'] = false;
        $this->procs[$pid]['exitCode'] = $exit;
        $outDir = $this->outDirFromArgs($this->procs[$pid]['args']);
        if ($outDir !== null && $exit === 0) {
            $this->writeFakeHls($outDir, max($this->autoSegments, 4), true);
        }
    }

    public function finishAll(int $exit = 0): void
    {
        foreach (array_keys($this->procs) as $pid) {
            if ($this->procs[$pid]['running']) {
                $this->finish($pid, $exit);
            }
        }
    }

    /** @param list<string> $args */
    private function outDirFromArgs(array $args): ?string
    {
        $last = $args[array_key_last($args)] ?? '';
        if (str_ends_with($last, 'index.m3u8')) {
            return dirname($last);
        }
        return null;
    }

    private function writeFakeHls(string $dir, int $segments, bool $endlist): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $body = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n#EXT-X-PLAYLIST-TYPE:" . ($endlist ? 'VOD' : 'EVENT') . "\n";
        for ($i = 0; $i < $segments; $i++) {
            $name = 'seg_' . sprintf('%03d', $i) . '.ts';
            $body .= "#EXTINF:6.0,\n{$name}\n";
            file_put_contents($dir . '/' . $name, 'ts');
        }
        if ($endlist) {
            $body .= "#EXT-X-ENDLIST\n";
        }
        file_put_contents($dir . '/index.m3u8', $body);
    }
}
