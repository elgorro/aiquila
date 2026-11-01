<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Command;

use OCA\AIquila\Command\TestSDKCommand;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `aiquila:test` exercises the provider that actually serves the user, not
 * always the Anthropic SDK, and masks keys tightly.
 */
class TestSDKCommandTest extends TestCase {
    private function local(bool $mock = false, string $key = 'llama-server-token-123'): LLMProviderInterface {
        $provider = $mock ? $this->createMock(LLMProviderInterface::class) : $this->createStub(LLMProviderInterface::class);
        $provider->method('getId')->willReturn('local');
        $provider->method('getLabel')->willReturn('Local');
        $provider->method('isConfigured')->willReturn(true);
        $provider->method('getApiKey')->willReturn($key);
        $provider->method('getModel')->willReturn('qwen3');
        $provider->method('getMaxTokens')->willReturn(4096);
        return $provider;
    }

    private function tester(LLMProviderFactory $factory): CommandTester {
        $claude = $this->createMock(ClaudeSDKService::class);
        $claude->expects($this->never())->method('ask');
        return new CommandTester(new TestSDKCommand($claude, $factory));
    }

    public function testUsesTheProviderResolvedForTheUser(): void {
        $local = $this->local(true);
        $local->expects($this->once())->method('ask')
            ->with($this->anything(), '', 'alice')
            ->willReturn(['response' => 'Test Successful']);

        $factory = $this->createMock(LLMProviderFactory::class);
        $factory->expects($this->once())->method('getProvider')->with('alice')->willReturn($local);

        $tester = $this->tester($factory);
        $code = $tester->execute(['--user' => 'alice']);

        $this->assertSame(0, $code, $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Local (local)', $display);
        $this->assertStringContainsString('Test Successful', $display);
        $this->assertStringContainsString('…-123', $display);
        $this->assertStringNotContainsString('llama-server', $display);
    }

    public function testExplicitProvider(): void {
        $local = $this->local();
        $local->method('ask')->willReturn(['response' => 'ok']);

        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('isKnownProviderId')->willReturn(true);
        $factory->method('getProviderById')->willReturn($local);

        $tester = $this->tester($factory);
        $this->assertSame(0, $tester->execute(['--provider' => 'local']), $tester->getDisplay());
    }

    public function testExplicitProviderRespectsTheUsersAccessLists(): void {
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('isKnownProviderId')->willReturn(true);
        $factory->method('isAllowedForUser')->willReturn(false);

        $tester = $this->tester($factory);
        $this->assertSame(1, $tester->execute(['--provider' => 'local', '--user' => 'bob']));
        $this->assertStringContainsString('may not use provider local', $tester->getDisplay());
    }

    public function testStreamingIsAnthropicOnly(): void {
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProvider')->willReturn($this->local());

        $tester = $this->tester($factory);
        $this->assertSame(1, $tester->execute(['--stream' => true]));
    }

    public function testShortKeysAreNotPartiallyShown(): void {
        $this->assertSame('(set)', TestSDKCommand::maskKey('short-token'));
        $this->assertSame('…cdef', TestSDKCommand::maskKey('sk-ant-0123456789abcdef'));
    }
}
