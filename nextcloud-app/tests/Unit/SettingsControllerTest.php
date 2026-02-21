<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\SettingsController;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
    private $config;
    private $request;
    private $claude;
    private SettingsController $ctrl;

    protected function setUp(): void {
        $this->config  = $this->createMock(IConfig::class);
        $this->request = $this->createMock(IRequest::class);
        $this->claude  = $this->createMock(ClaudeSDKService::class);
        $this->ctrl    = new SettingsController(
            'aiquila',
            $this->request,
            $this->config,
            'testuser',
            $this->claude
        );
    }

    public function testGetReturnsHasUserKeyFalse(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->claude->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertFalse($data['hasUserKey']);
    }

    public function testGetReturnsHasUserKeyTrue(): void {
        $this->config->method('getUserValue')
            ->willReturnMap([
                ['testuser', 'aiquila', 'api_key', '', 'user-secret-key'],
                ['testuser', 'aiquila', 'model',   '', ''],
            ]);
        $this->claude->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertTrue($data['hasUserKey']);
    }

    public function testGetReturnsUserModelAndAvailableModels(): void {
        $this->config->method('getUserValue')
            ->willReturnMap([
                ['testuser', 'aiquila', 'api_key', '', ''],
                ['testuser', 'aiquila', 'model',   '', ClaudeModels::HAIKU_4_5],
            ]);
        $this->claude->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertEquals(ClaudeModels::HAIKU_4_5, $data['userModel']);
        $this->assertEquals(ClaudeModels::getAllModels(), $data['availableModels']);
    }

    public function testGetUsesLiveModelsWhenAvailable(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->claude->method('listModels')->with('testuser')->willReturn(['claude-test-model']);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertSame(['claude-test-model'], $data['availableModels']);
    }

    public function testGetFallsBackToStaticModelsWhenListFails(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->claude->method('listModels')->with('testuser')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertEquals(ClaudeModels::getAllModels(), $data['availableModels']);
    }

    public function testSaveStoresApiKeyAndModel(): void {
        $this->request->method('getParam')
            ->willReturnMap([
                ['api_key', '', 'my-api-key'],
                ['model',   '', ClaudeModels::OPUS_4_6],
            ]);

        $calls = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(function (string $uid, string $app, string $key, string $val) use (&$calls): void {
                $calls[] = [$uid, $app, $key, $val];
            });

        $response = $this->ctrl->save();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('ok', $response->getData()['status']);
        $this->assertContains(['testuser', 'aiquila', 'api_key', 'my-api-key'], $calls);
        $this->assertContains(['testuser', 'aiquila', 'model', ClaudeModels::OPUS_4_6], $calls);
    }

    public function testSaveWithEmptyModelDeletesUserValue(): void {
        $this->request->method('getParam')
            ->willReturnMap([
                ['api_key', '', 'some-key'],
                ['model',   '', ''],
            ]);

        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('testuser', 'aiquila', 'api_key', 'some-key');

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('testuser', 'aiquila', 'model');

        $response = $this->ctrl->save();
        $this->assertEquals('ok', $response->getData()['status']);
    }

    public function testSaveWithEmptyApiKeyClearsKey(): void {
        $this->request->method('getParam')
            ->willReturnMap([
                ['api_key', '', ''],
                ['model',   '', ClaudeModels::SONNET_4_5],
            ]);

        $calls = [];
        $this->config->method('setUserValue')
            ->willReturnCallback(function (string $uid, string $app, string $key, string $val) use (&$calls): void {
                $calls[] = [$uid, $app, $key, $val];
            });

        $this->ctrl->save();

        $this->assertContains(['testuser', 'aiquila', 'api_key', ''], $calls);
    }
}
