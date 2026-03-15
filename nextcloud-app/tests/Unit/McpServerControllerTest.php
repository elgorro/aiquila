<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\McpServerController;
use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use OCA\AIquila\Service\McpClientService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class McpServerControllerTest extends TestCase {
    private McpServerMapper $mapper;
    private McpClientService $mcpClient;
    private McpServerController $controller;

    protected function setUp(): void {
        $request = $this->createMock(IRequest::class);
        $this->mapper = $this->createMock(McpServerMapper::class);
        $this->mcpClient = $this->createMock(McpClientService::class);
        $this->controller = new McpServerController(
            'aiquila',
            $request,
            $this->mapper,
            $this->mcpClient
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
        $server->setAuthToken('super-secret-token-12345');
        $this->mapper->method('findAll')->willReturn([$server]);

        $response = $this->controller->index();
        $data = $response->getData();

        $this->assertNotEquals('super-secret-token-12345', $data[0]['auth_token_masked']);
        $this->assertStringEndsWith('2345', $data[0]['auth_token_masked']);
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
            $idProp->setAccessible(true);
            $idProp->setValue($s, 1);
            return $s;
        });

        $response = $this->controller->create('My MCP', 'http://localhost:3339/mcp', 'none', '');
        $data = $response->getData();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('My MCP', $data['display_name']);
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
}
