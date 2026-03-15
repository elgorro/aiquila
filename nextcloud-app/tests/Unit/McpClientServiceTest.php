<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use OCA\AIquila\Service\McpClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Testable subclass that lets us stub HTTP responses.
 */
class TestableMcpClientService extends McpClientService {
    /** @var array Queued responses for jsonRpc calls */
    private array $responses = [];
    private int $callIndex = 0;

    /** Captured calls: [['method' => ..., 'params' => ...], ...] */
    public array $capturedCalls = [];

    public function __construct(McpServerMapper $mapper, LoggerInterface $logger) {
        parent::__construct($mapper, $logger);
    }

    /**
     * Queue a response for the next jsonRpc call.
     */
    public function queueResponse(array $result): void {
        $this->responses[] = ['result' => $result, 'error' => null];
    }

    /**
     * Queue an exception for the next jsonRpc call.
     */
    public function queueError(\Throwable $error): void {
        $this->responses[] = ['result' => null, 'error' => $error];
    }

    /**
     * Override initialize to skip real HTTP but still track calls.
     */
    public function initialize(McpServer $server): void {
        // consume one response for the initialize call
        $this->consumeResponse('initialize', []);
    }

    /**
     * Override listTools to use queued responses.
     */
    public function listTools(McpServer $server): array {
        $this->initialize($server);
        $result = $this->consumeResponse('tools/list', []);
        return $result['tools'] ?? [];
    }

    /**
     * Override callTool to use queued responses.
     */
    public function callTool(McpServer $server, string $name, array $args): array {
        $this->initialize($server);
        try {
            return $this->consumeResponse('tools/call', ['name' => $name, 'arguments' => $args]);
        } catch (\Throwable $e) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => 'Tool execution error: ' . $e->getMessage()],
                ],
                'isError' => true,
            ];
        }
    }

    private function consumeResponse(string $method, array $params): array {
        $this->capturedCalls[] = ['method' => $method, 'params' => $params];

        if ($this->callIndex >= count($this->responses)) {
            return [];
        }

        $entry = $this->responses[$this->callIndex++];
        if ($entry['error'] !== null) {
            throw $entry['error'];
        }
        return $entry['result'];
    }
}

class McpClientServiceTest extends TestCase {
    private McpServerMapper $mapper;
    private LoggerInterface $logger;
    private TestableMcpClientService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(McpServerMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new TestableMcpClientService($this->mapper, $this->logger);
    }

    private function makeServer(int $id = 1, string $name = 'Test Server', string $url = 'http://localhost:3339/mcp'): McpServer {
        $server = new McpServer();
        // Use reflection to set the ID since it's from the parent Entity class
        $ref = new \ReflectionClass($server);
        $parent = $ref->getParentClass();
        $idProp = $parent->getProperty('id');
        $idProp->setValue($server, $id);

        $server->setDisplayName($name);
        $server->setUrl($url);
        $server->setAuthType('none');
        $server->setIsEnabled(true);
        $server->setCreatedAt(time());
        $server->setUpdatedAt(time());
        return $server;
    }

    public function testTestConnectionSuccess(): void {
        $server = $this->makeServer();

        // Queue: initialize + tools/list
        $this->service->queueResponse([]); // initialize
        $this->service->queueResponse([
            'tools' => [
                ['name' => 'list_files', 'description' => 'List files'],
                ['name' => 'read_file', 'description' => 'Read a file'],
            ],
        ]);

        $this->mapper->method('update')->willReturn($server);

        $result = $this->service->testConnection($server);
        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['tool_count']);
        $this->assertStringContainsString('2 tools', $result['message']);
    }

    public function testTestConnectionFailure(): void {
        $server = $this->makeServer();

        // Queue: initialize ok, tools/list throws
        $this->service->queueResponse([]); // initialize
        $this->service->queueError(new \RuntimeException('Connection refused'));

        $this->mapper->method('update')->willReturn($server);

        $result = $this->service->testConnection($server);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection refused', $result['message']);
    }

    public function testGetAllToolsFromMultipleServers(): void {
        $server1 = $this->makeServer(1, 'Server A');
        $server2 = $this->makeServer(2, 'Server B');

        $this->mapper->method('findAllEnabled')->willReturn([$server1, $server2]);
        $this->mapper->method('update')->willReturnArgument(0);

        // Server A: initialize + tools/list
        $this->service->queueResponse([]); // init A
        $this->service->queueResponse([
            'tools' => [
                ['name' => 'list_files', 'description' => 'List files', 'inputSchema' => ['type' => 'object']],
            ],
        ]);
        // Server B: initialize + tools/list
        $this->service->queueResponse([]); // init B
        $this->service->queueResponse([
            'tools' => [
                ['name' => 'create_note', 'description' => 'Create a note', 'inputSchema' => ['type' => 'object']],
            ],
        ]);

        $result = $this->service->getAllTools();

        $this->assertCount(2, $result['tools']);
        $this->assertEquals('list_files', $result['tools'][0]['name']);
        $this->assertEquals('create_note', $result['tools'][1]['name']);

        // Verify mapping
        $this->assertEquals(1, $result['mapping']['list_files']['serverId']);
        $this->assertEquals(2, $result['mapping']['create_note']['serverId']);
    }

    public function testGetAllToolsHandlesServerFailure(): void {
        $server1 = $this->makeServer(1, 'Good Server');
        $server2 = $this->makeServer(2, 'Bad Server');

        $this->mapper->method('findAllEnabled')->willReturn([$server1, $server2]);
        $this->mapper->method('update')->willReturnArgument(0);

        // Server 1 succeeds
        $this->service->queueResponse([]);
        $this->service->queueResponse([
            'tools' => [['name' => 'list_files', 'description' => 'List files']],
        ]);
        // Server 2 fails on initialize
        $this->service->queueError(new \RuntimeException('Unreachable'));

        $result = $this->service->getAllTools();

        // Only tools from server 1
        $this->assertCount(1, $result['tools']);
        $this->assertEquals('list_files', $result['tools'][0]['name']);
    }

    public function testExecuteToolResolvesMapping(): void {
        $server = $this->makeServer(1, 'Test');

        $this->mapper->method('findById')->with(1)->willReturn($server);

        // Queue: initialize + tools/call
        $this->service->queueResponse([]);
        $this->service->queueResponse([
            'content' => [['type' => 'text', 'text' => 'file1.txt\nfile2.txt']],
        ]);

        $mapping = [
            'list_files' => ['serverId' => 1, 'originalName' => 'list_files'],
        ];

        $result = $this->service->executeTool('list_files', ['path' => '/'], $mapping);
        $this->assertArrayHasKey('content', $result);
        $this->assertFalse($result['isError'] ?? false);
    }

    public function testExecuteToolUnknownTool(): void {
        $result = $this->service->executeTool('unknown_tool', [], []);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Unknown tool', $result['content'][0]['text']);
    }

    public function testGetAllToolsReturnsEmptyWhenNoServers(): void {
        $this->mapper->method('findAllEnabled')->willReturn([]);

        $result = $this->service->getAllTools();

        $this->assertEmpty($result['tools']);
        $this->assertEmpty($result['mapping']);
    }
}
