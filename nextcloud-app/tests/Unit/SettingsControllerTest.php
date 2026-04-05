<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\SettingsController;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
    private $config;
    private $request;
    private $claude;
    private $credentials;
    private SettingsController $ctrl;

    protected function setUp(): void {
        $this->config      = $this->createMock(IConfig::class);
        $this->request     = $this->createMock(IRequest::class);
        $this->claude      = $this->createMock(ClaudeSDKService::class);
        $this->credentials = $this->createMock(CredentialService::class);
        $this->ctrl        = new SettingsController(
            'aiquila',
            $this->request,
            $this->config,
            'testuser',
            $this->claude,
            $this->credentials
        );
    }

    public function testGetReturnsHasUserKeyFalse(): void {
        $this->credentials->method('hasApiKey')->with('testuser')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->claude->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertFalse($data['hasUserKey']);
    }

    public function testGetReturnsHasUserKeyTrue(): void {
        $this->credentials->method('hasApiKey')->with('testuser')->willReturn(true);
        $this->config->method('getUserValue')
            ->willReturnMap([
                ['testuser', 'aiquila', 'user_model', '', ''],
                ['testuser', 'aiquila', 'default_system_prompt', '', ''],
                ['testuser', 'aiquila', 'default_verbose', '0', '0'],
            ]);
        $this->claude->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertTrue($data['hasUserKey']);
    }

    public function testGetReturnsUserModelAndAvailableModels(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')
            ->willReturnMap([
                ['testuser', 'aiquila', 'user_model', '', ClaudeModels::HAIKU_4_5],
                ['testuser', 'aiquila', 'default_system_prompt', '', ''],
                ['testuser', 'aiquila', 'default_verbose', '0', '0'],
            ]);
        $this->claude->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertEquals(ClaudeModels::HAIKU_4_5, $data['userModel']);
        $this->assertEquals(ClaudeModels::getAllModels(), $data['availableModels']);
    }

    public function testGetUsesLiveModelsWhenAvailable(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->claude->method('listModels')->with('testuser')->willReturn(['claude-test-model']);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertSame(['claude-test-model'], $data['availableModels']);
    }

    public function testGetFallsBackToStaticModelsWhenListFails(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->claude->method('listModels')->with('testuser')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertEquals(ClaudeModels::getAllModels(), $data['availableModels']);
    }

    public function testSaveStoresApiKeyAndModel(): void {
        $this->credentials->expects($this->once())
            ->method('setApiKey')
            ->with('testuser', 'my-api-key');

        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('testuser', 'aiquila', 'user_model', ClaudeModels::OPUS_4_6);

        $response = $this->ctrl->save('my-api-key', ClaudeModels::OPUS_4_6);

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('ok', $response->getData()['status']);
    }

    public function testSaveWithEmptyModelDeletesUserValue(): void {
        $this->credentials->expects($this->once())
            ->method('setApiKey')
            ->with('testuser', 'some-key');

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('testuser', 'aiquila', 'user_model');

        $response = $this->ctrl->save('some-key', '');
        $this->assertEquals('ok', $response->getData()['status']);
    }

    public function testSaveWithEmptyApiKeyDeletesKey(): void {
        $this->credentials->expects($this->once())
            ->method('deleteApiKey')
            ->with('testuser');

        $this->ctrl->save('', ClaudeModels::SONNET_4_5);
    }
}
