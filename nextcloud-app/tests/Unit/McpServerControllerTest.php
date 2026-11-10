<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\McpServerController;
use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\McpClientService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class McpServerControllerTest extends TestCase {
    private McpServerMapper $mapper;
    private McpClientService $mcpClient;
    private CredentialService $credentials;
    private McpServerController $controller;
    private IRequest $request;

    protected function setUp(): void {
        $this->request = $this->createStub(IRequest::class);
        $this->mapper = $this->createStub(McpServerMapper::class);
        $this->mcpClient = $this->createStub(McpClientService::class);
        $this->credentials = $this->createStub(CredentialService::class);

        // Default: encryptToken returns a fixed encrypted value
        $this->credentials->method('encryptToken')
            ->willReturnCallback(fn(?string $v) => $v !== null && $v !== '' ? 'encrypted:' . $v : $v);

        // Mirror image of the encrypt mock above.
        $this->credentials->method('decryptToken')
            ->willReturnCallback(fn(?string $v) => is_string($v) && str_starts_with($v, 'encrypted:')
                ? substr($v, strlen('encrypted:'))
                : $v);

        $this->controller = new McpServerController(
            'aiquila',
            $this->request,
            $this->mapper,
            $this->mcpClient,
            $this->credentials,
            $this->createStub(\Psr\Log\LoggerInterface::class)
        );
    }

    private function makeServer(int $id = 1): McpServer {
        $server = new McpServer();
        $ref = new \ReflectionClass($server);
        $parent = $ref->getParentClass();
        $idProp = $parent->getProperty('id');
        $idProp->setValue($server, $id);

        $server->setDisplayName('Test MCP');
        $server->setUrl('http://localhost:3339/mcp');
        $server->setAuthType('none');
        $server->setIsEnabled(true);
        $server->setCreatedAt(time());
        $server->setUpdatedAt(time());
        return $server;
    }

    public function testIndexReturnsAllServers(): void {
        $server = $this->makeServer();
        $this->mapper->method('findAll')->willReturn([$server]);

        $response = $this->controller->index();
        $data = $response->getData();

        $this->assertEquals(200, $response->getStatus());
        $this->assertCount(1, $data);
        $this->assertEquals('Test MCP', $data[0]['display_name']);
    }

    public function testIndexMasksAuthToken(): void {
        $server = $this->makeServer();
        $server->setAuthType('bearer');
        $server->setAuthToken('encrypted:super-secret-token-12345');
        $this->mapper->method('findAll')->willReturn([$server]);

        $response = $this->controller->index();
        $data = $response->getData();

        $this->assertEquals('****', $data[0]['auth_token_masked']);
    }

    public function testCreateValidatesRequired(): void {
        $response = $this->controller->create('', '', 'none', '');
        $this->assertEquals(400, $response->getStatus());
    }

    public function testCreateValidatesAuthType(): void {
        $response = $this->controller->create('Test', 'http://example.com', 'invalid', '');
        $this->assertEquals(400, $response->getStatus());
    }

    public function testCreateSuccess(): void {
        $this->mapper->method('insert')->willReturnCallback(function (McpServer $s) {
            $ref = new \ReflectionClass($s);
            $parent = $ref->getParentClass();
            $idProp = $parent->getProperty('id');
            $idProp->setValue($s, 1);
            return $s;
        });

        $response = $this->controller->create('My MCP', 'http://localhost:3339/mcp', 'none', '');
        $data = $response->getData();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('My MCP', $data['display_name']);
    }

    public function testCreateBearerEncryptsToken(): void {
        $this->mapper->method('insert')->willReturnCallback(function (McpServer $s) {
            // Verify the token was encrypted before insert
            $this->assertEquals('encrypted:my-token', $s->getAuthToken());
            $ref = new \ReflectionClass($s);
            $parent = $ref->getParentClass();
            $idProp = $parent->getProperty('id');
            $idProp->setValue($s, 1);
            return $s;
        });

        $response = $this->controller->create('My MCP', 'http://localhost:3339/mcp', 'bearer', 'my-token');
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Regression (#457): a gated registration endpoint needs an RFC 7591 initial
     * access token, which only makes sense for oauth2 servers.
     */
    public function testCreateOauth2EncryptsRegistrationToken(): void {
        $this->mapper->method('insert')->willReturnCallback(function (McpServer $s) {
            $this->assertEquals('encrypted:reg-secret', $s->getOauthRegistrationToken());
            $this->assertNull($s->getAuthToken());
            $ref = new \ReflectionClass($s);
            $idProp = $ref->getParentClass()->getProperty('id');
            $idProp->setValue($s, 1);
            return $s;
        });

        $response = $this->controller->create('My MCP', 'http://localhost:3339/mcp', 'oauth2', '', 'reg-secret');
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('****', $response->getData()['registration_token_masked']);
    }

    public function testCreateBearerIgnoresRegistrationToken(): void {
        $this->mapper->method('insert')->willReturnCallback(function (McpServer $s) {
            $this->assertNull($s->getOauthRegistrationToken());
            $ref = new \ReflectionClass($s);
            $idProp = $ref->getParentClass()->getProperty('id');
            $idProp->setValue($s, 1);
            return $s;
        });

        $response = $this->controller->create('My MCP', 'http://localhost:3339/mcp', 'bearer', 'tok', 'reg-secret');
        $this->assertEquals(200, $response->getStatus());
        $this->assertNull($response->getData()['registration_token_masked']);
    }

    /**
     * A client registered under the old token is worthless once the token
     * changes, so the registration has to be dropped and redone.
     */
    public function testUpdateChangedRegistrationTokenClearsRegistration(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthRegistrationToken('encrypted:old-secret');
        $server->setOauthClientId('client-abc');
        $server->setOauthAccessToken('encrypted:access');
        $server->setOauthRefreshToken('encrypted:refresh');
        $server->setOauthTokenExpiresAt(time() + 3600);
        $this->mapper->method('findById')->willReturn($server);

        $response = $this->controller->update(1, '', '', '', '', 'new-secret');

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('encrypted:new-secret', $server->getOauthRegistrationToken());
        $this->assertNull($server->getOauthClientId());
        $this->assertNull($server->getOauthAccessToken());
        $this->assertNull($server->getOauthRefreshToken());
        $this->assertNull($server->getOauthTokenExpiresAt());
    }

    public function testUpdateWithoutRegistrationTokenKeepsIt(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthRegistrationToken('encrypted:old-secret');
        $server->setOauthClientId('client-abc');
        $this->mapper->method('findById')->willReturn($server);

        $response = $this->controller->update(1, 'Renamed');

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('encrypted:old-secret', $server->getOauthRegistrationToken());
        $this->assertEquals('client-abc', $server->getOauthClientId());
    }

    public function testUpdateEmptyRegistrationTokenClearsIt(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthRegistrationToken('encrypted:old-secret');
        $this->mapper->method('findById')->willReturn($server);

        $response = $this->controller->update(1, '', '', '', '', '');

        $this->assertEquals(200, $response->getStatus());
        $this->assertNull($server->getOauthRegistrationToken());
    }

    public function testDestroyNotFound(): void {
        $this->mapper->method('findById')->willThrowException(new DoesNotExistException(''));

        $response = $this->controller->destroy(999);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testDestroySuccess(): void {
        $server = $this->makeServer();
        $this->mapper->method('findById')->willReturn($server);
        $this->mapper->method('delete')->willReturn($server);

        $response = $this->controller->destroy(1);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('ok', $response->getData()['status']);
    }

    public function testTestConnection(): void {
        $server = $this->makeServer();
        $this->mapper->method('findById')->willReturn($server);
        $this->mcpClient->method('testConnection')->willReturn([
            'success' => true,
            'message' => 'Connected successfully. Found 5 tools.',
            'tool_count' => 5,
        ]);

        $response = $this->controller->test(1);
        $data = $response->getData();

        $this->assertEquals(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertEquals(5, $data['tool_count']);
    }

    public function testTestConnectionNotFound(): void {
        $this->mapper->method('findById')->willThrowException(new DoesNotExistException(''));

        $response = $this->controller->test(999);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testToolsNotFound(): void {
        $this->mapper->method('findById')->willThrowException(new DoesNotExistException(''));

        $response = $this->controller->tools(999);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testUpdateNotFound(): void {
        $this->mapper->method('findById')->willThrowException(new DoesNotExistException(''));

        $response = $this->controller->update(999, 'New Name');
        $this->assertEquals(404, $response->getStatus());
    }

    public function testUpdateSuccess(): void {
        $server = $this->makeServer();
        $this->mapper->method('findById')->willReturn($server);
        $this->mapper->method('update')->willReturn($server);

        $response = $this->controller->update(1, 'Updated Name');
        $data = $response->getData();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('Updated Name', $data['display_name']);
    }

    public function testCreateAcceptsOauth2AuthType(): void {
        $this->mapper->method('insert')->willReturnCallback(function (McpServer $s) {
            $ref = new \ReflectionClass($s);
            $parent = $ref->getParentClass();
            $idProp = $parent->getProperty('id');
            $idProp->setValue($s, 2);
            return $s;
        });

        $response = $this->controller->create('OAuth Server', 'http://localhost:3339/mcp', 'oauth2', '');
        $data = $response->getData();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('oauth2', $data['auth_type']);
        $this->assertNull($data['auth_token_masked']);
    }

    public function testAuthorizeReturnsUrl(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $this->mapper->method('findById')->willReturn($server);

        $this->mcpClient->method('initiateOAuth')->willReturn('http://mcp.local/authorize?state=abc');

        $response = $this->controller->authorize(1);
        $data = $response->getData();

        $this->assertEquals(200, $response->getStatus());
        $this->assertArrayHasKey('authorize_url', $data);
        $this->assertStringContainsString('authorize', $data['authorize_url']);
    }

    public function testAuthorizeNotFound(): void {
        $this->mapper->method('findById')->willThrowException(new DoesNotExistException(''));

        $response = $this->controller->authorize(999);
        $this->assertEquals(404, $response->getStatus());
    }

    public function testOauthCallbackSuccess(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $this->mapper->method('findById')->willReturn($server);

        $this->request->method('getParam')->willReturnMap([
            ['code', '', 'auth-code-123'],
            ['state', '', 'state-abc'],
        ]);

        $response = $this->controller->oauthCallback(1);

        $this->assertEquals(200, $response->getStatus());
        $this->assertStringContainsString('aiquila-oauth-complete', $response->getData());
    }

    public function testCreateStoresPreRegisteredClient(): void {
        $this->mapper->method('insert')->willReturnArgument(0);

        $data = $this->controller->create('Cloud', 'https://cloud.example/mcp', 'oauth2', '', '', ' cid ', 'secret')->getData();

        $this->assertSame('cid', $data['static_client_id']);
        $this->assertSame('****', $data['static_client_secret_masked']);
    }

    public function testCreateBearerIgnoresPreRegisteredClient(): void {
        $this->mapper->method('insert')->willReturnArgument(0);

        $data = $this->controller->create('Cloud', 'https://cloud.example/mcp', 'bearer', 'tok', '', 'cid', 'secret')->getData();

        $this->assertNull($data['static_client_id']);
        $this->assertNull($data['static_client_secret_masked']);
    }

    public function testUpdateChangedPreRegisteredClientDropsActiveClientAndTokens(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthStaticClientId('old');
        $server->setOauthClientId('old');
        $server->setOauthClientIssuer('https://as.example');
        $server->setOauthAccessToken('at');
        $server->setOauthRefreshToken('rt');
        $this->mapper->method('findById')->willReturn($server);

        $this->controller->update(1, staticClientId: 'new');

        $this->assertSame('new', $server->getOauthStaticClientId());
        $this->assertNull($server->getOauthClientId());
        $this->assertNull($server->getOauthClientIssuer());
        $this->assertNull($server->getOauthAccessToken());
        $this->assertNull($server->getOauthRefreshToken());
    }

    public function testUpdateUnchangedPreRegisteredClientKeepsTokens(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthStaticClientId('cid');
        $server->setOauthStaticClientSecret('encrypted:secret');
        $server->setOauthClientId('cid');
        $server->setOauthAccessToken('at');
        $this->mapper->method('findById')->willReturn($server);

        $this->controller->update(1, staticClientId: 'cid', staticClientSecret: 'secret');

        $this->assertSame('cid', $server->getOauthClientId());
        $this->assertSame('at', $server->getOauthAccessToken());
    }

    public function testUpdateClearingPreRegisteredClientClearsSecret(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthStaticClientId('cid');
        $server->setOauthStaticClientSecret('encrypted:secret');
        $this->mapper->method('findById')->willReturn($server);

        $this->controller->update(1, staticClientId: '');

        $this->assertNull($server->getOauthStaticClientId());
        $this->assertNull($server->getOauthStaticClientSecret());
    }

    public function testUpdateUrlDropsCachedAuthorizationServerMetadata(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthMetadata('{"issuer":"https://old.example"}');
        $this->mapper->method('findById')->willReturn($server);

        $this->controller->update(1, url: 'https://new.example/mcp');

        $this->assertNull($server->getOauthMetadata());
    }

    public function testSharedOauthCallbackFindsServerByState(): void {
        $server = $this->makeServer(5);
        $server->setAuthType('oauth2');
        $this->mapper = $this->createMock(McpServerMapper::class);
        $this->mapper->expects($this->once())->method('findByOauthState')->with('state-abc')->willReturn($server);
        $client = $this->createMock(McpClientService::class);
        $client->expects($this->once())->method('completeOAuth')->with($server, 'code-1', 'state-abc', null, '');
        $controller = new McpServerController('aiquila', $this->request, $this->mapper, $client, $this->credentials, $this->createStub(\Psr\Log\LoggerInterface::class));

        $response = $controller->oauthCallbackShared('code-1', 'state-abc');

        $this->assertStringContainsString('serverId:5', $response->getData());
        $this->assertSame([], $response->getThrottleMetadata());
    }

    /** RFC 9207 §2.4: iss and error reach the validation; a rejected response is throttled. */
    public function testOauthCallbackPassesIssAndError(): void {
        $server = $this->makeServer(5);
        $server->setAuthType('oauth2');
        $this->mapper->method('findById')->willReturn($server);
        $client = $this->createMock(McpClientService::class);
        $client->expects($this->once())->method('completeOAuth')
            ->with($server, '', 'state-abc', 'https://attacker.example', 'access_denied')
            ->willThrowException(new \RuntimeException('Authorization response issuer mismatch'));
        $controller = new McpServerController('aiquila', $this->request, $this->mapper, $client, $this->credentials, $this->createStub(\Psr\Log\LoggerInterface::class));

        $response = $controller->oauthCallback(5, '', 'state-abc', 'https://attacker.example', 'access_denied');

        $this->assertStringContainsString('Authentication Failed', $response->getData());
        $this->assertStringNotContainsString('access_denied', $response->getData());
        $this->assertTrue($response->isThrottled());
    }

    public function testSharedOauthCallbackWithUnknownStateIsThrottled(): void {
        $this->mapper->method('findByOauthState')->willThrowException(new DoesNotExistException(''));

        $response = $this->controller->oauthCallbackShared('code', 'guess');

        $this->assertStringContainsString('Authentication Failed', $response->getData());
        $this->assertTrue($response->isThrottled());
    }

    public function testClientMetadataServesTheDocument(): void {
        $this->mcpClient->method('getClientMetadataDocument')->willReturn(['client_id' => 'https://nc.example/apps/aiquila/oauth/mcp-client']);

        $response = $this->controller->clientMetadata();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('https://nc.example/apps/aiquila/oauth/mcp-client', $response->getData()['client_id']);
    }
}
