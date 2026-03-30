<?php

namespace OCA\AIquila\Tests\Unit\SetupCheck;

use OCA\AIquila\SetupCheck\PhpExtensions;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class PhpExtensionsTest extends TestCase {
    private IL10N $l10n;
    private PhpExtensions $check;

    protected function setUp(): void {
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(
            fn(string $text, array $params = []) => $params ? vsprintf($text, $params) : $text
        );
        $this->check = new PhpExtensions($this->l10n);
    }

    public function testGetName(): void {
        $this->assertSame('AIquila PHP extensions', $this->check->getName());
    }

    public function testGetCategory(): void {
        $this->assertSame('system', $this->check->getCategory());
    }

    public function testSuccessWhenAllExtensionsLoaded(): void {
        // curl, json, mbstring, openssl are standard in PHP 8.4
        $result = $this->check->run();
        $this->assertSame('success', $result->getSeverity());
        $this->assertStringContainsString('curl', $result->getDescription());
    }
}
