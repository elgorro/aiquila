<?php

namespace OCA\AIquila\Tests\Unit\SetupCheck;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\SetupCheck\ApiKeyConfigured;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ApiKeyConfiguredTest extends TestCase {
    private CredentialService $credentials;
    private IL10N $l10n;
    private ApiKeyConfigured $check;

    protected function setUp(): void {
        $this->credentials = $this->createMock(CredentialService::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(
            fn(string $text, array $params = []) => $params ? vsprintf($text, $params) : $text
        );
        $this->check = new ApiKeyConfigured($this->credentials, $this->l10n);
    }

    public function testGetName(): void {
        $this->assertSame('AIquila API key', $this->check->getName());
    }

    public function testGetCategory(): void {
        $this->assertSame('system', $this->check->getCategory());
    }

    public function testSuccessWhenKeyConfigured(): void {
        $this->credentials->method('hasApiKey')->with(null)->willReturn(true);
        $result = $this->check->run();
        $this->assertSame('success', $result->getSeverity());
    }

    public function testErrorWhenKeyMissing(): void {
        $this->credentials->method('hasApiKey')->with(null)->willReturn(false);
        $result = $this->check->run();
        $this->assertSame('error', $result->getSeverity());
        $this->assertStringContainsString('No Anthropic API key configured', $result->getDescription());
    }
}
