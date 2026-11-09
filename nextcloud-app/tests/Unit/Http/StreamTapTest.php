<?php

namespace OCA\AIquila\Tests\Unit\Http;

use OCA\AIquila\Http\StreamTap;
use PHPUnit\Framework\TestCase;

class StreamTapTest extends TestCase {

    public function testEachWriteReachesTheCallbackAndStillLandsInTheStream(): void {
        $seen = [];
        $stream = fopen('php://temp', 'w+b');
        StreamTap::attach($stream, function (string $chunk) use (&$seen): void {
            $seen[] = $chunk;
        });

        fwrite($stream, 'first ');
        $this->assertSame(['first '], $seen, 'seen at the write, not when the stream closes');
        fwrite($stream, 'second');

        rewind($stream);
        $this->assertSame('first second', stream_get_contents($stream));
        $this->assertSame(['first ', 'second'], $seen);
        fclose($stream);
    }

    public function testTwoTapsOnTwoStreamsKeepTheirOwnCallbacks(): void {
        $a = fopen('php://temp', 'w+b');
        $b = fopen('php://temp', 'w+b');
        $seenA = '';
        $seenB = '';
        StreamTap::attach($a, function (string $chunk) use (&$seenA): void {
            $seenA .= $chunk;
        });
        StreamTap::attach($b, function (string $chunk) use (&$seenB): void {
            $seenB .= $chunk;
        });

        fwrite($a, 'A');
        fwrite($b, 'B');

        $this->assertSame(['A', 'B'], [$seenA, $seenB]);
        fclose($a);
        fclose($b);
    }
}
