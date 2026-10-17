<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Config;
use Drumeo\Video\Hls\MediaPlaylist;
use Drumeo\Video\Jobs\EncoderPicker;
use Drumeo\Video\Jobs\FfmpegCommand;
use Drumeo\Video\Recipe\Matcher;
use PHPUnit\Framework\TestCase;

final class FfmpegCommandTest extends TestCase
{
    private const META_4K = ['video' => ['codec' => 'h264', 'width' => 3840, 'height' => 2160, 'fps' => 29.97, 'pixFmt' => 'yuv420p']];

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/vbcmd-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->tmp);
    }

    /** Production runs FFMPEG_VCODEC=libx264: this argv must not drift. */
    public function testLibx264ArgvIsUnchanged(): void
    {
        $args = $this->command()->build('/s/x [1].mkv', '/c/1/avc_1080/1', self::META_4K, 'avc_1080', 1, 'libx264');
        $this->assertSame(json_decode('["ffmpeg","-hide_banner","-loglevel","error","-y","-i","\/s\/x [1].mkv","-map","0:v:0","-map","0:a:1","-c:v","libx264","-preset","veryfast","-profile:v","high","-pix_fmt","yuv420p","-vf","scale=1920:1080:force_original_aspect_ratio=decrease,scale=trunc(iw\/2)*2:trunc(ih\/2)*2,format=yuv420p","-sc_threshold","0","-b:v","5M","-maxrate","6M","-bufsize","10M","-g","60","-keyint_min","60","-c:a","aac","-profile:a","aac_low","-ac","2","-b:a","128k","-f","hls","-hls_time","6","-hls_list_size","0","-hls_playlist_type","event","-hls_flags","independent_segments+temp_file","-hls_segment_type","mpegts","-hls_segment_filename","\/c\/1\/avc_1080\/1\/seg_%03d.ts","\/c\/1\/avc_1080\/1\/index.m3u8"]', true), $args);
        // Default (picker) path gives the same argv when libx264 is chosen.
        $this->assertSame($args, $this->command()->build('/s/x [1].mkv', '/c/1/avc_1080/1', self::META_4K, 'avc_1080', 1));
    }

    public function testRemuxAndAudioAacArgvAreUnchanged(): void
    {
        $remux = $this->command()->build('/s/x [1].mkv', '/c/1/remux/0', self::META_4K, 'remux', 0, 'h264_vaapi');
        $this->assertSame(json_decode('["ffmpeg","-hide_banner","-loglevel","error","-y","-i","\/s\/x [1].mkv","-map","0:v:0","-map","0:a:0","-c:v","copy","-c:a","copy","-tag:v","avc1","-f","hls","-hls_time","6","-hls_list_size","0","-hls_playlist_type","event","-hls_flags","independent_segments+temp_file","-hls_segment_type","mpegts","-hls_segment_filename","\/c\/1\/remux\/0\/seg_%03d.ts","\/c\/1\/remux\/0\/index.m3u8"]', true), $remux);
        $aac = $this->command()->build('/s/x [1].mkv', '/c/1/audio_aac/0', ['video' => ['codec' => 'hevc', 'fps' => 25]], 'audio_aac', 0);
        $this->assertSame(json_decode('["ffmpeg","-hide_banner","-loglevel","error","-y","-i","\/s\/x [1].mkv","-map","0:v:0","-map","0:a:0","-c:v","copy","-tag:v","hvc1","-c:a","aac","-profile:a","aac_low","-ac","2","-b:a","128k","-f","hls","-hls_time","6","-hls_list_size","0","-hls_playlist_type","event","-hls_flags","independent_segments+temp_file","-hls_segment_type","mpegts","-hls_segment_filename","\/c\/1\/audio_aac\/0\/seg_%03d.ts","\/c\/1\/audio_aac\/0\/index.m3u8"]', true), $aac);
    }

    public function testVaapiPutsDeviceAndHwaccelBeforeInput(): void
    {
        $args = $this->command()->build('/s/in.mkv', '/c/out', self::META_4K, 'avc_1080', 0, 'h264_vaapi');
        $input = array_search('-i', $args, true);
        foreach (['-vaapi_device', '-hwaccel', '-hwaccel_output_format'] as $flag) {
            $pos = array_search($flag, $args, true);
            $this->assertIsInt($pos, $flag);
            $this->assertLessThan($input, $pos, "$flag must precede -i");
        }
        $this->assertSame('vaapi', $args[array_search('-hwaccel', $args, true) + 1]);
        $this->assertStringStartsWith('/dev/dri/', $args[array_search('-vaapi_device', $args, true) + 1]);
        $codec = array_search('h264_vaapi', $args, true);
        $this->assertGreaterThan($input, $codec);
        $vf = $args[array_search('-vf', $args, true) + 1];
        $this->assertSame('format=nv12|vaapi,hwupload,scale_vaapi=w=1920:h=1080:format=nv12', $vf);
        $this->assertNotContains('libx264', $args);
    }

    public function testVideotoolboxHasItsOwnBranch(): void
    {
        $args = $this->command()->build('/s/in.mkv', '/c/out', self::META_4K, 'avc_1080', 0, 'h264_videotoolbox');
        $this->assertContains('h264_videotoolbox', $args);
        $this->assertNotContains('libx264', $args);
        $this->assertNotContains('-sc_threshold', $args);
        $this->assertSame('-i', $args[5], 'no input options for videotoolbox');
    }

    public function testEncoderSmokeUsesTheJobArgv(): void
    {
        $seen = [];
        $picker = new EncoderPicker($this->config($this->tmp), function (array $args) use (&$seen) {
            $seen[] = $args;
            return ['ok' => true, 'output' => ''];
        });
        $this->assertTrue($picker->smoke('h264_vaapi'));
        $smoke = end($seen);
        $this->assertContains('-hwaccel', $smoke);
        $this->assertContains('hls', $smoke);
        $this->assertLessThan(array_search('-i', $smoke, true), array_search('-vaapi_device', $smoke, true));
        $this->assertStringEndsWith('vb-smoke-sample.mkv', $smoke[array_search('-i', $smoke, true) + 1]);
    }

    public function testToVodInsertsPlaylistTypeOnItsOwnLine(): void
    {
        $raw = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n#EXTINF:6.0,\nseg_000.ts\n#EXT-X-ENDLIST\n";
        $vod = MediaPlaylist::toVod($raw);
        $this->assertSame(
            "#EXTM3U\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXT-X-START:TIME-OFFSET=0\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n#EXTINF:6.0,\nseg_000.ts\n#EXT-X-ENDLIST\n",
            $vod,
        );
        $this->assertSame($vod, MediaPlaylist::toVod($vod));
    }

    public function testCoversDurationTolerance(): void
    {
        $this->assertTrue(MediaPlaylist::coversDuration(303.17, 303.27));
        $this->assertTrue(MediaPlaylist::coversDuration(22.0, 23.8));
        $this->assertFalse(MediaPlaylist::coversDuration(150.0, 300.0));
        $this->assertFalse(MediaPlaylist::coversDuration(290.0, 300.0));
        $this->assertTrue(MediaPlaylist::coversDuration(10.0, 0.0), 'unknown duration cannot be checked');
    }

    private function command(): FfmpegCommand
    {
        $config = $this->config($this->tmp);
        $picker = new EncoderPicker($config, fn (array $a) => ['ok' => true, 'output' => " V..... libx264\n"]);
        return new FfmpegCommand($config, new Matcher(), $picker);
    }

    private function config(string $tmp): Config
    {
        return new Config(sourceDir: '/s', cacheDir: '/c', metadataDir: '/m', tmpDir: $tmp, ffmpegVcodec: 'libx264');
    }
}
