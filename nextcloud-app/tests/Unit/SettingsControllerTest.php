<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\SettingsController;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
    private $config;
    private $request;
    private $factory;
    private $provider;
    private $credentials;
    private $nativeMcp;
    private SettingsController $ctrl;

    protected function setUp(): void {
        $this->config      = $this->createMock(IConfig::class);
        $this->request     = $this->createMock(IRequest::class);
        $this->factory     = $this->createMock(LLMProviderFactory::class);
        $this->provider    = $this->createMock(LLMProviderInterface::class);
        $this->credentials = $this->createMock(CredentialService::class);
        $this->nativeMcp   = $this->createMock(\OCA\AIquila\Service\NativeMcpService::class);
        $this->nativeMcp->method('isEnabledForUser')->willReturn(false);
        $this->nativeMcp->method('probeAll')->willReturn([]);

        // Single-provider (anthropic) world for these tests.
        $this->provider->method('getId')->willReturn('anthropic');
        $this->provider->method('getLabel')->willReturn('Claude (Anthropic)');
        $this->provider->method('isConfigured')->willReturn(false);
        $this->factory->method('getActiveProviderId')->willReturn('anthropic');
        $this->factory->method('getProviderIds')->willReturn(['anthropic']);
        $this->factory->method('getProviderById')->willReturn($this->provider);
        $this->factory->method('getProvider')->willReturn($this->provider);

        $this->ctrl = new SettingsController(
            'aiquila',
            $this->request,
            $this->config,
            'testuser',
            $this->factory,
            $this->credentials,
            $this->nativeMcp
        );
    }

    public function testGetReturnsHasUserKeyFalse(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->provider->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertFalse($data['hasUserKey']);
    }

    public function testGetReturnsHasUserKeyTrue(): void {
        $this->credentials->method('hasApiKey')->willReturn(true);
        $this->config->method('getUserValue')->willReturn('');
        $this->provider->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertTrue($data['hasUserKey']);
    }

    public function testGetReturnsUserModelAndAvailableModels(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')
            ->willReturnMap([
                ['testuser', 'aiquila', 'user_provider', '', ''],
                ['testuser', 'aiquila', 'user_model', '', ClaudeModels::HAIKU_4_5],
                ['testuser', 'aiquila', 'default_system_prompt', '', ''],
                ['testuser', 'aiquila', 'default_verbose', '0', '0'],
                ['testuser', 'aiquila', 'native_mcp_enabled', '', ''],
            ]);
        $this->provider->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertEquals(ClaudeModels::HAIKU_4_5, $data['userModel']);
        $this->assertEquals(ClaudeModels::getAllModels(), $data['availableModels']);
    }

    public function testGetUsesLiveModelsWhenAvailable(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->provider->method('listModels')->willReturn(['claude-test-model']);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertSame(['claude-test-model'], $data['availableModels']);
    }

    public function testGetFallsBackToStaticModelsWhenListFails(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->provider->method('listModels')->willReturn(null);

        $response = $this->ctrl->get();
        $data = $response->getData();

        $this->assertEquals(ClaudeModels::getAllModels(), $data['availableModels']);
    }

    public function testGetExposesProvidersList(): void {
        $this->credentials->method('hasApiKey')->willReturn(false);
        $this->config->method('getUserValue')->willReturn('');
        $this->provider->method('listModels')->willReturn(null);

        $data = $this->ctrl->get()->getData();

        $this->assertSame('anthropic', $data['provider']);
        $this->assertCount(1, $data['providers']);
        $this->assertSame('anthropic', $data['providers'][0]['id']);
        $this->assertSame('Claude (Anthropic)', $data['providers'][0]['label']);
    }

    public function testSaveStoresApiKeyAndModel(): void {
        $this->credentials->expects($this->once())
            ->method('setApiKey')
            ->with('testuser', 'my-api-key', 'anthropic');

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
            ->with('testuser', 'some-key', 'anthropic');

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('testuser', 'aiquila', 'user_model');

        $response = $this->ctrl->save('some-key', '');
        $this->assertEquals('ok', $response->getData()['status']);
    }

    public function testSaveWithEmptyApiKeyDeletesKey(): void {
        $this->credentials->expects($this->once())
            ->method('deleteApiKey')
            ->with('testuser', 'anthropic');

        $this->ctrl->save('', ClaudeModels::SONNET_4_5);
    }

    public function testSaveStoresProviderOverrideAndScopesKey(): void {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('testuser', 'aiquila', 'user_provider', 'mistral');

        $this->credentials->expects($this->once())
            ->method('setApiKey')
            ->with('testuser', 'mistral-key', 'mistral');

        $response = $this->ctrl->save('mistral-key', '', 'mistral');
        $this->assertEquals('ok', $response->getData()['status']);
    }
}
