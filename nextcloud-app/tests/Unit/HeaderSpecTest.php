<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\Provider\HeaderSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HeaderSpecTest extends TestCase {
    public function testParsesNameValuePairs(): void {
        $headers = HeaderSpec::parse("CF-Access-Client-Id: abc\nCF-Access-Client-Secret: def");

        $this->assertSame([
            'CF-Access-Client-Id' => 'abc',
            'CF-Access-Client-Secret' => 'def',
        ], $headers);
    }

    public function testSkipsBlankLinesAndComments(): void {
        $headers = HeaderSpec::parse("# routing\n\n  X-Tenant: acme  \r\n");

        $this->assertSame(['X-Tenant' => 'acme'], $headers);
    }

    public function testKeepsColonsInsideTheValue(): void {
        // A URL or a time in a header value must survive the split.
        $headers = HeaderSpec::parse('X-Origin: https://cloud.example.com:8443');

        $this->assertSame(['X-Origin' => 'https://cloud.example.com:8443'], $headers);
    }

    public function testAllowsHostOverride(): void {
        // The URL already decides which address is contacted, so a Host header
        // only selects a vhost there.
        $this->assertSame(['Host' => 'models.internal'], HeaderSpec::parse('Host: models.internal'));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function rejectedProvider(): array {
        return [
            'no colon' => ['X-Tenant acme', 'Expected "Name: value"'],
            'no name' => [': acme', 'Expected "Name: value"'],
            'no value' => ['X-Tenant:', 'has no value'],
            'invalid name' => ['X Tenant: acme', 'not a valid header name'],
            'content type' => ['Content-Type: text/plain', 'cannot be overridden'],
            'authorization' => ['Authorization: Bearer x', 'cannot be overridden'],
            'hop by hop' => ['Transfer-Encoding: chunked', 'cannot be overridden'],
            'content length' => ['Content-Length: 3', 'cannot be overridden'],
            'duplicate' => ["X-Tenant: a\nX-Tenant: b", 'is set twice'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function testRejects(string $raw, string $expected): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        HeaderSpec::parse($raw);
    }

    public function testRejectionNamesTheLine(): void {
        try {
            HeaderSpec::parse("X-Tenant: acme\nContent-Type: text/plain");
            $this->fail('expected a rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('(line 2)', $e->getMessage());
        }
    }

    public function testRequireNameAcceptsAnOrdinaryCustomHeader(): void {
        $this->assertSame('X-API-Key', HeaderSpec::requireName('X-API-Key'));
    }

    public function testRequireNameRejectsInjectionAttempt(): void {
        $this->expectException(\InvalidArgumentException::class);

        HeaderSpec::requireName("X-API-Key\r\nAuthorization");
    }
}
