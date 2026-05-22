<?php

namespace OCA\AIquila\Tests\Unit\SetupCheck;

use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\SetupCheck\AnthropicApiReachable;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class AnthropicApiReachableTest extends TestCase {
    private ClaudeSDKService $sdk;
    private CredentialService $credentials;
    private IL10N $l10n;
    private AnthropicApiReachable $check;

    protected function setUp(): void {
        $this->sdk = $this->createMock(ClaudeSDKService::class);
        $this->credentials = $this->createMock(CredentialService::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(
            fn(string $text, array $params = []) => $params ? vsprintf($text, $params) : $text
        );
        $this->check = new AnthropicApiReachable($this->sdk, $this->credentials, $this->l10n);
    }

    public function testGetName(): void {
        $this->assertSame('AIquila API connectivity', $this->check->getName());
    }

    public function testGetCategory(): void {
        $this->assertSame('network', $this->check->getCategory());
    }

    public function testInfoWhenNoApiKey(): void {
        $this->credentials->method('hasApiKey')->with(null)->willReturn(false);
        $result = $this->check->run();
        $this->assertSame('info', $result->getSeverity());
    }

    public function testSuccessWhenApiReachable(): void {
        $this->credentials->method('hasApiKey')->with(null)->willReturn(true);
        $this->sdk->method('listModels')->with(null)->willReturn(['claude-sonnet-4-6']);
        $result = $this->check->run();
        $this->assertSame('success', $result->getSeverity());
    }

    public function testErrorWhenApiReturnsNull(): void {
        $this->credentials->method('hasApiKey')->with(null)->willReturn(true);
        $this->sdk->method('listModels')->with(null)->willReturn(null);
        $result = $this->check->run();
        $this->assertSame('error', $result->getSeverity());
    }

    public function testErrorWhenApiThrows(): void {
        $this->credentials->method('hasApiKey')->with(null)->willReturn(true);
        $this->sdk->method('listModels')->willThrowException(new \RuntimeException('Connection refused'));
        $result = $this->check->run();
        $this->assertSame('error', $result->getSeverity());
        $this->assertStringContainsString('Connection refused', $result->getDescription());
    }
}
