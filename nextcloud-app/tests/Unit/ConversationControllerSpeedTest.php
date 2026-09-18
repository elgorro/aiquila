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
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers speedFast validation on ConversationController::update().
 *
 * Fast mode is the only conversation pin gated on both the provider and the
 * model, and the order matters: an Ollama user asking for /fast should be told
 * about Ollama, not about Opus.
 */
class ConversationControllerSpeedTest extends TestCase {
    private ConversationMapper $mapper;
    private Conversation $conversation;

    /** @param array<string, bool> $capabilityOverrides */
    private function makeController(array $capabilityOverrides = [], string $model = ClaudeModels::OPUS_5): ConversationController {
        $this->conversation = new Conversation();
        $this->conversation->setUserId('testuser');
        $this->conversation->setModel($model);

        $this->mapper = $this->createMock(ConversationMapper::class);
        $this->mapper->method('findByIdAndUser')->willReturn($this->conversation);
        $this->mapper->method('update')->willReturnArgument(0);

        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getCapabilities')->willReturn(array_merge([
            'vision' => true,
            'tools' => true,
            'streaming' => true,
            'thinking' => true,
            'effort' => true,
            'native_mcp' => true,
            'documents' => true,
            'fast_mode' => true,
        ], $capabilityOverrides));
        $provider->method('getLabel')->willReturn('Ollama');
        $provider->method('getModel')->willReturn($model);

        $factory = $this->createMock(LLMProviderFactory::class);
        $factory->method('hasPermittedProvider')->willReturn(true);
        $factory->method('getProvider')->willReturn($provider);
        $factory->method('getProviderById')->willReturn($provider);
        $factory->method('getProviderForUser')->willReturn($provider);

        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn([]);

        return new ConversationController(
            'aiquila',
            $request,
            $this->mapper,
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

    public function testFastModeOnIsStored(): void {
        $controller = $this->makeController();
        $response = $controller->update(1, speedFast: 'on');

        $this->assertEquals(200, $response->getStatus());
        $this->assertTrue($this->conversation->getSpeedFast());
    }

    /**
     * False is not the same as unset: it opts this conversation out of an
     * instance that has fast mode on by default.
     */
    public function testFastModeOffIsStoredAsFalseNotNull(): void {
        $controller = $this->makeController();
        $response = $controller->update(1, speedFast: 'off');

        $this->assertEquals(200, $response->getStatus());
        $this->assertFalse($this->conversation->getSpeedFast());
    }

    public function testEmptyStringClearsThePin(): void {
        $controller = $this->makeController();
        $this->conversation->setSpeedFast(true);

        $response = $controller->update(1, speedFast: '');

        $this->assertEquals(200, $response->getStatus());
        $this->assertNull($this->conversation->getSpeedFast());
    }

    /** Omitting the param must leave an existing pin alone. */
    public function testOmittedValueLeavesThePinUntouched(): void {
        $controller = $this->makeController();
        $this->conversation->setSpeedFast(true);

        $controller->update(1, title: 'Renamed');

        $this->assertTrue($this->conversation->getSpeedFast());
    }

    public function testGarbageValueIsRejected(): void {
        $controller = $this->makeController();
        $response = $controller->update(1, speedFast: 'yes');

        $this->assertEquals(400, $response->getStatus());
        $this->assertNull($this->conversation->getSpeedFast());
    }

    public function testProviderWithoutFastModeIsRejectedFirst(): void {
        $controller = $this->makeController(['fast_mode' => false]);
        $response = $controller->update(1, speedFast: 'on');

        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString('does not support fast mode', $response->getData()['error']);
        $this->assertStringContainsString('Ollama', $response->getData()['error']);
        $this->assertNull($this->conversation->getSpeedFast());
    }

    /** The provider supports fast mode; this particular model does not. */
    public function testModelWithoutFastModeIsRejected(): void {
        $controller = $this->makeController([], ClaudeModels::SONNET_5);
        $response = $controller->update(1, speedFast: 'on');

        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString(ClaudeModels::SONNET_5, $response->getData()['error']);
        $this->assertNull($this->conversation->getSpeedFast());
    }

    /** Turning it *off* needs no capability at all — it is always a valid thing to want. */
    public function testFastModeOffIsAcceptedOnAnUnsupportedModel(): void {
        $controller = $this->makeController(['fast_mode' => false], ClaudeModels::SONNET_5);
        $response = $controller->update(1, speedFast: 'off');

        $this->assertEquals(200, $response->getStatus());
        $this->assertFalse($this->conversation->getSpeedFast());
    }
}
