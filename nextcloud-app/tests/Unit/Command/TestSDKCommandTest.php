<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Command;

use OCA\AIquila\Command\TestSDKCommand;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\InstructionsService;
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

    private function tester(LLMProviderFactory $factory, ?InstructionsService $instructions = null): CommandTester {
        $claude = $this->createMock(ClaudeSDKService::class);
        $claude->expects($this->never())->method('ask');
        return new CommandTester(new TestSDKCommand($claude, $factory, $instructions ?? $this->createStub(InstructionsService::class)));
    }

    /**
     * An Anthropic provider whose two `ask()` calls report $first then $second
     * as [cache_creation_tokens, cache_read_tokens].
     *
     * @param array{int, int} $first
     * @param array{int, int} $second
     */
    private function anthropicCaching(array $first, array $second): LLMProviderInterface {
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getId')->willReturn(LLMProviderFactory::DEFAULT_PROVIDER);
        $provider->method('getLabel')->willReturn('Claude');
        $provider->method('isConfigured')->willReturn(true);
        $provider->method('getApiKey')->willReturn('sk-ant-0123456789abcdef');
        $provider->method('getModel')->willReturn('claude-opus-5');
        $provider->method('getMaxTokens')->willReturn(4096);
        $usage = static fn(array $u): array => ['response' => 'ok', 'usage' => [
            'input_tokens' => 12, 'output_tokens' => 3, 'cache_creation_tokens' => $u[0], 'cache_read_tokens' => $u[1],
        ]];
        $provider->expects($this->exactly(2))->method('ask')
            ->with($this->anything(), '', 'alice', ['system' => 'Be terse.'])
            ->willReturnOnConsecutiveCalls($usage($first), $usage($second));
        return $provider;
    }

    /**
     * @param array{int, int} $first
     * @param array{int, int} $second
     */
    private function runCacheCheck(array $first, array $second): CommandTester {
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProvider')->willReturn($this->anthropicCaching($first, $second));
        $instructions = $this->createStub(InstructionsService::class);
        $instructions->method('forChat')->willReturn('Be terse.');
        $tester = $this->tester($factory, $instructions);
        $tester->execute(['--cache-check' => true, '--user' => 'alice']);
        return $tester;
    }

    public function testCacheCheckReportsAHit(): void {
        $tester = $this->runCacheCheck([900, 0], [0, 900]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Cache hit', $tester->getDisplay());
    }

    public function testCacheCheckTreatsAShortPrefixAsExpected(): void {
        $tester = $this->runCacheCheck([0, 0], [0, 0]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('below the minimum cacheable length for claude-opus-5', $tester->getDisplay());
    }

    public function testCacheCheckFailsWhenTheWriteIsNeverReadBack(): void {
        $tester = $this->runCacheCheck([900, 0], [900, 0]);
        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Cache miss', $tester->getDisplay());
    }

    public function testCacheCheckIsAnthropicOnly(): void {
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProvider')->willReturn($this->local());

        $tester = $this->tester($factory);
        $this->assertSame(1, $tester->execute(['--cache-check' => true]));
        $this->assertStringContainsString('--cache-check is only supported', $tester->getDisplay());
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
