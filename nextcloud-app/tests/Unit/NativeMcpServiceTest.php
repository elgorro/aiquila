<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\McpClientService;
use OCA\AIquila\Service\NativeMcpService;
use OCA\AIquila\Service\AppSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NativeMcpServiceTest extends TestCase {
    /**
     * @param list<McpServer> $servers
     */
    private function service(string $connectorIds = '', array $servers = [], ?McpClientService $mcpClient = null): NativeMcpService {
        $mapper = $this->createStub(McpServerMapper::class);
        $mapper->method('findAllEnabled')->willReturn($servers);
        $credentials = $this->createStub(CredentialService::class);
        $credentials->method('decryptToken')
            ->willReturnCallback(fn(?string $v) => is_string($v) && str_starts_with($v, 'enc:') ? substr($v, 4) : $v);
        $config = $this->createStub(AppSettings::class);
        $logger = $this->createStub(LoggerInterface::class);
        $config->method('getAppValue')->willReturnCallback(
            fn($app, $key, $default = '') => $key === 'mistral_connector_ids' ? $connectorIds : $default
        );
        return new NativeMcpService(
            $mapper,
            $credentials,
            $config,
            $mcpClient ?? $this->createStub(McpClientService::class),
            $logger,
        );
    }

    private function oauthServer(string $name, ?string $accessToken): McpServer {
        $server = new McpServer();
        $server->setDisplayName($name);
        $server->setUrl('https://' . strtolower($name) . '.example/mcp');
        $server->setAuthType('oauth2');
        $server->setOauthAccessToken($accessToken);
        $server->setOauthRefreshToken('enc:refresh');
        return $server;
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

    public function testBuildServerDefinitionsSendsAValidOAuthTokenAsIs(): void {
        $server = $this->oauthServer('Live', 'enc:valid');
        $mcpClient = $this->createMock(McpClientService::class);
        $mcpClient->method('isTokenExpired')->willReturn(false);
        $mcpClient->expects($this->never())->method('refreshOAuthToken');

        $defs = $this->service(servers: [$server], mcpClient: $mcpClient)->buildServerDefinitions();

        $this->assertSame([[
            'type' => 'url',
            'name' => 'live',
            'url' => 'https://live.example/mcp',
            'authorization_token' => 'valid',
        ]], $defs);
    }

    public function testBuildServerDefinitionsRefreshesAnExpiredOAuthTokenFirst(): void {
        $server = $this->oauthServer('Stale', 'enc:expired');
        $mcpClient = $this->createMock(McpClientService::class);
        $mcpClient->method('isTokenExpired')->willReturn(true);
        $mcpClient->expects($this->once())->method('refreshOAuthToken')
            ->with($server)
            ->willReturnCallback(fn(McpServer $s) => $s->setOauthAccessToken('enc:fresh'));

        $defs = $this->service(servers: [$server], mcpClient: $mcpClient)->buildServerDefinitions();

        $this->assertSame('fresh', $defs[0]['authorization_token']);
    }

    public function testBuildServerDefinitionsLeavesOutAServerWhoseRefreshFails(): void {
        $failing = $this->oauthServer('Failing', 'enc:expired');
        $bearer = new McpServer();
        $bearer->setDisplayName('Bearer');
        $bearer->setUrl('https://bearer.example/mcp');
        $bearer->setAuthType('bearer');
        $bearer->setAuthToken('enc:static');
        $mcpClient = $this->createStub(McpClientService::class);
        $mcpClient->method('isTokenExpired')->willReturn(true);
        $mcpClient->method('refreshOAuthToken')->willThrowException(new \RuntimeException('invalid_grant'));

        $defs = $this->service(servers: [$failing, $bearer], mcpClient: $mcpClient)->buildServerDefinitions();

        $this->assertSame(['bearer'], array_column($defs, 'name'));
        $this->assertSame('static', $defs[0]['authorization_token']);
    }

    public function testBuildServerDefinitionsLeavesOutAnOAuthServerThatIsNotSignedIn(): void {
        $defs = $this->service(servers: [$this->oauthServer('Unsigned', null)])->buildServerDefinitions();

        $this->assertSame([], $defs);
    }
}
