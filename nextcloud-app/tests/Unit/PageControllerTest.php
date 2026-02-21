<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\PageController;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class PageControllerTest extends TestCase {
    private $initialState;
    private $claude;
    private $request;
    private PageController $ctrl;

    protected function setUp(): void {
        $this->initialState = $this->createMock(IInitialState::class);
        $this->claude       = $this->createMock(ClaudeSDKService::class);
        $this->request      = $this->createMock(IRequest::class);
        $this->ctrl         = new PageController(
            'aiquila',
            $this->request,
            $this->initialState,
            $this->claude,
            'testuser'
        );
    }

    public function testIndexProvidesInitialStateWithUserEffectiveModel(): void {
        $this->claude->expects($this->atLeastOnce())
            ->method('getModel')
            ->with('testuser')
            ->willReturn(ClaudeModels::HAIKU_4_5);

        $this->claude->method('getConfiguration')
            ->willReturn([
                'api_key'    => 'some-key',
                'model'      => ClaudeModels::HAIKU_4_5,
                'max_tokens' => 4096,
                'timeout'    => 30,
            ]);

        $capturedKey  = null;
        $capturedData = null;
        $this->initialState->expects($this->once())
            ->method('provideInitialState')
            ->willReturnCallback(function (string $key, mixed $data) use (&$capturedKey, &$capturedData): void {
                $capturedKey  = $key;
                $capturedData = $data;
            });

        $this->ctrl->index();

        $this->assertEquals('config', $capturedKey);
        $this->assertArrayHasKey('model', $capturedData);
        $this->assertEquals(ClaudeModels::HAIKU_4_5, $capturedData['model']);
    }

    public function testIndexProvidesHasApiKeyTrue(): void {
        $this->claude->method('getModel')->willReturn(ClaudeModels::DEFAULT_MODEL);
        $this->claude->method('getConfiguration')
            ->willReturn([
                'api_key'    => 'my-secret-key',
                'model'      => ClaudeModels::DEFAULT_MODEL,
                'max_tokens' => 4096,
                'timeout'    => 30,
            ]);

        $capturedData = null;
        $this->initialState->method('provideInitialState')
            ->willReturnCallback(function (string $key, mixed $data) use (&$capturedData): void {
                $capturedData = $data;
            });

        $this->ctrl->index();

        $this->assertTrue($capturedData['has_api_key']);
    }

    public function testIndexProvidesHasApiKeyFalse(): void {
        $this->claude->method('getModel')->willReturn(ClaudeModels::DEFAULT_MODEL);
        $this->claude->method('getConfiguration')
            ->willReturn([
                'api_key'    => '',
                'model'      => ClaudeModels::DEFAULT_MODEL,
                'max_tokens' => 4096,
                'timeout'    => 30,
            ]);

        $capturedData = null;
        $this->initialState->method('provideInitialState')
            ->willReturnCallback(function (string $key, mixed $data) use (&$capturedData): void {
                $capturedData = $data;
            });

        $this->ctrl->index();

        $this->assertFalse($capturedData['has_api_key']);
    }

    public function testIndexReturnsTemplateResponse(): void {
        $this->claude->method('getModel')->willReturn(ClaudeModels::DEFAULT_MODEL);
        $this->claude->method('getConfiguration')
            ->willReturn([
                'api_key'    => '',
                'model'      => ClaudeModels::DEFAULT_MODEL,
                'max_tokens' => 4096,
                'timeout'    => 30,
            ]);
        $this->initialState->method('provideInitialState')->willReturnCallback(function() {});

        $response = $this->ctrl->index();

        $this->assertInstanceOf(TemplateResponse::class, $response);
    }
}
