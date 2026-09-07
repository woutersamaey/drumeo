<?php

declare(strict_types=1);

namespace Drumeo\Video\Hls;

final class MediaPlaylist
{
    /** @return array{endlist:bool,event:bool,vod:bool,duration:float,segmentCount:int} */
    public static function parse(string $contents): array
    {
        $endlist = str_contains($contents, '#EXT-X-ENDLIST');
        $vod = (bool) preg_match('/#EXT-X-PLAYLIST-TYPE:\s*VOD/i', $contents);
        $event = (bool) preg_match('/#EXT-X-PLAYLIST-TYPE:\s*EVENT/i', $contents);
        $duration = 0.0;
        $count = 0;
        if (preg_match_all('/#EXTINF:([0-9.]+)/', $contents, $m)) {
            $count = count($m[1]);
            foreach ($m[1] as $sec) {
                $duration += (float) $sec;
            }
        }
        return [
            'endlist' => $endlist,
            'event' => $event,
            'vod' => $vod,
            'duration' => $duration,
            'segmentCount' => $count,
        ];
    }

    public static function toVod(string $contents): string
    {
        $contents = preg_replace('/#EXT-X-PLAYLIST-TYPE:\s*EVENT/i', '#EXT-X-PLAYLIST-TYPE:VOD', $contents) ?? $contents;
        if (!preg_match('/#EXT-X-PLAYLIST-TYPE:/i', $contents)) {
            $contents = preg_replace('/(#EXTM3U\s*)/', "$1\n#EXT-X-PLAYLIST-TYPE:VOD", $contents, 1) ?? $contents;
        }
        if (!str_contains($contents, '#EXT-X-ENDLIST')) {
            $contents = rtrim($contents) . "\n#EXT-X-ENDLIST\n";
        }
        return $contents;
    }
}
