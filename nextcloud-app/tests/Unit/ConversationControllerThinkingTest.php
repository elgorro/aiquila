<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\ConversationController;
use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\MessageFileMapper;
use OCA\AIquila\Db\MessageMapper;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ContextChatService;
use OCA\AIquila\Service\FileService;
use OCA\AIquila\Service\FilesService;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\McpClientService;
use OCA\AIquila\Service\NativeMcpService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\ThinkingProfileInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the model-aware side of /thinking: refusing "off" on models that
 * always think, the effective-state endpoint behind the chat badge, and
 * dropping a pinned effort the newly chosen model rejects.
 */
class ConversationControllerThinkingTest extends TestCase {
    private Conversation $conversation;
    /** @var LLMProviderInterface&ThinkingProfileInterface&MockObject */
    private $provider;

    private function makeController(string $model): ConversationController {
        $this->conversation = new Conversation();
        $this->conversation->setUserId('testuser');
        $this->conversation->setModel($model);
        $this->conversation->setProvider('anthropic');

        $mapper = $this->createMock(ConversationMapper::class);
        $mapper->method('findByIdAndUser')->willReturn($this->conversation);
        $mapper->method('update')->willReturnArgument(0);

        $this->provider = $this->createMockForIntersectionOfInterfaces([LLMProviderInterface::class, ThinkingProfileInterface::class]);
        $this->provider->method('getCapabilities')->willReturn([
            'vision' => true, 'tools' => true, 'streaming' => true, 'thinking' => true,
            'effort' => true, 'native_mcp' => true, 'documents' => true,
        ]);
        $this->provider->method('getModel')->willReturn($model);
        $this->provider->method('getAllowedEfforts')
            ->willReturnCallback(fn(string $m) => ClaudeModels::getAllowedEfforts($m));
        $this->provider->method('getThinkingProfile')
            ->willReturnCallback(fn(string $m) => [
                'model' => $m,
                'thinking' => ClaudeModels::canDisableThinking($m) ? 'adaptive_by_default' : 'always_on',
                'can_disable' => ClaudeModels::canDisableThinking($m),
                'off_max_effort' => ClaudeModels::maxEffortWithThinkingDisabled($m),
                'efforts' => ClaudeModels::getAllowedEfforts($m),
                'default_effort' => ClaudeModels::getEffortLevel($m),
            ]);
        $this->provider->method('describeThinking')
            ->willReturnCallback(fn(?string $uid, array $options) => [
                'model' => $options['model'] ?? $model,
                'mode' => ($options['thinking'] ?? null) === false ? 'off' : 'auto',
                'state' => 'off',
                'always_on' => false,
                'effort' => 'high',
                'budget' => null,
                'adjustments' => ['effort_capped'],
            ]);

        $factory = $this->createMock(LLMProviderFactory::class);
        $factory->method('hasPermittedProvider')->willReturn(true);
        $factory->method('getProvider')->willReturn($this->provider);
        $factory->method('getProviderById')->willReturn($this->provider);
        $factory->method('getProviderForUser')->willReturn($this->provider);
        $factory->method('isKnownProviderId')->willReturn(true);
        $factory->method('isAllowedForUser')->willReturn(true);

        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn([]);

        return new ConversationController(
            'aiquila',
            $request,
            $mapper,
            $this->createMock(MessageMapper::class),
            $this->createMock(MessageFileMapper::class),
            $this->createMock(ProjectMapper::class),
            $this->createMock(ProjectPathMapper::class),
            $factory,
            $this->createMock(FileService::class),
            $this->createMock(FilesService::class),
            $this->createMock(ImageOptimizer::class),
            $this->createMock(McpClientService::class),
            $this->createMock(NativeMcpService::class),
            $this->createMock(IJobList::class),
            $this->createMock(ContextChatService::class),
            'testuser',
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testThinkingOffRejectedOnAlwaysOnModel(): void {
        $controller = $this->makeController(ClaudeModels::OPUS_5_5);
        $response = $controller->update(1, thinking: 'off');

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('always thinks', $response->getData()['error']);
        $this->assertNull($this->conversation->getThinking());
    }

    public function testThinkingOffAcceptedOnOpus5(): void {
        $controller = $this->makeController(ClaudeModels::OPUS_5);
        $response = $controller->update(1, thinking: 'off');

        $this->assertSame(200, $response->getStatus());
        $this->assertFalse($this->conversation->getThinking());
    }

    public function testThinkingAutoClearsTheOverride(): void {
        $controller = $this->makeController(ClaudeModels::OPUS_5);
        $this->conversation->setThinking(true);

        $controller->update(1, thinking: 'auto');

        $this->assertNull($this->conversation->getThinking());
    }

    public function testThinkingEndpointReportsEffectiveState(): void {
        $controller = $this->makeController(ClaudeModels::OPUS_5);
        $this->conversation->setThinking(false);

        $data = $controller->thinking(1)->getData();

        $this->assertSame(ClaudeModels::OPUS_5, $data['model']);
        $this->assertSame('off', $data['mode']);
        $this->assertSame('high', $data['effort']);
        $this->assertSame(['effort_capped'], $data['adjustments']);
    }

    public function testSwitchingModelDropsARejectedEffort(): void {
        $controller = $this->makeController(ClaudeModels::OPUS_5);
        $this->conversation->setEffort('xhigh');

        // Sonnet 4.6 has no xhigh.
        $controller->setModel(1, model: ClaudeModels::SONNET_4_6);

        $this->assertNull($this->conversation->getEffort());
    }

    public function testSwitchingModelKeepsAnAcceptedEffort(): void {
        $controller = $this->makeController(ClaudeModels::OPUS_5);
        $this->conversation->setEffort('high');

        $controller->setModel(1, model: ClaudeModels::SONNET_4_6);

        $this->assertSame('high', $this->conversation->getEffort());
    }
}
