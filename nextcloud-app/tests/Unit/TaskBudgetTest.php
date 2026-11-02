<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Anthropic\Core\BaseClient;
use Anthropic\Core\Conversion;
use Anthropic\Messages\Message;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\RequestMetadataService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskBudgetTestableService extends ClaudeSDKService {
    /** @var list<array<string, mixed>> */
    public array $createCalls = [];
    /** @var list<array<string, mixed>|null> */
    public array $requestOptions = [];
    public bool $platformServesTaskBudget = true;

    protected function platformFeatures(): array {
        return [self::FEATURE_TASK_BUDGET => $this->platformServesTaskBudget] + parent::platformFeatures();
    }

    protected function callCreate(BaseClient $client, array $params): Message {
        $this->createCalls[] = $params;
        $this->requestOptions[] = $this->requestOptionsForMessages($params);
        /** @var Message */
        return Conversion::coerce(Message::class, [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => $params['model'],
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn', 'stop_details' => null, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function getClient(?string $userId = null): Client {
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }
}

/**
 * Task budgets (beta task-budgets-2026-03-13): an advisory token ceiling for
 * one agentic loop, sent as output_config.task_budget.
 * https://platform.claude.com/docs/en/build-with-claude/task-budgets
 */
class TaskBudgetTest extends TestCase {
    private ?LoggerInterface $logger = null;

    /** @param array<string, string> $appValues */
    private function service(array $appValues = [], string $model = ClaudeModels::OPUS_5): TaskBudgetTestableService {
        $config = $this->createStub(AppSettings::class);
        $config->method('getUserValue')->willReturn('');
        $config->method('getAppValue')->willReturnCallback(
            fn($app, $key, $default) => $appValues[$key] ?? match ($key) {
                'model' => $model,
                'max_tokens' => '4096',
                // Keep these tests about the budget alone.
                'server_fallback' => '0',
                default => $default,
            }
        );
        $credentials = $this->createStub(CredentialService::class);
        $credentials->method('getApiKey')->willReturn('test-key');
        $cache = $this->createStub(ICache::class);
        $cache->method('get')->willReturn(null);
        $cacheFactory = $this->createStub(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        return new TaskBudgetTestableService(
            $config,
            $this->logger ?? $this->createStub(LoggerInterface::class),
            $credentials,
            $cacheFactory,
            $this->createStub(RequestMetadataService::class),
        );
    }

    private function runToolLoop(TaskBudgetTestableService $service, array $options = []): array {
        return $service->chatWithTools(
            [['role' => 'user', 'content' => 'Tidy my notes']],
            [['name' => 'list_files', 'description' => 'List', 'input_schema' => ['type' => 'object']]],
            fn() => [],
            null,
            'alice',
            $options,
        );
    }

    public function testOffByDefault(): void {
        $service = $this->service();
        $this->runToolLoop($service);

        $this->assertArrayNotHasKey('task_budget', $service->createCalls[0]);
        $this->assertNull($service->requestOptions[0]);
    }

    public function testAdminBudgetGoesOutAsOutputConfigWithItsBetaHeader(): void {
        $service = $this->service(['task_budget' => '64000', 'effort' => 'high']);
        $this->runToolLoop($service);

        $options = $service->requestOptions[0];
        $this->assertSame('task-budgets-2026-03-13', $options['extraHeaders']['anthropic-beta']);
        // Sent whole: an extra body key replaces output_config, so effort must ride along.
        $this->assertSame(
            ['effort' => 'high', 'task_budget' => ['type' => 'tokens', 'total' => 64000]],
            $options['extraBodyParams']['output_config'],
        );
        // No `remaining`: the loop resends full history and compacts nothing.
        $this->assertArrayNotHasKey('remaining', $options['extraBodyParams']['output_config']['task_budget']);
    }

    /** A budget is about a loop; a single answer does not get one. */
    public function testSingleRequestsCarryNoBudget(): void {
        $service = $this->service(['task_budget' => '64000']);
        $service->ask('Hi', '', 'alice');

        $this->assertArrayNotHasKey('task_budget', $service->createCalls[0]);
    }

    public function testCallerRunLoopStepsCarryIt(): void {
        $service = $this->service(['task_budget' => '64000']);
        $service->chatToolsTurn([['role' => 'user', 'content' => 'Hi']], [['name' => 't', 'description' => 'd', 'input_schema' => ['type' => 'object']]], null, 'alice');

        $this->assertSame(64000, $service->createCalls[0]['task_budget']);
    }

    public function testModelsWithoutSupportGetNone(): void {
        $service = $this->service(['task_budget' => '64000'], ClaudeModels::SONNET_5);
        $this->runToolLoop($service);

        $this->assertArrayNotHasKey('task_budget', $service->createCalls[0]);
    }

    public function testPlatformsWithoutSupportGetNone(): void {
        $service = $this->service(['task_budget' => '64000']);
        $service->platformServesTaskBudget = false;
        $this->runToolLoop($service);

        $this->assertArrayNotHasKey('task_budget', $service->createCalls[0]);
    }

    /** One bad setting must not break every tool-using answer. */
    public function testAnInvalidAdminBudgetIsLoggedAndIgnored(): void {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')
            ->with('AIquila SDK: Ignoring invalid task_budget default', $this->anything());
        $service = $this->service(['task_budget' => '5000']);

        $result = $this->runToolLoop($service);

        $this->assertSame('ok', $result['response']);
        $this->assertArrayNotHasKey('task_budget', $service->createCalls[0]);
    }

    public function testAnExplicitBudgetBelowTheMinimumIsAnError(): void {
        $service = $this->service();
        $result = $this->runToolLoop($service, ['task_budget' => 5000]);

        $this->assertStringContainsString('task_budget must be at least 20000', $result['error']);
        $this->assertSame([], $service->createCalls);
    }

    public function testAnExplicitBudgetWinsOverTheAdminDefault(): void {
        $service = $this->service(['task_budget' => '64000']);
        $this->runToolLoop($service, ['task_budget' => 30000]);

        $this->assertSame(30000, $service->createCalls[0]['task_budget']);
    }

    public function testModelTable(): void {
        $this->assertTrue(ClaudeModels::supportsTaskBudget(ClaudeModels::OPUS_5));
        $this->assertTrue(ClaudeModels::supportsTaskBudget(ClaudeModels::OPUS_4_7));
        $this->assertFalse(ClaudeModels::supportsTaskBudget(ClaudeModels::SONNET_5));
        $this->assertFalse(ClaudeModels::supportsTaskBudget(ClaudeModels::OPUS_4_6));
        $this->assertFalse(ClaudeModels::supportsTaskBudget(ClaudeModels::HAIKU_4_5));
    }
}
