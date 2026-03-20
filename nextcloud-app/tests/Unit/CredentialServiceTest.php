<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\CredentialService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CredentialServiceTest extends TestCase {
    private ICredentialsManager $credManager;
    private ICrypto $crypto;
    private IConfig $config;
    private LoggerInterface $logger;
    private CredentialService $service;

    protected function setUp(): void {
        $this->credManager = $this->createMock(ICredentialsManager::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new CredentialService(
            $this->credManager,
            $this->crypto,
            $this->config,
            $this->logger
        );
    }

    public function testGetApiKeyFromCredentialsManager(): void {
        $this->credManager->method('retrieve')
            ->with('testuser', 'aiquila/api_key')
            ->willReturn('sk-ant-secure-key');

        $result = $this->service->getApiKey('testuser');
        $this->assertEquals('sk-ant-secure-key', $result);
    }

    public function testGetApiKeyMigratesFromIConfig(): void {
        $this->credManager->method('retrieve')
            ->with('testuser', 'aiquila/api_key')
            ->willReturn(null);

        $this->config->method('getUserValue')
            ->with('testuser', 'aiquila', 'api_key', '')
            ->willReturn('sk-ant-plaintext-key');

        // Expect migration: store in credentials manager
        $this->credManager->expects($this->once())
            ->method('store')
            ->with('testuser', 'aiquila/api_key', 'sk-ant-plaintext-key');

        // Expect plaintext deletion
        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('testuser', 'aiquila', 'api_key');

        $result = $this->service->getApiKey('testuser');
        $this->assertEquals('sk-ant-plaintext-key', $result);
    }

    public function testGetApiKeyFallsBackToAppKey(): void {
        // User key not found in either store
        $this->credManager->method('retrieve')
            ->willReturnMap([
                ['testuser', 'aiquila/api_key', null],
                ['', 'aiquila/api_key', 'sk-ant-app-key'],
            ]);

        $this->config->method('getUserValue')
            ->with('testuser', 'aiquila', 'api_key', '')
            ->willReturn('');

        $result = $this->service->getApiKey('testuser');
        $this->assertEquals('sk-ant-app-key', $result);
    }

    public function testGetApiKeyAppScope(): void {
        $this->credManager->method('retrieve')
            ->with('', 'aiquila/api_key')
            ->willReturn('sk-ant-app-key');

        $result = $this->service->getApiKey(null);
        $this->assertEquals('sk-ant-app-key', $result);
    }

    public function testGetApiKeyAppScopeMigratesFromIConfig(): void {
        $this->credManager->method('retrieve')
            ->with('', 'aiquila/api_key')
            ->willReturn(null);

        $this->config->method('getAppValue')
            ->with('aiquila', 'api_key', '')
            ->willReturn('sk-ant-old-app-key');

        $this->credManager->expects($this->once())
            ->method('store')
            ->with('', 'aiquila/api_key', 'sk-ant-old-app-key');

        $this->config->expects($this->once())
            ->method('deleteAppValue')
            ->with('aiquila', 'api_key');

        $result = $this->service->getApiKey(null);
        $this->assertEquals('sk-ant-old-app-key', $result);
    }

    public function testHasApiKeyTrue(): void {
        $this->credManager->method('retrieve')
            ->with('testuser', 'aiquila/api_key')
            ->willReturn('sk-ant-key');

        $this->assertTrue($this->service->hasApiKey('testuser'));
    }

    public function testHasApiKeyFalse(): void {
        $this->credManager->method('retrieve')
            ->with('testuser', 'aiquila/api_key')
            ->willReturn(null);

        $this->config->method('getUserValue')
            ->with('testuser', 'aiquila', 'api_key', '')
            ->willReturn('');

        $this->assertFalse($this->service->hasApiKey('testuser'));
    }

    public function testHasApiKeyChecksPlaintextFallback(): void {
        $this->credManager->method('retrieve')
            ->with('testuser', 'aiquila/api_key')
            ->willReturn(null);

        $this->config->method('getUserValue')
            ->with('testuser', 'aiquila', 'api_key', '')
            ->willReturn('sk-ant-plaintext');

        $this->assertTrue($this->service->hasApiKey('testuser'));
    }

    public function testSetApiKeyStoresAndCleansPlaintext(): void {
        $this->credManager->expects($this->once())
            ->method('store')
            ->with('testuser', 'aiquila/api_key', 'sk-ant-new-key');

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('testuser', 'aiquila', 'api_key');

        $this->service->setApiKey('testuser', 'sk-ant-new-key');
    }

    public function testSetApiKeyAppScope(): void {
        $this->credManager->expects($this->once())
            ->method('store')
            ->with('', 'aiquila/api_key', 'sk-ant-app-key');

        $this->config->expects($this->once())
            ->method('deleteAppValue')
            ->with('aiquila', 'api_key');

        $this->service->setApiKey(null, 'sk-ant-app-key');
    }

    public function testDeleteApiKey(): void {
        $this->credManager->expects($this->once())
            ->method('delete')
            ->with('testuser', 'aiquila/api_key');

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('testuser', 'aiquila', 'api_key');

        $this->service->deleteApiKey('testuser');
    }

    public function testEncryptTokenNullPassthrough(): void {
        $this->assertNull($this->service->encryptToken(null));
    }

    public function testEncryptTokenEmptyPassthrough(): void {
        $this->assertSame('', $this->service->encryptToken(''));
    }

    public function testEncryptToken(): void {
        $this->crypto->method('encrypt')
            ->with('my-secret-token')
            ->willReturn('encrypted-data');

        $result = $this->service->encryptToken('my-secret-token');
        $this->assertEquals('encrypted-data', $result);
    }

    public function testDecryptTokenNullPassthrough(): void {
        $this->assertNull($this->service->decryptToken(null));
    }

    public function testDecryptTokenDecryptsEncrypted(): void {
        $this->crypto->method('decrypt')
            ->with('encrypted-data')
            ->willReturn('my-secret-token');

        $result = $this->service->decryptToken('encrypted-data');
        $this->assertEquals('my-secret-token', $result);
    }

    public function testDecryptTokenPlaintextFallback(): void {
        $this->crypto->method('decrypt')
            ->willThrowException(new \Exception('Invalid ciphertext'));

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->service->decryptToken('plaintext-value');
        $this->assertEquals('plaintext-value', $result);
    }
}
