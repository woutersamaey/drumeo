<?php

declare(strict_types=1);

namespace Drumeo\Video\Tests;

use Drumeo\Video\Http\Request;
use Drumeo\Video\VideoId;
use PHPUnit\Framework\TestCase;

final class PathSafetyTest extends TestCase
{
    public function testAcceptsSimpleIds(): void
    {
        $this->assertTrue(VideoId::isValid('film1'));
        $this->assertTrue(VideoId::isValid('1146769485'));
        $this->assertTrue(VideoId::isValid(1146769485));
        $this->assertSame('1146769485', VideoId::assert(1146769485));
        $this->assertTrue(VideoId::isValid('a-b_c0'));
    }

    public function testRejectsTraversalAndJunk(): void
    {
        $this->assertFalse(VideoId::isValid('../etc/passwd'));
        $this->assertFalse(VideoId::isValid('foo/bar'));
        $this->assertFalse(VideoId::isValid('film 1'));
        $this->assertFalse(VideoId::isValid('film;rm'));
        $this->assertFalse(VideoId::isValid(''));
        $this->assertFalse(VideoId::isValid('..'));
        $this->assertFalse(VideoId::isValid('a.b'));
    }

    public function testExtractsIdFromNasFilename(): void
    {
        $this->assertSame('1146769485', VideoId::extractFromFilename('Drumeo_WelcomeVideo_V4 [1146769485].mkv'));
        $this->assertSame('1101964379', VideoId::extractFromFilename('l1-sp1-4-note-groove-v1 [1101964379].jpg'));
        $this->assertSame('film1', VideoId::extractFromFilename('film1.mkv'));
        $this->assertNull(VideoId::extractFromFilename('nope.txt'));
    }

    public function testApiRejectsTraversalId(): void
    {
        $h = new TestHarness();
        try {
            $req = new Request('GET', '/api/videos/..%2Fetc', [], [], [], '');
            $res = $h->app->handle($req);
            $this->assertContains($res->status, [400, 404]);
            $req = new Request('GET', '/api/videos/foo/bar', [], [], [], '');
            $res = $h->app->handle($req);
            $this->assertContains($res->status, [400, 404]);
        } finally {
            $h->destroy();
        }
    }
}
