<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\ProviderSettingsController;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCA\AIquila\Service\Provider\ProviderSettingsService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The personal settings API must never describe — or accept a write for — a
 * provider the admin has blocked for the caller.
 */
class ProviderSettingsControllerTest extends TestCase {
    private $config;
    private $factory;
    private $settings;
    private ProviderSettingsController $ctrl;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturnArgument(2);

        $this->factory = $this->createMock(LLMProviderFactory::class);
        $this->factory->method('getProviderIds')->willReturn(['anthropic', 'mistral']);
        $this->factory->method('isKnownProviderId')->willReturn(true);
        $this->factory->method('getProviderById')->willReturnCallback(
            function (string $id): LLMProviderInterface {
                $provider = $this->createMock(LLMProviderInterface::class);
                $provider->method('getId')->willReturn($id);
                return $provider;
            },
        );

        $this->settings = $this->createMock(ProviderSettingsService::class);
        $this->settings->method('describe')->willReturnCallback(
            static fn (LLMProviderInterface $p): array => ['id' => $p->getId()],
        );

        $this->ctrl = new ProviderSettingsController(
            'aiquila',
            $this->createMock(IRequest::class),
            'testuser',
            $this->config,
            $this->factory,
            $this->settings,
            $this->createMock(CredentialService::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testIndexOmitsDeniedProviders(): void {
        $this->factory->method('getActiveProviderId')->willReturn('mistral');
        $this->factory->method('getProviderIdsForUser')->willReturn(['mistral']);

        $data = $this->ctrl->index()->getData();

        $this->assertSame([['id' => 'mistral']], $data['providers']);
        $this->assertSame('mistral', $data['defaultProvider']);
    }

    public function testIndexReportsAnEmptyStateWhenEveryProviderIsDenied(): void {
        $this->factory->method('getActiveProviderId')
            ->willThrowException(new NoPermittedProviderException('testuser'));
        $this->factory->method('getProviderIdsForUser')->willReturn([]);

        $data = $this->ctrl->index()->getData();

        $this->assertSame([], $data['providers']);
        $this->assertSame('', $data['defaultProvider']);
    }

    public function testUpdateRefusesADeniedProvider(): void {
        $this->factory->method('isAllowedForUser')->willReturn(false);
        // The write must not happen at all, not merely be reported as rejected.
        $this->settings->expects($this->never())->method('writeUser');
        $this->config->expects($this->never())->method('setUserValue');

        $response = $this->ctrl->update('anthropic', ['model' => 'x'], '1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testStatusReportsTheProbeResult(): void {
        $this->factory->method('isAllowedForUser')->willReturn(true);
        $this->settings->method('status')->willReturn([
            'providerId' => 'anthropic',
            'state' => 'degraded',
            'reason' => 'model_missing',
            'message' => 'not offered',
            'model' => 'gone-1',
        ]);

        $response = $this->ctrl->status('anthropic');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('degraded', $response->getData()['state']);
    }

    public function testStatusRefusesADeniedProvider(): void {
        $this->factory->method('isAllowedForUser')->willReturn(false);
        // A blocked provider must not be probed at all — the light would
        // otherwise confirm a key the user may not use.
        $this->settings->expects($this->never())->method('status');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->ctrl->status('anthropic')->getStatus());
    }

    public function testUpdateAcceptsAPermittedProvider(): void {
        $this->factory->method('isAllowedForUser')->willReturn(true);
        $this->settings->method('writeUser')->willReturn([]);

        $response = $this->ctrl->update('anthropic', ['model' => 'x']);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('ok', $response->getData()['status']);
    }
}
