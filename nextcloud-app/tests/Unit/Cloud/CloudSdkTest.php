<?php

namespace OCA\AIquila\Tests\Unit\Cloud;

use OCA\AIquila\Service\Provider\Cloud\CloudSdk;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CloudSdkTest extends TestCase {
    private function sdk(string $path, LoggerInterface $logger): CloudSdk {
        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())->method('getSystemValueString')
            ->with(CloudSdk::SYSTEM_CONFIG_KEY, '')
            ->willReturn($path);
        return new class($config, $logger) extends CloudSdk {
            // Never present, whatever the test machine has installed.
            protected function detect(string $package): bool {
                return false;
            }
        };
    }

    public function testOnlyAnAbsoluteAutoloadPhpIsLoaded(): void {
        foreach (['vendor/autoload.php', '/etc/passwd', '/nonexistent/vendor/autoload.php'] as $path) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($this->once())->method('warning');
            $this->assertFalse($this->sdk($path, $logger)->isAvailable(CloudSdk::AWS), $path);
        }
    }

    public function testNoPathMeansNothingToLoad(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $sdk = $this->sdk('', $logger);

        $this->assertFalse($sdk->isAvailable(CloudSdk::GOOGLE));
        $this->expectExceptionMessage(CloudSdk::SYSTEM_CONFIG_KEY);
        $sdk->require(CloudSdk::GOOGLE, 'Vertex');
    }
}
