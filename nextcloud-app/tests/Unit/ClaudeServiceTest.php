<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\ClaudeService;
use OCP\IConfig;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

class ClaudeServiceTest extends TestCase {
    private $config;
    private $clientService;
    private $httpClient;
    private ClaudeService $service;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->httpClient = $this->createMock(IClient::class);

        $this->clientService->method('newClient')->willReturn($this->httpClient);

        $this->service = new ClaudeService($this->config, $this->clientService);
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
        $this->assertEquals('No API key configured', $result['error']);
    }

    public function testAskMakesApiCallWithCorrectPayload(): void {
        $this->config->method('getUserValue')->willReturn('test-api-key');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'content' => [['text' => 'Hello! How can I help?']]
        ]));

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://api.anthropic.com/v1/messages',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);
                    return $body['model'] === 'claude-sonnet-4-20250514'
                        && $body['max_tokens'] === 4096
                        && count($body['messages']) === 1;
                })
            )
            ->willReturn($response);

        $result = $this->service->ask('Hello', '', 'testuser');
        $this->assertEquals('Hello! How can I help?', $result['response']);
    }

    public function testSummarizeCallsAskWithSummarizePrompt(): void {
        $this->config->method('getUserValue')->willReturn('test-api-key');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'content' => [['text' => 'This is a summary.']]
        ]));

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://api.anthropic.com/v1/messages',
                $this->callback(function ($options) {
                    $body = json_decode($options['body'], true);
                    return str_contains($body['messages'][0]['content'], 'Summarize');
                })
            )
            ->willReturn($response);

        $result = $this->service->summarize('Long text here...', 'testuser');
        $this->assertEquals('This is a summary.', $result['response']);
    }
}
