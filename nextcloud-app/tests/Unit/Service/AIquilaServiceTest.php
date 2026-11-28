<?php

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Service\AIquilaService;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\FileService;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The public IAIquila API other apps call: isConfigured(), getStatus(), ask()
 * and summarize(), with the model provider behind a mock.
 */
#[AllowMockObjectsWithoutExpectations]
class AIquilaServiceTest extends TestCase {
    private ClaudeSDKService&MockObject $claude;
    private UsageStatService&MockObject $usage;

    protected function setUp(): void {
        $this->claude = $this->createMock(ClaudeSDKService::class);
        $this->usage = $this->createMock(UsageStatService::class);
    }

    private function service(): AIquilaService {
        return new AIquilaService(
            $this->claude,
            $this->createStub(FileService::class),
            $this->createStub(ImageOptimizer::class),
            $this->usage,
            $this->createStub(INotificationManager::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    public function testIsConfiguredFollowsTheApiKey(): void {
        $this->claude->method('getApiKey')->willReturnMap([
            ['alice', 'sk-ant-key'],
            ['bob', ''],
        ]);

        $this->assertTrue($this->service()->isConfigured('alice'));
        $this->assertFalse($this->service()->isConfigured('bob'));
    }

    public function testGetStatusReportsConfigurationWithoutTheKey(): void {
        $this->claude->method('getConfiguration')->willReturn([
            'api_key' => 'sk-ant-secret',
            'model' => 'claude-opus-5',
            'max_tokens' => 4096,
            'timeout' => 30,
        ]);

        $status = $this->service()->getStatus();

        $this->assertSame([
            'configured' => true,
            'model' => 'claude-opus-5',
            'max_tokens' => 4096,
            'timeout' => 30,
        ], $status);
    }

    public function testGetStatusIsUnconfiguredWithoutAKey(): void {
        $this->claude->method('getConfiguration')->willReturn([
            'api_key' => '',
            'model' => 'claude-opus-5',
            'max_tokens' => 4096,
            'timeout' => 30,
        ]);

        $this->assertFalse($this->service()->getStatus()['configured']);
    }

    public function testAskPassesPromptAndContextAndRecordsUsage(): void {
        $result = ['response' => 'Nextcloud is a file sync platform.', 'usage' => ['input_tokens' => 10, 'output_tokens' => 7]];
        $this->claude->expects($this->once())->method('ask')
            ->with('What is Nextcloud?', 'README text', 'admin', LLMProviderInterface::TASK_OPTIONS)
            ->willReturn($result);
        $this->usage->expects($this->once())->method('record')
            ->with('admin', $this->claude, $result, UsageStatService::TYPE_API);

        $this->assertSame($result, $this->service()->ask('What is Nextcloud?', 'README text', 'admin'));
    }

    public function testAskPassesProviderErrorsThrough(): void {
        $this->claude->method('ask')->willReturn(['error' => 'invalid x-api-key']);

        $this->assertSame(['error' => 'invalid x-api-key'], $this->service()->ask('hi', '', 'admin'));
    }

    public function testAskTurnsExceptionsIntoAnError(): void {
        $this->claude->method('ask')->willThrowException(new \RuntimeException('connection reset'));

        $this->assertSame(['error' => 'Internal error: connection reset'], $this->service()->ask('hi', '', 'admin'));
    }

    public function testSummarizeDelegatesTheContent(): void {
        $this->claude->expects($this->once())->method('summarize')
            ->with('long README', 'admin')
            ->willReturn(['response' => 'A short summary.']);

        $this->assertSame(['response' => 'A short summary.'], $this->service()->summarize('long README', 'admin'));
    }

    public function testSummarizeTurnsExceptionsIntoAnError(): void {
        $this->claude->method('summarize')->willThrowException(new \RuntimeException('timeout'));

        $this->assertSame(['error' => 'Internal error: timeout'], $this->service()->summarize('x', 'admin'));
    }
}
