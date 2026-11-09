<?php

namespace OCA\AIquila\Tests\Unit\Http;

use OCA\AIquila\Http\SseDecoder;
use PHPUnit\Framework\TestCase;

/**
 * Event stream interpretation per the WHATWG HTML standard, §9.2.6.
 */
class SseDecoderTest extends TestCase {

    public function testAnEventIsDispatchedOnlyByTheBlankLineAfterIt(): void {
        $decoder = new SseDecoder();

        $this->assertSame([], $decoder->feed("event: ping\ndata: {\"a\":1}\n"));
        $this->assertSame([['event' => 'ping', 'data' => '{"a":1}']], $decoder->feed("\n"));
    }

    public function testCommentsIdsAndRetriesAreSkippedAndDataLinesJoin(): void {
        $events = (new SseDecoder())->feed(": comment\nid: 4\nretry: 10\ndata: one\ndata:two\n\n");

        $this->assertSame([['event' => '', 'data' => "one\ntwo"]], $events);
    }

    public function testAnEventWithoutDataIsNotDispatchedAndItsNameDoesNotLeak(): void {
        $events = (new SseDecoder())->feed("event: lonely\n\ndata: x\n\n");

        $this->assertSame([['event' => '', 'data' => 'x']], $events);
    }

    /** CRLF, LF and bare CR all end a line; a trailing CR waits for its LF. */
    public function testEveryLineEndingWorksEvenSplitAcrossChunks(): void {
        $decoder = new SseDecoder();
        $events = [];
        foreach (["data: a\r", "\n\r", "\ndata: b\r\rdata: c\n\n"] as $chunk) {
            array_push($events, ...$decoder->feed($chunk));
        }

        $this->assertSame([
            ['event' => '', 'data' => 'a'],
            ['event' => '', 'data' => 'b'],
            ['event' => '', 'data' => 'c'],
        ], $events);
    }

    public function testOnlyOneSpaceAfterTheColonIsDropped(): void {
        $this->assertSame([['event' => '', 'data' => ' padded']], (new SseDecoder())->feed("data:  padded\n\n"));
    }
}
