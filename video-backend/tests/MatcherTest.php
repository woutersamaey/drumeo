<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Recipe\Capabilities;
use Drumeo\Video\Recipe\Matcher;
use Drumeo\Video\Recipe\Recipe;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase
{
    private Matcher $m;

    protected function setUp(): void
    {
        $this->m = new Matcher();
    }

    public function testHevcAacWithHvc1ClientChoosesRemux(): void
    {
        $offer = $this->m->match($this->hevcAac(), $this->modernIos(), 0);
        $this->assertNotNull($offer);
        $this->assertSame(Recipe::REMUX, $offer['recipe']);
        $this->assertSame('hvc1', $offer['video']['codec']);
        $this->assertSame('aac', $offer['audio']['codec']);
    }

    public function testDtsAudioChoosesAudioAac(): void
    {
        $meta = $this->hevcAac();
        $meta['audioTracks'][0]['codec'] = 'dts';
        $offer = $this->m->match($meta, $this->modernIos(), 0);
        $this->assertNotNull($offer);
        $this->assertSame(Recipe::AUDIO_AAC, $offer['recipe']);
        $this->assertSame('hvc1', $offer['video']['codec']);
        $this->assertSame('aac', $offer['audio']['codec']);
        $this->assertSame(2, $offer['audio']['channels']);
    }

    public function testAvc1MaxHeight1080ChoosesAvc1080For4k(): void
    {
        $offer = $this->m->match($this->hevcAac(), $this->oldIpad(), 0);
        $this->assertNotNull($offer);
        $this->assertSame(Recipe::AVC_1080, $offer['recipe']);
        $this->assertSame('avc1', $offer['video']['codec']);
        $this->assertLessThanOrEqual(1920, $offer['video']['width']);
        $this->assertLessThanOrEqual(1080, $offer['video']['height']);
    }

    public function testTenBitForcesAvc1080(): void
    {
        $meta = $this->hevcAac();
        $meta['video']['pixFmt'] = 'yuv420p10le';
        $meta['video']['bits'] = 10;
        $offer = $this->m->match($meta, $this->modernIos(), 0);
        $this->assertNotNull($offer);
        $this->assertSame(Recipe::AVC_1080, $offer['recipe']);
    }

    public function testHdrForcesAvc1080(): void
    {
        $meta = $this->hevcAac();
        $meta['video']['hdr'] = true;
        $offer = $this->m->match($meta, $this->modernIos(), 0);
        $this->assertSame(Recipe::AVC_1080, $offer['recipe']);
    }

    public function testH264AacRemuxWhenClientHasAvc(): void
    {
        $meta = $this->hevcAac();
        $meta['video']['codec'] = 'h264';
        $meta['video']['width'] = 1920;
        $meta['video']['height'] = 1080;
        $offer = $this->m->match($meta, $this->oldIpad(), 0);
        $this->assertSame(Recipe::REMUX, $offer['recipe']);
        $this->assertSame('avc1', $offer['video']['codec']);
    }

    public function testReadyVariantPreferredOverLighterMissing(): void
    {
        $variants = [[
            'recipe' => Recipe::AVC_1080,
            'audioIndex' => 0,
            'state' => 'ready',
        ]];
        $offer = $this->m->match($this->hevcAac(), $this->modernIos(), 0, $variants);
        $this->assertSame(Recipe::AVC_1080, $offer['recipe']);
    }

    public function testNoHlsCapabilityReturnsNull(): void
    {
        $caps = Capabilities::fromArray([
            'protocols' => ['dash'],
            'videoCodecs' => ['avc1'],
            'audioCodecs' => ['mp4a.40.2'],
            'maxWidth' => 1920,
            'maxHeight' => 1080,
        ]);
        $this->assertNull($this->m->match($this->hevcAac(), $caps, 0));
    }

    public function testUnknownAudioIndexReturnsNull(): void
    {
        $this->assertNull($this->m->match($this->hevcAac(), $this->modernIos(), 9));
    }

    public function testAudioIndexZeroDoesNotDescribeTrackOne(): void
    {
        $meta = $this->hevcAac();
        $meta['audioTracks'][] = ['index' => 1, 'codec' => 'aac', 'channels' => 2];
        $offer0 = $this->m->match($meta, $this->modernIos(), 0);
        $this->assertSame(0, $offer0['audio']['index']);
        $offer1 = $this->m->match($meta, $this->modernIos(), 1);
        $this->assertSame(1, $offer1['audio']['index']);
    }

    /** @return array<string,mixed> */
    private function hevcAac(): array
    {
        return [
            'video' => [
                'index' => 0,
                'codec' => 'hevc',
                'width' => 3840,
                'height' => 2160,
                'pixFmt' => 'yuv420p',
                'bits' => 8,
                'hdr' => false,
                'fps' => 30.0,
            ],
            'audioTracks' => [
                ['index' => 0, 'codec' => 'aac', 'channels' => 2],
            ],
            'variants' => [],
        ];
    }

    private function modernIos(): Capabilities
    {
        return Capabilities::fromArray([
            'protocols' => ['hls'],
            'videoCodecs' => ['avc1', 'hvc1'],
            'audioCodecs' => ['mp4a.40.2'],
            'maxWidth' => 3840,
            'maxHeight' => 2160,
            'hdr' => false,
            'nativeHls' => true,
        ]);
    }

    private function oldIpad(): Capabilities
    {
        return Capabilities::fromArray([
            'protocols' => ['hls'],
            'videoCodecs' => ['avc1'],
            'audioCodecs' => ['mp4a.40.2'],
            'maxWidth' => 1920,
            'maxHeight' => 1080,
            'nativeHls' => true,
        ]);
    }
}
