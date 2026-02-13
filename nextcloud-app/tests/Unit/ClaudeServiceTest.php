<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClaudeServiceTest extends TestCase {
    private $config;
    private $logger;
    private ClaudeSDKService $service;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ClaudeSDKService($this->config, $this->logger);
    }

    public function testGetApiKeyReturnsUserKeyFirst(): void {
        $this->config->method('getUserValue')
            ->with('testuser', 'aiquila', 'api_key', '')
            ->willReturn('user-api-key');

        $result = $this->service->getApiKey('testuser');
        $this->assertEquals('user-api-key', $result);
    }

    public function testGetApiKeyFallsBackToAdminKey(): void {
        $this->config->method('getUserValue')
            ->with('testuser', 'aiquila', 'api_key', '')
            ->willReturn('');

        $this->config->method('getAppValue')
            ->with('aiquila', 'api_key', '')
            ->willReturn('admin-api-key');

        $result = $this->service->getApiKey('testuser');
        $this->assertEquals('admin-api-key', $result);
    }

    public function testAskReturnsErrorWhenNoApiKey(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('');

        $result = $this->service->ask('Hello', '', 'testuser');
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetModelReturnsDefault(): void {
        $this->config->method('getAppValue')
            ->with('aiquila', 'model', ClaudeModels::DEFAULT_MODEL)
            ->willReturn(ClaudeModels::DEFAULT_MODEL);

        $result = $this->service->getModel();
        $this->assertEquals(ClaudeModels::DEFAULT_MODEL, $result);
    }

    public function testGetMaxTokensReturnsDefault(): void {
        // getMaxTokens() also calls getModel() internally to apply the ceiling
        $this->config->method('getAppValue')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'model') return ClaudeModels::DEFAULT_MODEL;
                if ($key === 'max_tokens') return '4096';
                return $default;
            });

        $result = $this->service->getMaxTokens();
        $this->assertEquals(4096, $result);
    }

    public function testGetConfigurationReturnsExpectedKeys(): void {
        $this->config->method('getAppValue')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'api_key') return 'test-key';
                if ($key === 'model') return ClaudeModels::DEFAULT_MODEL;
                if ($key === 'max_tokens') return '4096';
                if ($key === 'api_timeout') return '30';
                return $default;
            });

        $config = $this->service->getConfiguration();
        $this->assertArrayHasKey('api_key', $config);
        $this->assertArrayHasKey('model', $config);
        $this->assertArrayHasKey('max_tokens', $config);
        $this->assertArrayHasKey('timeout', $config);
        $this->assertEquals('test-key', $config['api_key']);
    }

    public function testSummarizeCallsAskWithSummarizePrompt(): void {
        // With no API key configured, summarize should return an error
        // (proving it delegates to ask())
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('');

        $result = $this->service->summarize('Long text here...', 'testuser');
        $this->assertArrayHasKey('error', $result);
    }

    public function testSendMessageThrowsOnError(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('');

        $this->expectException(\Exception::class);
        $this->service->sendMessage('Hello', 'testuser');
    }
}
