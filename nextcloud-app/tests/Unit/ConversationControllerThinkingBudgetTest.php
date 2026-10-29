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
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\ContextChatService;
use OCA\AIquila\Service\FileService;
use OCA\AIquila\Service\FilesService;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\McpClientService;
use OCA\AIquila\Service\NativeMcpService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers thinkingBudget validation on ConversationController::update().
 *
 * The budget is the only conversation pin with a numeric range, and the range
 * depends on the provider's max_tokens rather than a static table, so it is
 * worth pinning down.
 */
class ConversationControllerThinkingBudgetTest extends TestCase {
    private const MAX_TOKENS = 4096;

    private ConversationMapper $mapper;
    private Conversation $conversation;

    /** @param array<string, bool> $capabilityOverrides */
    private function makeController(array $capabilityOverrides = []): ConversationController {
        $this->conversation = new Conversation();
        $this->conversation->setUserId('testuser');
        $this->conversation->setModel(ClaudeModels::DEFAULT_MODEL);

        $this->mapper = $this->createStub(ConversationMapper::class);
        $this->mapper->method('findByIdAndUser')->willReturn($this->conversation);
        $this->mapper->method('update')->willReturnArgument(0);

        $provider = $this->createStub(LLMProviderInterface::class);
        $provider->method('getCapabilities')->willReturn(array_merge([
            'vision' => true,
            'tools' => true,
            'streaming' => true,
            'thinking' => true,
            'effort' => true,
            'native_mcp' => true,
            'documents' => true,
        ], $capabilityOverrides));
        $provider->method('getMaxTokens')->willReturn(self::MAX_TOKENS);
        $provider->method('getLabel')->willReturn('Ollama');
        $provider->method('getModel')->willReturn(ClaudeModels::DEFAULT_MODEL);

        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('hasPermittedProvider')->willReturn(true);
        $factory->method('getProvider')->willReturn($provider);
        $factory->method('getProviderById')->willReturn($provider);
        $factory->method('getProviderForUser')->willReturn($provider);

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
            'testuser',
            $this->createStub(LoggerInterface::class),
        );
    }

    public function testValidBudgetIsStored(): void {
        $controller = $this->makeController();
        $response = $controller->update(1, thinkingBudget: '2048');

        $this->assertEquals(200, $response->getStatus());
        $this->assertSame(2048, $this->conversation->getThinkingBudget());
    }

    public function testEmptyStringClearsTheBudget(): void {
        $controller = $this->makeController();
        $this->conversation->setThinkingBudget(2048);

        $response = $controller->update(1, thinkingBudget: '');

        $this->assertEquals(200, $response->getStatus());
        $this->assertNull($this->conversation->getThinkingBudget());
    }

    /** Omitting the param must leave an existing pin alone. */
    public function testOmittedBudgetLeavesThePinUntouched(): void {
        $controller = $this->makeController();
        $this->conversation->setThinkingBudget(2048);

        $controller->update(1, title: 'Renamed');

        $this->assertSame(2048, $this->conversation->getThinkingBudget());
    }

    public function testBudgetBelowMinimumIsRejected(): void {
        $controller = $this->makeController();
        $response = $controller->update(1, thinkingBudget: '512');

        $this->assertEquals(400, $response->getStatus());
        $this->assertNull($this->conversation->getThinkingBudget());
    }

    /** The ceiling is the provider's max_tokens, not a static model table. */
    public function testBudgetAtMaxTokensIsRejected(): void {
        $controller = $this->makeController();
        $response = $controller->update(1, thinkingBudget: (string)self::MAX_TOKENS);

        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString(
            (string)(self::MAX_TOKENS - 1),
            $response->getData()['error'],
        );
        $this->assertNull($this->conversation->getThinkingBudget());
    }

    public function testNonNumericBudgetIsRejected(): void {
        $controller = $this->makeController();
        $response = $controller->update(1, thinkingBudget: '8k');

        $this->assertEquals(400, $response->getStatus());
        $this->assertNull($this->conversation->getThinkingBudget());
    }

    public function testProviderWithoutThinkingIsRejectedFirst(): void {
        $controller = $this->makeController(['thinking' => false]);
        $response = $controller->update(1, thinkingBudget: '2048');

        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString('does not support a thinking budget', $response->getData()['error']);
        $this->assertNull($this->conversation->getThinkingBudget());
    }

    public function testMinimumBoundaryIsAccepted(): void {
        $controller = $this->makeController();
        $response = $controller->update(1, thinkingBudget: (string)ClaudeSDKService::MIN_THINKING_BUDGET);

        $this->assertEquals(200, $response->getStatus());
        $this->assertSame(ClaudeSDKService::MIN_THINKING_BUDGET, $this->conversation->getThinkingBudget());
    }
}
