<?php

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Migration\RepairMovePlaintextApiKeys;
use OCP\Config\Exceptions\TypeConflictException;
use OCP\Config\IUserConfig;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Security\ICredentialsManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Settings on IAppConfig/IUserConfig, and the move of plaintext API keys out of them.
 */
class AppSettingsTest extends TestCase {
    public function testValuesAreReadAndWrittenAsStrings(): void {
        $app = $this->createMock(IAppConfig::class);
        $app->expects($this->once())->method('getValueString')->with('aiquila', 'auto_cache', '1')->willReturn('0');
        $app->expects($this->once())->method('setValueString')->with('aiquila', 'auto_cache', '1');
        $app->expects($this->once())->method('deleteKey')->with('aiquila', 'auto_cache');
        $user = $this->createMock(IUserConfig::class);
        $user->expects($this->once())->method('getValueString')->with('alice', 'aiquila', 'user_provider', '')->willReturn('mistral');
        $user->expects($this->once())->method('setValueString')->with('alice', 'aiquila', 'user_provider', 'local');
        $user->expects($this->once())->method('deleteUserConfig')->with('alice', 'aiquila', 'user_provider');

        $settings = new AppSettings($app, $user, $this->createStub(LoggerInterface::class));
        $this->assertSame('0', $settings->getAppValue('aiquila', 'auto_cache', '1'));
        $settings->setAppValue('aiquila', 'auto_cache', '1');
        $settings->deleteAppValue('aiquila', 'auto_cache');
        $this->assertSame('mistral', $settings->getUserValue('alice', 'aiquila', 'user_provider'));
        $settings->setUserValue('alice', 'aiquila', 'user_provider', 'local');
        $settings->deleteUserValue('alice', 'aiquila', 'user_provider');
    }

    public function testAValueStoredUnderAnotherTypeFallsBackToTheDefault(): void {
        $app = $this->createStub(IAppConfig::class);
        $app->method('getValueString')->willThrowException(new AppConfigTypeConflictException('stored as int'));
        $user = $this->createStub(IUserConfig::class);
        $user->method('getValueString')->willThrowException(new TypeConflictException('stored as bool'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');

        $settings = new AppSettings($app, $user, $logger);
        $this->assertSame('30', $settings->getAppValue('aiquila', 'api_timeout', '30'));
        $this->assertSame('1', $settings->getUserValue('alice', 'aiquila', 'task_failure_notifications', '1'));
    }

    private function credentials(AppSettings $settings, ICredentialsManager $manager): CredentialService {
        return new CredentialService($manager, $this->createStub(ICrypto::class), $settings, $this->createStub(LoggerInterface::class));
    }

    public function testPlaintextKeyIsMovedAndDeleted(): void {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getUserValue')->willReturn('sk-plain');
        $settings->expects($this->once())->method('deleteUserValue')->with('alice', 'aiquila', 'api_key');
        $manager = $this->createMock(ICredentialsManager::class);
        $manager->method('retrieve')->willReturn(null);
        $manager->expects($this->once())->method('store')->with('alice', 'aiquila/api_key', 'sk-plain');

        $this->assertSame('sk-plain', $this->credentials($settings, $manager)->migratePlaintextApiKey('alice'));
    }

    public function testAStoredKeyWinsOverThePlaintextOne(): void {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getAppValue')->willReturn('sk-old');
        $settings->expects($this->once())->method('deleteAppValue')->with('aiquila', 'api_key');
        $manager = $this->createMock(ICredentialsManager::class);
        $manager->method('retrieve')->willReturn('sk-current');
        $manager->expects($this->never())->method('store');

        $this->assertSame('', $this->credentials($settings, $manager)->migratePlaintextApiKey(null));
    }

    public function testRepairMovesTheInstanceKeyAndEveryUsersKey(): void {
        $credentials = $this->createMock(CredentialService::class);
        $credentials->expects($this->exactly(3))->method('migratePlaintextApiKey');
        $appConfig = $this->createStub(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(true);
        $userConfig = $this->createStub(IUserConfig::class);
        $userConfig->method('getValuesByUsers')->willReturn(['alice' => 'a', 'bob' => 'b']);
        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info')->with($this->stringContains('Removed 3'));

        (new RepairMovePlaintextApiKeys($credentials, $appConfig, $userConfig, $this->createStub(LoggerInterface::class)))->run($output);
    }
}
