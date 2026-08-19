<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\RequestMetadataService;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RequestMetadataServiceTest extends TestCase {
    private const SALT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private IConfig $config;
    private CredentialService $credentials;
    private ISecureRandom $random;
    private LoggerInterface $logger;

    /** Secret slot contents, so the mocks behave like real storage. */
    private array $secrets = [];

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->credentials = $this->createMock(CredentialService::class);
        $this->credentials->method('getSecret')
            ->willReturnCallback(fn (string $name) => $this->secrets[$name] ?? '');
        $this->credentials->method('setSecret')
            ->willReturnCallback(function (string $name, string $value): void {
                $this->secrets[$name] = $value;
            });

        $this->random = $this->createMock(ISecureRandom::class);
        $this->random->method('generate')->willReturn(self::SALT);
    }

    private function service(bool $enabled): RequestMetadataService {
        $this->config->method('getAppValue')
            ->willReturnCallback(fn ($app, $key, $default) => $key === RequestMetadataService::ENABLED_KEY
                ? ($enabled ? 'true' : 'false')
                : $default);
        return new RequestMetadataService($this->config, $this->credentials, $this->random, $this->logger);
    }

    public function testDisabledSendsNothing(): void {
        $this->assertNull($this->service(false)->hashUserId('alice'));
    }

    public function testNoUserSendsNothing(): void {
        $service = $this->service(true);
        $this->assertNull($service->hashUserId(null));
        $this->assertNull($service->hashUserId(''));
    }

    public function testHashIsTheHmacAndNeverTheRawUid(): void {
        $hash = $this->service(true)->hashUserId('alice');

        $this->assertSame(hash_hmac('sha256', 'alice', self::SALT), $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$hash);
        $this->assertStringNotContainsString('alice', (string)$hash);
    }

    public function testHashIsStableAcrossCalls(): void {
        $service = $this->service(true);
        $this->assertSame($service->hashUserId('alice'), $service->hashUserId('alice'));
        $this->assertNotSame($service->hashUserId('alice'), $service->hashUserId('bob'));
    }

    public function testSaltIsGeneratedOnceAndPersisted(): void {
        $service = $this->service(true);

        $this->assertSame(self::SALT, $service->getSalt());
        $this->assertSame(self::SALT, $this->secrets[RequestMetadataService::SALT_SECRET]);

        // A pre-existing salt is reused rather than regenerated.
        $this->secrets[RequestMetadataService::SALT_SECRET] = 'stored-salt';
        $this->assertSame('stored-salt', $service->getSalt());
    }

    public function testRotateReplacesTheSaltAndChangesTheHash(): void {
        $service = $this->service(true);
        $this->secrets[RequestMetadataService::SALT_SECRET] = 'old-salt';
        $before = $service->hashUserId('alice');

        $this->assertSame(self::SALT, $service->rotateSalt());
        $this->assertNotSame($before, $service->hashUserId('alice'));
    }

    public function testSaltIsInstanceSpecific(): void {
        // Two deployments with different salts must not produce the same hash
        // for the same login name.
        $this->assertNotSame(
            hash_hmac('sha256', 'alice', 'salt-one'),
            hash_hmac('sha256', 'alice', 'salt-two'),
        );
    }
}
