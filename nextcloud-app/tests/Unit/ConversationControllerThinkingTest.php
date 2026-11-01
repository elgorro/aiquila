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
use OCA\AIquila\Service\RuntimeContextService;
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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the model-aware side of /thinking: refusing "off" on models that
 * always think, refusing any override on providers without a thinking
 * profile, the effective-state endpoint behind the chat badge, and dropping a
 * pinned effort the newly chosen model rejects.
 */
class ConversationControllerThinkingTest extends TestCase {
    private Conversation $conversation;
    /** @var (LLMProviderInterface&ThinkingProfileInterface&Stub)|(LLMProviderInterface&Stub) */
    private $provider;
    private ConversationMapper $mapper;

    private function makeController(string $model, bool $thinkingProfile = true): ConversationController {
        $this->conversation = new Conversation();
        $this->conversation->setUserId('testuser');
        $this->conversation->setModel($model);
        $this->conversation->setProvider('anthropic');

        $this->mapper = $this->createStub(ConversationMapper::class);
        $this->mapper->method('findByIdAndUser')->willReturn($this->conversation);
        $this->mapper->method('update')->willReturnArgument(0);

        $this->provider = $thinkingProfile
            ? $this->createStubForIntersectionOfInterfaces([LLMProviderInterface::class, ThinkingProfileInterface::class])
            : $this->createStub(LLMProviderInterface::class);
        $this->provider->method('getLabel')->willReturn('Hetzner');
        $this->provider->method('getCapabilities')->willReturn([
            'vision' => true, 'tools' => true, 'streaming' => true, 'thinking' => $thinkingProfile,
            'effort' => true, 'native_mcp' => true, 'documents' => true,
        ]);
        $this->provider->method('getModel')->willReturn($model);
        $this->provider->method('getAllowedEfforts')
            ->willReturnCallback(fn(string $m) => ClaudeModels::getAllowedEfforts($m));
        if (!$thinkingProfile) {
            return $this->controller($this->provider);
        }
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

        return $this->controller($this->provider);
    }

    private function controller(LLMProviderInterface $provider): ConversationController {
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('hasPermittedProvider')->willReturn(true);
        $factory->method('getProvider')->willReturn($provider);
        $factory->method('getProviderById')->willReturn($provider);
        $factory->method('getProviderForUser')->willReturn($provider);
        $factory->method('isKnownProviderId')->willReturn(true);
        $factory->method('isAllowedForUser')->willReturn(true);

        $request = $this->createStub(IRequest::class);
        $request->method('getParams')->willReturn([]);

        return new ConversationController(
            'aiquila',
            $request,
            $this->mapper,
            $this->createStub(MessageMapper::class),
            $this->createStub(MessageFileMapper::class),
            $this->createStub(ProjectMapper::class),
            $this->createStub(ProjectPathMapper::class),
            $factory,
            $this->createStub(FileService::class),
            $this->createStub(FilesService::class),
            $this->createStub(ImageOptimizer::class),
            $this->createStub(McpClientService::class),
            $this->createStub(NativeMcpService::class),
            $this->createStub(IJobList::class),
            $this->createStub(ContextChatService::class),
            $this->createStub(RuntimeContextService::class),
            'testuser',
            $this->createStub(LoggerInterface::class),
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

    public function testThinkingOnRejectedWithoutAThinkingProfile(): void {
        $controller = $this->makeController('Qwen/Qwen3.6-35B-A3B-FP8', thinkingProfile: false);
        $response = $controller->update(1, thinking: 'on');

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Hetzner does not support thinking', $response->getData()['error']);
        $this->assertNull($this->conversation->getThinking());
    }

    public function testThinkingOffRejectedWithoutAThinkingProfile(): void {
        $controller = $this->makeController('Qwen/Qwen3.6-35B-A3B-FP8', thinkingProfile: false);

        $this->assertSame(400, $controller->update(1, thinking: 'off')->getStatus());
        $this->assertNull($this->conversation->getThinking());
    }

    public function testThinkingAutoStillAcceptedWithoutAThinkingProfile(): void {
        $controller = $this->makeController('Qwen/Qwen3.6-35B-A3B-FP8', thinkingProfile: false);
        $this->conversation->setThinking(true);

        $this->assertSame(200, $controller->update(1, thinking: 'auto')->getStatus());
        $this->assertNull($this->conversation->getThinking());
    }

    public function testThinkingEndpointReportsAStoredOverrideWithoutAThinkingProfile(): void {
        $controller = $this->makeController('Qwen/Qwen3.6-35B-A3B-FP8', thinkingProfile: false);
        // Stored before the provider was switched, or before this check existed.
        $this->conversation->setThinking(true);

        $data = $controller->thinking(1)->getData();

        $this->assertSame('on', $data['mode']);
        $this->assertSame('none', $data['state']);
    }

    public function testSwitchingToAProviderWithoutThinkingDropsTheOverride(): void {
        $controller = $this->makeController('Qwen/Qwen3.6-35B-A3B-FP8', thinkingProfile: false);
        $this->conversation->setThinking(false);

        $controller->setModel(1, provider: 'hetzner');

        $this->assertNull($this->conversation->getThinking());
    }
}
