<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Db\McpServerMapper;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\NativeMcpService;
use OCA\AIquila\Service\AppSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NativeMcpServiceTest extends TestCase {
    private function service(string $connectorIds): NativeMcpService {
        $mapper = $this->createStub(McpServerMapper::class);
        $credentials = $this->createStub(CredentialService::class);
        $config = $this->createStub(AppSettings::class);
        $logger = $this->createStub(LoggerInterface::class);
        $config->method('getAppValue')->willReturnCallback(
            fn($app, $key, $default = '') => $key === 'mistral_connector_ids' ? $connectorIds : $default
        );
        return new NativeMcpService($mapper, $credentials, $config, $logger);
    }

    public function testBuildMistralConnectorToolsParsesCommaAndWhitespace(): void {
        $tools = $this->service("conn_a, conn_b\n  conn_c ")->buildMistralConnectorTools();
        $this->assertSame([
            ['type' => 'connector', 'connector_id' => 'conn_a'],
            ['type' => 'connector', 'connector_id' => 'conn_b'],
            ['type' => 'connector', 'connector_id' => 'conn_c'],
        ], $tools);
    }

    public function testBuildMistralConnectorToolsEmptyWhenUnset(): void {
        $this->assertSame([], $this->service('')->buildMistralConnectorTools());
    }
}
