<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Http\SSEResponse;
use PHPUnit\Framework\TestCase;

/**
 * The SSE contract: what the response promises about itself, and the exact
 * bytes it writes per event.
 *
 * The Content-Type matters more than it looks. A stream that goes out labelled
 * text/html is compressed by a stock Apache, and a compressed stream is a
 * buffered one — the whole reply arrives at the end, which is the single thing
 * Server-Sent Events exist to avoid.
 */
class SSEResponseTest extends TestCase {
    public function testDeclaresTheHeadersThatKeepTheStreamUnbuffered(): void {
        // getHeaders() reaches into the server container for the CSP headers,
        // which does not exist in a unit test; the declared set is what matters.
        $property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        /** @var array<string, string> $headers */
        $headers = $property->getValue(new SSEResponse([]));

        $this->assertSame('text/event-stream; charset=utf-8', $headers['Content-Type']);
        $this->assertSame('no-cache, no-transform', $headers['Cache-Control']);
        $this->assertSame('keep-alive', $headers['Connection']);
        $this->assertSame('no', $headers['X-Accel-Buffering']);
    }

    public function testEventIsOneDataBlockTerminatedByABlankLine(): void {
        $this->assertSame(
            "data: {\"type\":\"text_delta\",\"text\":\"Hello\"}\n\n",
            SSEResponse::frame(['type' => 'text_delta', 'text' => 'Hello'])
        );
    }

    /** Slashes and non-ASCII stay readable rather than being escaped. */
    public function testPayloadsAreNotOverEscaped(): void {
        $this->assertSame("data: {\"text\":\"a/b — ü\"}\n\n", SSEResponse::frame(['text' => 'a/b — ü']));
    }

    /**
     * An unencodable event is dropped rather than half-written: a truncated
     * frame would desynchronise the client's parser for the rest of the stream.
     */
    public function testUnencodableEventIsDropped(): void {
        $this->assertNull(SSEResponse::frame(['text' => "\xB1\x31"]));
    }
}
