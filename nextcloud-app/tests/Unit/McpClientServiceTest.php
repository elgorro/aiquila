<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\McpClientService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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

    public function __construct(McpServerMapper $mapper, LoggerInterface $logger, CredentialService $credentials, IURLGenerator $urlGenerator) {
        parent::__construct($mapper, $logger, $credentials, $urlGenerator);
    }

    /** Expose the protected JSON-RPC body builder for assertions. */
    public function exposeRequestBody(string $method, array $params, int $id): array {
        return $this->buildRequestBody($method, $params, $id);
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
    private CredentialService $credentials;
    private IURLGenerator $urlGenerator;
    private TestableMcpClientService $service;

    /**
     * Canned responses keyed by "METHOD url", answered without being captured.
     *
     * @var array<string, array{0: int, 1: mixed, 2?: array<string, string>}>
     */
    private array $routes = [];

    /** @var list<string> Every request the mock client saw, as "METHOD url". */
    private array $discoveryLog = [];

    /** Base URL the stub URL generator builds Nextcloud links on. */
    private string $ncBase = 'https://nc.example/apps/aiquila';

    protected function setUp(): void {
        $this->mapper = $this->createStub(McpServerMapper::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->credentials = $this->createStub(CredentialService::class);

        // Default: encrypt/decrypt pass through for test simplicity
        $this->credentials->method('encryptToken')
            ->willReturnCallback(fn(?string $v) => $v);
        $this->credentials->method('decryptToken')
            ->willReturnCallback(fn(?string $v) => $v);

        $this->urlGenerator = $this->createStub(IURLGenerator::class);
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
            fn(string $route, array $params = []) => match ($route) {
                McpClientService::CALLBACK_ROUTE => $this->ncBase . '/api/admin/mcp-servers/' . $params['id'] . '/oauth/callback',
                McpClientService::SHARED_CALLBACK_ROUTE => $this->ncBase . '/api/admin/mcp-servers/oauth/callback',
                McpClientService::CLIENT_METADATA_ROUTE => $this->ncBase . '/oauth/mcp-client',
            }
        );
        $this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            fn(string $url) => 'https://nc.example' . $url
        );

        $this->buildService();
    }

    private function buildService(): void {
        $this->service = new TestableMcpClientService($this->mapper, $this->logger, $this->credentials, $this->urlGenerator);
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

    /**
     * Regression: an empty `params` used to be sent as `[]`, which json_encodes
     * to a JSON array rather than an object. The MCP SDK rejects that with
     * -32700 "Invalid JSON-RPC message", so tools/list always failed and no MCP
     * tools ever reached the chat.
     */
    public function testParameterlessRequestOmitsParams(): void {
        $body = $this->service->exposeRequestBody('tools/list', [], 42);

        $this->assertArrayNotHasKey('params', $body);
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertSame('tools/list', $body['method']);
        $this->assertSame(42, $body['id']);
        $this->assertStringNotContainsString('"params":[]', json_encode($body));
    }

    public function testRequestWithParamsEncodesThemAsAnObject(): void {
        $body = $this->service->exposeRequestBody('tools/call', ['name' => 'list_files', 'arguments' => ['path' => '/']], 7);

        $this->assertSame(['name' => 'list_files', 'arguments' => ['path' => '/']], $body['params']);
        $this->assertStringContainsString('"params":{"name":"list_files"', json_encode($body));
    }

    /**
     * Regression: MCP clients must acknowledge the initialize response before
     * sending requests such as tools/list. The notification must not include a
     * JSON-RPC request ID and must reuse any session ID returned by initialize.
     */
    public function testListToolsCompletesInitializationHandshakeBeforeListingTools(): void {
        $server = $this->makeServer(1, 'Test Server', 'https://mcp.example/mcp');
        $service = new McpClientService($this->mapper, $this->logger, $this->credentials, $this->urlGenerator);

        $captured = [];
        $responses = [
            new MockResponse(
                json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['protocolVersion' => '2025-03-26'],
                ]),
                [
                    'http_code' => 200,
                    'response_headers' => [
                        'content-type' => ['application/json'],
                        'mcp-session-id' => ['session-123'],
                    ],
                ]
            ),
            new MockResponse('', ['http_code' => 202]),
            new MockResponse(
                json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'result' => [
                        'tools' => [
                            ['name' => 'list_files', 'description' => 'List files'],
                        ],
                    ],
                ]),
                [
                    'http_code' => 200,
                    'response_headers' => ['content-type' => ['application/json']],
                ]
            ),
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, &$responses): MockResponse {
            $captured[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];
            return array_shift($responses);
        });

        $ref = new \ReflectionProperty(McpClientService::class, 'httpClient');
        $ref->setValue($service, $client);

        $tools = $service->listTools($server);

        $this->assertCount(1, $tools);
        $this->assertCount(3, $captured);

        $initializeBody = $this->requestBody($captured[0]['options']);
        $this->assertSame('initialize', $initializeBody['method']);
        $this->assertArrayHasKey('id', $initializeBody);

        $initializedBody = $this->requestBody($captured[1]['options']);
        $this->assertSame('notifications/initialized', $initializedBody['method']);
        $this->assertArrayNotHasKey('id', $initializedBody);
        $this->assertSame('session-123', $this->headerValue($captured[1]['options'], 'Mcp-Session-Id'));

        $toolsBody = $this->requestBody($captured[2]['options']);
        $this->assertSame('tools/list', $toolsBody['method']);
        $this->assertArrayHasKey('id', $toolsBody);
        $this->assertSame('session-123', $this->headerValue($captured[2]['options'], 'Mcp-Session-Id'));
    }

    /**
     * Regression: Streamable HTTP servers may answer initialize with an SSE
     * stream. The Mcp-Session-Id on that response must still be captured and
     * sent on every subsequent request.
     */
    public function testSessionIdFromSseInitializeResponseIsReused(): void {
        $server = $this->makeServer(1, 'Test Server', 'https://mcp.example/mcp');
        $service = new McpClientService($this->mapper, $this->logger, $this->credentials, $this->urlGenerator);

        $captured = [];
        $responses = [
            new MockResponse(
                "event: message\ndata: " . json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => ['protocolVersion' => '2025-03-26'],
                ]) . "\n\n",
                [
                    'http_code' => 200,
                    'response_headers' => [
                        'content-type' => ['text/event-stream'],
                        'mcp-session-id' => ['sse-session-456'],
                    ],
                ]
            ),
            new MockResponse('', ['http_code' => 202]),
            new MockResponse(
                "event: message\ndata: " . json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'result' => ['tools' => [['name' => 'list_files']]],
                ]) . "\n\n",
                [
                    'http_code' => 200,
                    'response_headers' => ['content-type' => ['text/event-stream']],
                ]
            ),
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, &$responses): MockResponse {
            $captured[] = $options;
            return array_shift($responses);
        });

        $ref = new \ReflectionProperty(McpClientService::class, 'httpClient');
        $ref->setValue($service, $client);

        $tools = $service->listTools($server);

        $this->assertCount(1, $tools);
        $this->assertCount(3, $captured);
        $this->assertNull($this->headerValue($captured[0], 'Mcp-Session-Id'));
        $this->assertSame('notifications/initialized', $this->requestBody($captured[1])['method']);
        $this->assertSame('sse-session-456', $this->headerValue($captured[1], 'Mcp-Session-Id'));
        $this->assertSame('tools/list', $this->requestBody($captured[2])['method']);
        $this->assertSame('sse-session-456', $this->headerValue($captured[2], 'Mcp-Session-Id'));
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
        $this->mapper = $this->createMock(McpServerMapper::class);
        $this->buildService();

        $server = $this->makeServer(1, 'Test');

        $this->mapper->expects($this->once())->method('findById')->with(1)->willReturn($server);

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

    public function testInitiateOAuthGeneratesPkceAndReturnsUrl(): void {
        $server = $this->oauthServer([]);
        $server->setOauthClientId('existing-client-id');

        $url = $this->service->initiateOAuth($server);

        $this->assertStringStartsWith('https://cloud.example/authorize?', $url);
        $this->assertStringContainsString('client_id=existing-client-id', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertNotNull($server->getOauthCodeVerifier());
        $this->assertNotNull($server->getOauthState());
    }

    public function testCompleteOAuthStoresTokens(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthClientId('test-client');
        $server->setOauthCodeVerifier('test-verifier');
        $server->setOauthState('test-state');
        $server->setOauthMetadata(json_encode([
            'token_endpoint' => 'http://localhost:3339/token',
        ]));

        $this->mapper->method('update')->willReturnArgument(0);

        // Verify state mismatch throws
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('state mismatch');
        $this->service->completeOAuth($server, 'auth-code', 'wrong-state');
    }

    public function testCompleteOAuthRejectsInvalidState(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthState('correct-state');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('state mismatch');
        $this->service->completeOAuth($server, 'code', 'wrong-state');
    }

    public function testIsTokenExpired(): void {
        $server = $this->makeServer();

        // No expiry set — should be expired
        $this->assertTrue($this->service->isTokenExpired($server));

        // Set expiry in the past
        $server->setOauthTokenExpiresAt(time() - 100);
        $this->assertTrue($this->service->isTokenExpired($server));

        // Set expiry within 60s buffer
        $server->setOauthTokenExpiresAt(time() + 30);
        $this->assertTrue($this->service->isTokenExpired($server));

        // Set expiry well in the future
        $server->setOauthTokenExpiresAt(time() + 3600);
        $this->assertFalse($this->service->isTokenExpired($server));
    }

    /**
     * Regression (#457): MCP servers may gate RFC 7591 dynamic client
     * registration behind an initial access token, answering 401 without an
     * `Authorization` header. The registration token configured on the server
     * entry has to be sent, or "Authorize" never reaches the consent screen.
     */
    public function testRegisterOAuthClientSendsRegistrationTokenWhenSet(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthMetadata(json_encode(['registration_endpoint' => 'https://mcp.example/register']));
        $server->setOauthRegistrationToken('reg-secret');

        $captured = [];
        $this->injectHttpClient($captured);

        $clientId = $this->service->registerOAuthClient($server, 'https://nc.example/callback');

        $this->assertSame('generated-client', $clientId);
        $this->assertSame(['Authorization' => 'Bearer reg-secret'], $captured[0]['headers'] ?? null);
    }

    public function testRegisterOAuthClientOmitsHeaderWhenNoRegistrationToken(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthMetadata(json_encode(['registration_endpoint' => 'https://mcp.example/register']));

        $captured = [];
        $this->injectHttpClient($captured);

        $this->service->registerOAuthClient($server, 'https://nc.example/callback');

        $this->assertSame([], $captured[0]['headers']);
    }

    public function testRegisterOAuthClientExplainsGatedRegistration(): void {
        $server = $this->makeServer();
        $server->setAuthType('oauth2');
        $server->setOauthMetadata(json_encode(['registration_endpoint' => 'https://mcp.example/register']));

        $captured = [];
        $this->injectHttpClient($captured, 401);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a registration token/');
        $this->service->registerOAuthClient($server, 'https://nc.example/callback');
    }

    /**
     * Swap the service's own HttpClient for a mock and record the request
     * options each call was made with.
     *
     * @param array<int, array<string, mixed>> $captured
     */
    private function injectHttpClient(array &$captured, int $status = 200): void {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $status) {
            $captured[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $this->authHeader($options),
            ];
            return new MockResponse(
                json_encode(['client_id' => 'generated-client']),
                ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]
            );
        });

        $ref = new \ReflectionProperty(McpClientService::class, 'httpClient');
        $ref->setValue($this->service, $client);
    }

    /**
     * Decode a JSON request body from the MockHttpClient options.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestBody(array $options): array {
        if (isset($options['json']) && is_array($options['json'])) {
            return $options['json'];
        }

        $body = $options['body'] ?? null;
        if (is_array($body)) {
            return $body;
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /**
     * Read a request header from either associative or "Name: value" form.
     *
     * @param array<string, mixed> $options
     */
    private function headerValue(array $options, string $name): ?string {
        foreach ($options['headers'] ?? [] as $key => $value) {
            if (!is_int($key) && strcasecmp((string)$key, $name) === 0) {
                return is_array($value) ? ($value[0] ?? null) : (string)$value;
            }

            $line = is_int($key) ? (string)$value : '';
            $prefix = $name . ':';
            if ($line !== '' && strncasecmp($line, $prefix, strlen($prefix)) === 0) {
                return trim(substr($line, strlen($prefix)));
            }
        }

        return null;
    }

    /**
     * MockHttpClient normalises headers into `$options['headers']` as a list of
     * "Name: value" strings, so pull the Authorization one back out in the shape
     * the caller passed it.
     *
     * @return array<string, string>
     */
    private function authHeader(array $options): array {
        foreach ($options['headers'] ?? [] as $key => $value) {
            $line = is_int($key) ? $value : $key . ': ' . (is_array($value) ? ($value[0] ?? '') : $value);
            if (stripos($line, 'authorization:') === 0) {
                return ['Authorization' => trim(substr($line, strlen('authorization:')))];
            }
        }
        return [];
    }

    // --- Client registration without DCR (MCP 2026-07-28 "Client Registration") ---

    /**
     * Route every request through $handler and record method, URL, form/JSON
     * body and Authorization header.
     *
     * @param array<int, array<string, mixed>> $captured
     * @param callable(string, string): array<string, mixed> $handler (method, url) → JSON body
     */
    private function injectResponder(array &$captured, callable $handler): void {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $handler) {
            $this->discoveryLog[] = $method . ' ' . $url;
            if (isset($this->routes[$method . ' ' . $url])) {
                return $this->routeResponse($this->routes[$method . ' ' . $url]);
            }
            if ($method === 'GET' && str_contains($url, '/.well-known/')) {
                return new MockResponse('not found', ['http_code' => 404]);
            }
            $body = $options['body'] ?? '';
            $parsed = [];
            if (is_string($body) && $body !== '') {
                $json = json_decode($body, true);
                if (is_array($json)) {
                    $parsed = $json;
                } else {
                    parse_str($body, $parsed);
                }
            }
            $captured[] = [
                'method' => $method,
                'url' => $url,
                'body' => $parsed,
                'authorization' => $this->headerValue($options, 'Authorization'),
            ];
            return new MockResponse(
                (string)json_encode($handler($method, $url)),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]
            );
        });

        $ref = new \ReflectionProperty(McpClientService::class, 'httpClient');
        $ref->setValue($this->service, $client);
    }

    /**
     * An OAuth server entry whose authorization server metadata is both cached
     * and served by discovery: the probe gets a bare 401, the path-based
     * protected resource metadata names the issuer, and the issuer's RFC 8414
     * well-known URI returns the metadata.
     *
     * @param array<string, mixed> $metadata
     */
    private function oauthServer(array $metadata): McpServer {
        $server = $this->makeServer(7, 'Cloud', 'https://cloud.example/apps/mcp/mcp');
        $server->setAuthType('oauth2');
        $metadata += [
            'issuer' => 'https://cloud.example',
            'authorization_endpoint' => 'https://cloud.example/authorize',
            'token_endpoint' => 'https://cloud.example/token',
        ];
        $server->setOauthMetadata((string)json_encode($metadata));
        $this->mapper->method('update')->willReturnArgument(0);

        $issuer = (string)$metadata['issuer'];
        $this->routes = [
            'POST https://cloud.example/apps/mcp/mcp' => [401, ''],
            'GET https://cloud.example/.well-known/oauth-protected-resource/apps/mcp/mcp' => [200, [
                'resource' => 'https://cloud.example/apps/mcp/mcp',
                'authorization_servers' => [$issuer],
            ]],
            'GET ' . rtrim($issuer, '/') . '/.well-known/oauth-authorization-server' => [200, $metadata],
        ];
        $unused = [];
        $this->injectResponder($unused, fn() => []);
        return $server;
    }

    /** @param array{0: int, 1: mixed, 2?: array<string, string>} $route */
    private function routeResponse(array $route): MockResponse {
        $headers = ['content-type' => 'application/json'];
        foreach ($route[2] ?? [] as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        return new MockResponse(
            is_string($route[1]) ? $route[1] : (string)json_encode($route[1]),
            ['http_code' => $route[0], 'response_headers' => $headers]
        );
    }

    /** @return array<string, string> */
    private function authorizeQuery(string $url): array {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return $query;
    }

    public function testPreRegisteredClientComesFirst(): void {
        $server = $this->oauthServer([
            'client_id_metadata_document_supported' => true,
            'registration_endpoint' => 'https://cloud.example/register',
        ]);
        $server->setOauthStaticClientId('admin-registered');

        $captured = [];
        $this->injectResponder($captured, fn() => ['client_id' => 'should-not-register']);

        $query = $this->authorizeQuery($this->service->initiateOAuth($server));

        $this->assertSame([], $captured, 'no registration request');
        $this->assertSame('admin-registered', $query['client_id']);
        $this->assertSame($this->ncBase . '/api/admin/mcp-servers/oauth/callback', $query['redirect_uri']);
        $this->assertSame('admin-registered', $server->getOauthClientId());
        $this->assertSame('https://cloud.example', $server->getOauthClientIssuer());
    }

    /**
     * "Authorization Server Binding": pre-registered credentials from another
     * issuer SHOULD be surfaced as an error, not used.
     */
    public function testPreRegisteredClientFromAnotherIssuerIsRefused(): void {
        $server = $this->oauthServer(['issuer' => 'https://other-as.example']);
        $server->setOauthStaticClientId('admin-registered');
        $server->setOauthClientId('admin-registered');
        $server->setOauthClientIssuer('https://cloud.example');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/registered with https:\/\/cloud\.example.*https:\/\/other-as\.example/');
        $this->service->initiateOAuth($server);
    }

    public function testClientMetadataDocumentIsPreferredOverRegistration(): void {
        $server = $this->oauthServer([
            'client_id_metadata_document_supported' => true,
            'token_endpoint_auth_methods_supported' => ['none'],
            'registration_endpoint' => 'https://cloud.example/register',
        ]);

        $captured = [];
        $this->injectResponder($captured, fn() => ['client_id' => 'should-not-register']);

        $query = $this->authorizeQuery($this->service->initiateOAuth($server));

        $this->assertSame([], $captured);
        $this->assertSame($this->ncBase . '/oauth/mcp-client', $query['client_id']);
        $this->assertSame($this->ncBase . '/api/admin/mcp-servers/oauth/callback', $query['redirect_uri']);
        $this->assertNull($server->getOauthClientIssuer(), 'a metadata document URL is portable');
    }

    /** draft-ietf-oauth-client-id-metadata-document §3: the client_id must be https. */
    public function testClientMetadataDocumentNeedsHttps(): void {
        $this->ncBase = 'http://nc.local/apps/aiquila';
        $server = $this->oauthServer([
            'client_id_metadata_document_supported' => true,
            'registration_endpoint' => 'https://cloud.example/register',
        ]);

        $captured = [];
        $this->injectResponder($captured, fn() => ['client_id' => 'registered']);

        $query = $this->authorizeQuery($this->service->initiateOAuth($server));

        $this->assertSame('https://cloud.example/register', $captured[0]['url']);
        $this->assertSame('registered', $query['client_id']);
        $this->assertSame('http://nc.local/apps/aiquila/api/admin/mcp-servers/7/oauth/callback', $query['redirect_uri']);
    }

    public function testClientMetadataDocumentNeedsPublicClientSupport(): void {
        $server = $this->oauthServer([
            'client_id_metadata_document_supported' => true,
            'token_endpoint_auth_methods_supported' => ['private_key_jwt'],
            'registration_endpoint' => 'https://cloud.example/register',
        ]);

        $captured = [];
        $this->injectResponder($captured, fn() => ['client_id' => 'registered']);

        $this->service->initiateOAuth($server);

        $this->assertSame('registered', $server->getOauthClientId());
    }

    public function testRegistrationSendsApplicationType(): void {
        $server = $this->oauthServer(['registration_endpoint' => 'https://cloud.example/register']);
        $captured = [];
        $this->injectResponder($captured, fn() => ['client_id' => 'registered']);

        $this->service->registerOAuthClient($server, 'https://nc.example/callback');
        $this->service->registerOAuthClient($server, 'http://localhost:8080/callback');

        $this->assertSame('web', $captured[0]['body']['application_type']);
        $this->assertSame('native', $captured[1]['body']['application_type']);
        $this->assertSame('https://cloud.example', $server->getOauthClientIssuer());
    }

    public function testNoRegistrationPathExplainsWhatToConfigure(): void {
        $server = $this->oauthServer([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/neither Client ID Metadata Documents nor dynamic client registration.*client ID/');
        $this->service->initiateOAuth($server);
    }

    /** "Authorization Server Binding": MUST NOT reuse a registration from another issuer. */
    public function testRegisteredClientIsNotReusedWithAnotherIssuer(): void {
        $server = $this->oauthServer([
            'issuer' => 'https://new-as.example',
            'registration_endpoint' => 'https://new-as.example/register',
        ]);
        $server->setOauthClientId('old-client');
        $server->setOauthClientIssuer('https://cloud.example');

        $captured = [];
        $this->injectResponder($captured, fn() => ['client_id' => 'new-client']);

        $query = $this->authorizeQuery($this->service->initiateOAuth($server));

        $this->assertCount(1, $captured);
        $this->assertSame('new-client', $query['client_id']);
        $this->assertSame('https://new-as.example', $server->getOauthClientIssuer());
    }

    public function testRegisteredClientIsReusedWithTheSameIssuer(): void {
        $server = $this->oauthServer(['registration_endpoint' => 'https://cloud.example/register']);
        $server->setOauthClientId('old-client');

        $captured = [];
        $this->injectResponder($captured, fn() => ['client_id' => 'new-client']);

        $query = $this->authorizeQuery($this->service->initiateOAuth($server));

        $this->assertSame([], $captured);
        $this->assertSame('old-client', $query['client_id']);
        // A row registered before the binding was recorded adopts the issuer.
        $this->assertSame('https://cloud.example', $server->getOauthClientIssuer());
    }

    /** A client left from a metadata document is not used as a registration. */
    public function testFormerMetadataDocumentClientIsReplacedByRegistration(): void {
        $server = $this->oauthServer(['registration_endpoint' => 'https://cloud.example/register']);
        $server->setOauthClientId($this->ncBase . '/oauth/mcp-client');

        $captured = [];
        $this->injectResponder($captured, fn() => ['client_id' => 'registered']);

        $this->service->initiateOAuth($server);

        $this->assertSame('registered', $server->getOauthClientId());
    }

    /** RFC 6749 §2.3.1: HTTP Basic with form-urlencoded parts, and no client_id in the body. */
    public function testConfidentialClientUsesBasicAuthAtTokenEndpoint(): void {
        $server = $this->oauthServer([]);
        $server->setOauthStaticClientId('my client');
        $server->setOauthStaticClientSecret('s3cr:et');
        $server->setOauthClientId('my client');
        $server->setOauthState('st');
        $server->setOauthCodeVerifier('verifier');

        $captured = [];
        $this->injectResponder($captured, fn() => ['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 60]);

        $this->service->completeOAuth($server, 'the-code', 'st');

        $this->assertSame('Basic ' . base64_encode('my+client:s3cr%3Aet'), $captured[0]['authorization']);
        $this->assertArrayNotHasKey('client_id', $captured[0]['body']);
        $this->assertArrayNotHasKey('client_secret', $captured[0]['body']);
        $this->assertSame($this->ncBase . '/api/admin/mcp-servers/oauth/callback', $captured[0]['body']['redirect_uri']);
        $this->assertSame('at', $server->getOauthAccessToken());
    }

    public function testConfidentialClientFallsBackToClientSecretPost(): void {
        $server = $this->oauthServer(['token_endpoint_auth_methods_supported' => ['client_secret_post']]);
        $server->setOauthStaticClientId('cid');
        $server->setOauthStaticClientSecret('secret');
        $server->setOauthClientId('cid');
        $server->setOauthRefreshToken('rt');

        $captured = [];
        $this->injectResponder($captured, fn() => ['access_token' => 'at2']);

        $this->service->refreshOAuthToken($server);

        $this->assertNull($captured[0]['authorization']);
        $this->assertSame('cid', $captured[0]['body']['client_id']);
        $this->assertSame('secret', $captured[0]['body']['client_secret']);
        $this->assertSame('refresh_token', $captured[0]['body']['grant_type']);
    }

    public function testPublicPreRegisteredClientSendsClientId(): void {
        $server = $this->oauthServer([]);
        $server->setOauthStaticClientId('public-client');
        $server->setOauthClientId('public-client');
        $server->setOauthState('st');
        $server->setOauthCodeVerifier('verifier');

        $captured = [];
        $this->injectResponder($captured, fn() => ['access_token' => 'at']);

        $this->service->completeOAuth($server, 'the-code', 'st');

        $this->assertNull($captured[0]['authorization']);
        $this->assertSame('public-client', $captured[0]['body']['client_id']);
    }

    public function testRegisteredClientKeepsPerServerRedirectUri(): void {
        $server = $this->oauthServer([]);
        $server->setOauthClientId('dcr-client');
        $server->setOauthState('st');
        $server->setOauthCodeVerifier('verifier');

        $captured = [];
        $this->injectResponder($captured, fn() => ['access_token' => 'at']);

        $this->service->completeOAuth($server, 'the-code', 'st');

        $this->assertSame($this->ncBase . '/api/admin/mcp-servers/7/oauth/callback', $captured[0]['body']['redirect_uri']);
    }

    public function testCompleteOAuthRejectsMissingStoredState(): void {
        $server = $this->oauthServer([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('state mismatch');
        $this->service->completeOAuth($server, 'code', '');
    }

    /**
     * MCP 2026-07-28 "Client ID Metadata Documents": client_id, client_name and
     * redirect_uris, client_id equal to the document URL; draft §4.1 no secret.
     */
    public function testClientMetadataDocument(): void {
        $doc = $this->service->getClientMetadataDocument();

        $this->assertSame($this->ncBase . '/oauth/mcp-client', $doc['client_id']);
        $this->assertNotSame('', $doc['client_name']);
        $this->assertSame([$this->ncBase . '/api/admin/mcp-servers/oauth/callback'], $doc['redirect_uris']);
        $this->assertSame(['none'], $doc['token_endpoint_auth_methods_supported']);
        $this->assertArrayNotHasKey('client_secret', $doc);
    }

    // --- Authorization server discovery (RFC 9728; MCP 2026-07-28 "Authorization Server Discovery") ---

    private const MCP_URL = 'https://mcp.example/tenant/mcp';

    /**
     * A server entry with nothing cached, routed through $this->routes.
     *
     * @param array<string, array{0: int, 1: mixed, 2?: array<string, string>}> $routes
     */
    private function discoveryServer(array $routes): McpServer {
        $server = $this->makeServer(9, 'Remote', self::MCP_URL);
        $server->setAuthType('oauth2');
        $this->mapper->method('update')->willReturnArgument(0);
        $this->routes = $routes;
        $unused = [];
        $this->injectResponder($unused, fn() => []);
        return $server;
    }

    /** @return array<string, mixed> */
    private function asMetadata(string $issuer): array {
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/authorize',
            'token_endpoint' => $issuer . '/token',
        ];
    }

    /** @return list<string> GET requests to well-known URIs, in order */
    private function wellKnownRequests(): array {
        return array_values(array_filter(
            $this->discoveryLog,
            fn(string $r) => str_starts_with($r, 'GET ') && str_contains($r, '/.well-known/')
        ));
    }

    /**
     * The challenge's resource_metadata URL is used instead of the well-known
     * URIs, and the authorization server it names may live on another host
     * under a path (e.g. a Nextcloud app). Its scope is authoritative.
     */
    public function testDiscoveryFollowsResourceMetadataFromChallenge(): void {
        $issuer = 'https://cloud.example/index.php/apps/oauth';
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [401, '', [
                'WWW-Authenticate' => 'Bearer realm="mcp", resource_metadata="https://mcp.example/prm.json", scope="files mail"',
            ]],
            'GET https://mcp.example/prm.json' => [200, [
                'resource' => self::MCP_URL,
                'authorization_servers' => [$issuer],
                'scopes_supported' => ['files', 'mail', 'calendar'],
            ]],
            'GET https://cloud.example/.well-known/oauth-authorization-server/index.php/apps/oauth' => [200, $this->asMetadata($issuer)],
        ]);

        $metadata = $this->service->discoverOAuth($server);

        $this->assertSame($issuer, $metadata['issuer']);
        $this->assertSame('files mail', $server->getOauthScope());
        $this->assertSame(self::MCP_URL, json_decode((string)$server->getOauthResourceMetadata(), true)['resource']);
        $this->assertSame([
            'GET https://cloud.example/.well-known/oauth-authorization-server/index.php/apps/oauth',
        ], $this->wellKnownRequests(), 'no well-known protected resource lookup when the challenge names one');
    }

    /** Without a challenge: path-based well-known URI first, then the root. */
    public function testDiscoveryFallsBackToPathBasedThenRootResourceMetadata(): void {
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [200, ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]],
            'GET https://mcp.example/.well-known/oauth-protected-resource' => [200, [
                'resource' => self::MCP_URL,
                'authorization_servers' => ['https://auth.example'],
            ]],
            'GET https://auth.example/.well-known/oauth-authorization-server' => [200, $this->asMetadata('https://auth.example')],
        ]);

        $this->service->discoverOAuth($server);

        $this->assertSame([
            'GET https://mcp.example/.well-known/oauth-protected-resource/tenant/mcp',
            'GET https://mcp.example/.well-known/oauth-protected-resource',
            'GET https://auth.example/.well-known/oauth-authorization-server',
        ], $this->wellKnownRequests());
        $this->assertNull($server->getOauthScope(), 'no challenge scope and no scopes_supported');
    }

    /** Issuer with a path: RFC 8414 insertion, OIDC insertion, then OIDC appending. */
    public function testIssuerWithPathTriesAllThreeEndpointsInOrder(): void {
        $issuer = 'https://auth.example/tenant1';
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [401, '', ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example/prm"']],
            'GET https://mcp.example/prm' => [200, ['resource' => self::MCP_URL, 'authorization_servers' => [$issuer]]],
            'GET https://auth.example/tenant1/.well-known/openid-configuration' => [200, $this->asMetadata($issuer)],
        ]);

        $this->service->discoverOAuth($server);

        $this->assertSame([
            'GET https://auth.example/.well-known/oauth-authorization-server/tenant1',
            'GET https://auth.example/.well-known/openid-configuration/tenant1',
            'GET https://auth.example/tenant1/.well-known/openid-configuration',
        ], $this->wellKnownRequests());
    }

    /** Issuer without a path: RFC 8414, then OIDC discovery. */
    public function testIssuerWithoutPathFallsBackToOpenIdConfiguration(): void {
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [401, '', ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example/prm"']],
            'GET https://mcp.example/prm' => [200, ['resource' => self::MCP_URL, 'authorization_servers' => ['https://auth.example']]],
            'GET https://auth.example/.well-known/openid-configuration' => [200, $this->asMetadata('https://auth.example')],
        ]);

        $metadata = $this->service->discoverOAuth($server);

        $this->assertSame('https://auth.example', $metadata['issuer']);
        $this->assertSame([
            'GET https://auth.example/.well-known/oauth-authorization-server',
            'GET https://auth.example/.well-known/openid-configuration',
        ], $this->wellKnownRequests());
    }

    /** RFC 8414 §3.3: metadata whose issuer differs MUST NOT be used. */
    public function testIssuerMismatchIsRejected(): void {
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [401, '', ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example/prm"']],
            'GET https://mcp.example/prm' => [200, ['resource' => self::MCP_URL, 'authorization_servers' => ['https://auth.example']]],
            'GET https://auth.example/.well-known/oauth-authorization-server' => [200, $this->asMetadata('https://honest.example')],
            'GET https://auth.example/.well-known/openid-configuration' => [200, $this->asMetadata('https://auth.example/')],
        ]);

        try {
            $this->service->discoverOAuth($server);
            $this->fail('metadata with a different issuer was used');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No valid authorization server metadata', $e->getMessage());
        }
        $this->assertNull($server->getOauthMetadata());
    }

    /** A later endpoint with the right issuer is still used after a mismatch. */
    public function testIssuerMismatchMovesOnToTheNextEndpoint(): void {
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [401, '', ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example/prm"']],
            'GET https://mcp.example/prm' => [200, ['resource' => self::MCP_URL, 'authorization_servers' => ['https://auth.example']]],
            'GET https://auth.example/.well-known/oauth-authorization-server' => [200, $this->asMetadata('https://attacker.example')],
            'GET https://auth.example/.well-known/openid-configuration' => [200, $this->asMetadata('https://auth.example')],
        ]);

        $this->assertSame('https://auth.example', $this->service->discoverOAuth($server)['issuer']);
    }

    /** RFC 9728 §3.3: metadata for another resource MUST NOT be used. */
    public function testResourceMetadataForAnotherResourceIsIgnored(): void {
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [401, '', ['WWW-Authenticate' => 'Bearer resource_metadata="https://evil.example/prm"']],
            'GET https://evil.example/prm' => [200, ['resource' => 'https://evil.example/mcp', 'authorization_servers' => ['https://evil.example']]],
            'GET https://mcp.example/.well-known/oauth-protected-resource/tenant/mcp' => [200, [
                'resource' => 'HTTPS://MCP.example/tenant/mcp/',
                'authorization_servers' => ['https://auth.example'],
            ]],
            'GET https://auth.example/.well-known/oauth-authorization-server' => [200, $this->asMetadata('https://auth.example')],
        ]);

        $this->assertSame('https://auth.example', $this->service->discoverOAuth($server)['issuer']);
        $this->assertNotContains('GET https://evil.example/.well-known/oauth-authorization-server', $this->discoveryLog);
    }

    /** Scope Selection Strategy: all of scopes_supported when the challenge has none, sent on authorize. */
    public function testScopeFallsBackToScopesSupportedAndIsSent(): void {
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [401, '', ['WWW-Authenticate' => 'Bearer error="invalid_token"']],
            'GET https://mcp.example/.well-known/oauth-protected-resource/tenant/mcp' => [200, [
                'resource' => self::MCP_URL,
                'authorization_servers' => ['https://auth.example'],
                'scopes_supported' => ['mcp:tools', 'mcp:files'],
            ]],
            'GET https://auth.example/.well-known/oauth-authorization-server' => [200, $this->asMetadata('https://auth.example')],
        ]);
        $server->setOauthStaticClientId('pre');

        $query = $this->authorizeQuery($this->service->initiateOAuth($server));

        $this->assertSame('mcp:tools mcp:files', $query['scope']);
        $this->assertSame('https://auth.example/authorize', strtok($this->service->initiateOAuth($server), '?'));
    }

    public function testNoScopeIsSentWhenNoneIsKnown(): void {
        $server = $this->oauthServer([]);
        $server->setOauthStaticClientId('pre');

        $query = $this->authorizeQuery($this->service->initiateOAuth($server));

        $this->assertArrayNotHasKey('scope', $query);
    }

    /** A server without protected resource metadata is its own authorization server at its origin. */
    public function testServerWithoutResourceMetadataUsesItsOrigin(): void {
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [401, ''],
            'GET https://mcp.example/.well-known/oauth-authorization-server' => [200, $this->asMetadata('https://mcp.example')],
        ]);

        $this->assertSame('https://mcp.example', $this->service->discoverOAuth($server)['issuer']);
        $this->assertNull($server->getOauthResourceMetadata());
    }

    public function testResourceMetadataWithoutAuthorizationServersFails(): void {
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [401, ''],
            'GET https://mcp.example/.well-known/oauth-protected-resource/tenant/mcp' => [200, ['resource' => self::MCP_URL]],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/lists no authorization_servers/');
        $this->service->discoverOAuth($server);
    }

    /** MUST NOT assume tokens from one authorization server are accepted by another. */
    public function testIssuerChangeDropsTokens(): void {
        $server = $this->discoveryServer([
            'POST ' . self::MCP_URL => [401, ''],
            'GET https://mcp.example/.well-known/oauth-protected-resource/tenant/mcp' => [200, [
                'resource' => self::MCP_URL,
                'authorization_servers' => ['https://new-as.example'],
            ]],
            'GET https://new-as.example/.well-known/oauth-authorization-server' => [200, $this->asMetadata('https://new-as.example')],
        ]);
        $server->setOauthMetadata((string)json_encode($this->asMetadata('https://old-as.example')));
        $server->setOauthAccessToken('old-at');
        $server->setOauthRefreshToken('old-rt');
        $server->setOauthTokenExpiresAt(time() + 3600);

        $this->service->discoverOAuth($server);

        $this->assertNull($server->getOauthAccessToken());
        $this->assertNull($server->getOauthRefreshToken());
        $this->assertNull($server->getOauthTokenExpiresAt());
    }

    public function testSameIssuerKeepsTokens(): void {
        $server = $this->oauthServer([]);
        $server->setOauthAccessToken('at');

        $this->service->discoverOAuth($server);

        $this->assertSame('at', $server->getOauthAccessToken());
    }

    /**
     * RFC 9110 §11.6.1 / RFC 6750 §3: several challenges per field or per
     * field line, case-insensitive scheme and parameter names, quoted-string
     * escapes, and a token68 credential before the Bearer challenge.
     */
    public function testParseBearerChallenge(): void {
        $this->assertSame(
            ['realm' => 'a, "b"', 'resource_metadata' => 'https://x/prm'],
            McpClientService::parseBearerChallenge([
                'Basic YWxhZGRpbjpvcGVuc2VzYW1l==, bearer Realm="a, \\"b\\"", RESOURCE_METADATA="https://x/prm"',
            ])
        );
        $this->assertSame(
            ['resource_metadata' => 'https://x/prm', 'scope' => 'one two'],
            McpClientService::parseBearerChallenge([
                'Newauth realm="apps", type=1',
                'Bearer resource_metadata="https://x/prm", scope="one two"',
            ])
        );
        $this->assertSame(['error' => 'invalid_token'], McpClientService::parseBearerChallenge(['Bearer error=invalid_token']));
        $this->assertSame([], McpClientService::parseBearerChallenge(['Bearer']));
        $this->assertNull(McpClientService::parseBearerChallenge(['Basic realm="x"']));
        $this->assertNull(McpClientService::parseBearerChallenge([]));
    }

    public function testGetOAuthBaseUrl(): void {
        $server = $this->makeServer(1, 'Test', 'http://localhost:3339/mcp');
        $this->assertEquals('http://localhost:3339', $this->service->getOAuthBaseUrl($server));

        $server->setUrl('http://example.com');
        $this->assertEquals('http://example.com', $this->service->getOAuthBaseUrl($server));
    }

    // --- Resource indicators (RFC 8707; MCP 2026-07-28 "Resource Parameter Implementation") ---

    /** MUST be included in authorization requests. */
    public function testAuthorizationRequestSendsResource(): void {
        $server = $this->oauthServer([]);
        $server->setOauthStaticClientId('cid');

        $query = $this->authorizeQuery($this->service->initiateOAuth($server));

        $this->assertSame('https://cloud.example/apps/mcp/mcp', $query['resource']);
    }

    /** MUST be included in token requests; RFC 8707 §2.2. */
    public function testCodeExchangeSendsResource(): void {
        $server = $this->oauthServer([]);
        $server->setOauthClientId('dcr-client');
        $server->setOauthState('st');
        $server->setOauthCodeVerifier('verifier');

        $captured = [];
        $this->injectResponder($captured, fn() => ['access_token' => 'at']);

        $this->service->completeOAuth($server, 'the-code', 'st');

        $this->assertSame('authorization_code', $captured[0]['body']['grant_type']);
        $this->assertSame('https://cloud.example/apps/mcp/mcp', $captured[0]['body']['resource']);
    }

    /** RFC 8707 §2.2: a refresh request may name the resource too, and MCP asks for it on every token request. */
    public function testRefreshSendsResource(): void {
        $server = $this->oauthServer([]);
        $server->setOauthClientId('dcr-client');
        $server->setOauthRefreshToken('rt');

        $captured = [];
        $this->injectResponder($captured, fn() => ['access_token' => 'at2']);

        $this->service->refreshOAuthToken($server);

        $this->assertSame('refresh_token', $captured[0]['body']['grant_type']);
        $this->assertSame('https://cloud.example/apps/mcp/mcp', $captured[0]['body']['resource']);
    }

    /**
     * "Canonical Server URI": lowercase scheme and host, no fragment (RFC 8707
     * §2), no trailing slash; path, port and query kept.
     */
    public function testResourceUriIsCanonical(): void {
        $cases = [
            'HTTPS://MCP.Example.COM/mcp/' => 'https://mcp.example.com/mcp',
            'https://mcp.example.com:8443/Tenant/MCP#frag' => 'https://mcp.example.com:8443/Tenant/MCP',
            'https://mcp.example.com/' => 'https://mcp.example.com',
            'https://mcp.example.com/mcp/?tenant=a#x' => 'https://mcp.example.com/mcp?tenant=a',
        ];
        foreach ($cases as $url => $expected) {
            $server = $this->makeServer(1, 'Test', $url);
            $this->assertSame($expected, $this->service->getResourceUri($server), $url);
        }
    }

    /** With Protected Resource Metadata, its `resource` (RFC 9728 §3.3) is the identifier. */
    public function testResourceUriPrefersResourceMetadata(): void {
        $server = $this->makeServer(1, 'Test', 'https://mcp.example.com/mcp');
        $server->setOauthResourceMetadata((string)json_encode([
            'resource' => 'https://mcp.example.com',
            'authorization_servers' => ['https://auth.example'],
        ]));

        $this->assertSame('https://mcp.example.com', $this->service->getResourceUri($server));
    }
}
