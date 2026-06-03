<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\PageController;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class PageControllerTest extends TestCase {
    private $initialState;
    private $factory;
    private $provider;
    private $conversationMapper;
    private $request;
    private PageController $ctrl;

    protected function setUp(): void {
        $this->initialState       = $this->createMock(IInitialState::class);
        $this->factory            = $this->createMock(LLMProviderFactory::class);
        $this->provider           = $this->createMock(LLMProviderInterface::class);
        $this->conversationMapper = $this->createMock(ConversationMapper::class);
        $this->request            = $this->createMock(IRequest::class);

        $this->conversationMapper->method('findAllByUser')->willReturn([]);
        $this->provider->method('getId')->willReturn('anthropic');
        $this->factory->method('getProvider')->willReturn($this->provider);

        $this->ctrl = new PageController(
            'aiquila',
            $this->request,
            $this->initialState,
            $this->factory,
            $this->conversationMapper,
            'testuser'
        );
    }

    private function stubConfiguration(): void {
        $this->provider->method('getConfiguration')
            ->willReturn([
                'api_key'    => '',
                'model'      => ClaudeModels::DEFAULT_MODEL,
                'max_tokens' => 4096,
                'timeout'    => 30,
            ]);
    }

    public function testIndexProvidesInitialStateWithUserEffectiveModel(): void {
        $this->provider->expects($this->atLeastOnce())
            ->method('getModel')
            ->with('testuser')
            ->willReturn(ClaudeModels::HAIKU_4_5);
        $this->stubConfiguration();

        $captured = [];
        $this->initialState->method('provideInitialState')
            ->willReturnCallback(function (string $key, mixed $data) use (&$captured): void {
                $captured[$key] = $data;
            });

        $this->ctrl->index();

        $this->assertArrayHasKey('config', $captured);
        $this->assertArrayHasKey('model', $captured['config']);
        $this->assertEquals(ClaudeModels::HAIKU_4_5, $captured['config']['model']);
    }

    public function testIndexProvidesHasApiKeyTrue(): void {
        $this->provider->method('getModel')->willReturn(ClaudeModels::DEFAULT_MODEL);
        $this->provider->method('isConfigured')->willReturn(true);
        $this->stubConfiguration();

        $captured = [];
        $this->initialState->method('provideInitialState')
            ->willReturnCallback(function (string $key, mixed $data) use (&$captured): void {
                $captured[$key] = $data;
            });

        $this->ctrl->index();

        $this->assertTrue($captured['config']['has_api_key']);
    }

    public function testIndexProvidesHasApiKeyFalse(): void {
        $this->provider->method('getModel')->willReturn(ClaudeModels::DEFAULT_MODEL);
        $this->provider->method('isConfigured')->willReturn(false);
        $this->stubConfiguration();

        $captured = [];
        $this->initialState->method('provideInitialState')
            ->willReturnCallback(function (string $key, mixed $data) use (&$captured): void {
                $captured[$key] = $data;
            });

        $this->ctrl->index();

        $this->assertFalse($captured['config']['has_api_key']);
    }

    public function testIndexReturnsTemplateResponse(): void {
        $this->provider->method('getModel')->willReturn(ClaudeModels::DEFAULT_MODEL);
        $this->provider->method('isConfigured')->willReturn(false);
        $this->stubConfiguration();
        $this->initialState->method('provideInitialState')->willReturnCallback(function () {});

        $response = $this->ctrl->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);
    }
}
