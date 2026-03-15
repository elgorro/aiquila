<?php

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class McpClientService {
    private McpServerMapper $mapper;
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;

    private const PROTOCOL_VERSION = '2025-03-26';
    private const CLIENT_NAME = 'aiquila-nextcloud';

    /** @var array<int, string> Session IDs keyed by server ID */
    private array $sessions = [];

    /** @var array<int, bool> Track which servers have been initialized */
    private array $initialized = [];

    public function __construct(McpServerMapper $mapper, LoggerInterface $logger) {
        $this->mapper = $mapper;
        $this->logger = $logger;
        $this->httpClient = HttpClient::create(['timeout' => 30]);
    }

    /**
     * Send a JSON-RPC 2.0 request to an MCP server.
     */
    private function jsonRpc(McpServer $server, string $method, array $params = [], ?int $id = null): array {
        $id = $id ?? random_int(1, 999999);

        $body = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ];

        if ($server->getAuthType() === 'bearer' && $server->getAuthToken()) {
            $headers['Authorization'] = 'Bearer ' . $server->getAuthToken();
        }

        $serverId = $server->getId();
        if (isset($this->sessions[$serverId])) {
            $headers['Mcp-Session-Id'] = $this->sessions[$serverId];
        }

        try {
            $response = $this->httpClient->request('POST', $server->getUrl(), [
                'headers' => $headers,
                'json' => $body,
            ]);

            $statusCode = $response->getStatusCode();
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

            // Handle SSE responses — extract the final JSON-RPC result
            if (str_contains($contentType, 'text/event-stream')) {
                $content = $response->getContent(false);
                return $this->parseSseResponse($content, $statusCode, $response);
            }

            $result = $response->toArray(false);

            // Capture session ID from response headers
            $sessionHeaders = $response->getHeaders(false)['mcp-session-id'] ?? [];
            if (!empty($sessionHeaders)) {
                $this->sessions[$serverId] = $sessionHeaders[0];
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("HTTP $statusCode: " . json_encode($result));
            }

            if (isset($result['error'])) {
                throw new \RuntimeException('JSON-RPC error: ' . ($result['error']['message'] ?? 'unknown'));
            }

            return $result['result'] ?? [];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('MCP request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Parse an SSE response to extract JSON-RPC result.
     */
    private function parseSseResponse(string $content, int $statusCode, $response): array {
        $serverId = null;

        // Capture session ID from response headers
        $sessionHeaders = $response->getHeaders(false)['mcp-session-id'] ?? [];

        $lastData = null;
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ')) {
                $lastData = substr($line, 6);
            }
        }

        if ($lastData === null) {
            throw new \RuntimeException("No data in SSE response (HTTP $statusCode)");
        }

        $result = json_decode($lastData, true);
        if ($result === null) {
            throw new \RuntimeException('Invalid JSON in SSE data');
        }

        // Capture session from SSE response headers too
        if (!empty($sessionHeaders) && isset($result['id'])) {
            // We need the server context — session will be captured by caller
        }

        if (isset($result['error'])) {
            throw new \RuntimeException('JSON-RPC error: ' . ($result['error']['message'] ?? 'unknown'));
        }

        return $result['result'] ?? [];
    }

    /**
     * Initialize the MCP session handshake.
     */
    public function initialize(McpServer $server): void {
        $serverId = $server->getId();
        if (isset($this->initialized[$serverId])) {
            return;
        }

        $result = $this->jsonRpc($server, 'initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => [
                'name' => self::CLIENT_NAME,
                'version' => '0.1.58',
            ],
        ]);

        $this->initialized[$serverId] = true;

        $this->logger->debug('MCP: initialized session with server', [
            'server' => $server->getDisplayName(),
            'protocol' => $result['protocolVersion'] ?? 'unknown',
        ]);
    }

    /**
     * List tools from a single MCP server.
     *
     * @return array Tool definitions
     */
    public function listTools(McpServer $server): array {
        $this->initialize($server);
        $result = $this->jsonRpc($server, 'tools/list');
        return $result['tools'] ?? [];
    }

    /**
     * Call a tool on an MCP server.
     */
    public function callTool(McpServer $server, string $name, array $args): array {
        $this->initialize($server);

        try {
            $result = $this->jsonRpc($server, 'tools/call', [
                'name' => $name,
                'arguments' => $args,
            ]);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('MCP: tool call failed', [
                'server' => $server->getDisplayName(),
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);
            return [
                'content' => [
                    ['type' => 'text', 'text' => 'Tool execution error: ' . $e->getMessage()],
                ],
                'isError' => true,
            ];
        }
    }

    /**
     * Get all tools from all enabled servers, with collision handling.
     *
     * Returns:
     *   'tools' => Anthropic-format tool definitions
     *   'mapping' => ['toolName' => ['serverId' => int, 'originalName' => string]]
     */
    public function getAllTools(): array {
        $servers = $this->mapper->findAllEnabled();
        $tools = [];
        $mapping = [];
        $seenNames = [];

        foreach ($servers as $server) {
            try {
                $serverTools = $this->listTools($server);
                $this->updateServerStatus($server, 'ok', null, count($serverTools));
            } catch (\Throwable $e) {
                $this->logger->warning('MCP: failed to list tools from server', [
                    'server' => $server->getDisplayName(),
                    'error' => $e->getMessage(),
                ]);
                $this->updateServerStatus($server, 'error', $e->getMessage(), null);
                continue;
            }

            $slug = $this->slugify($server->getDisplayName());

            foreach ($serverTools as $tool) {
                $originalName = $tool['name'];
                $toolName = $originalName;

                // Handle name collisions by prefixing with server slug
                if (isset($seenNames[$originalName])) {
                    $toolName = $slug . '__' . $originalName;
                }
                $seenNames[$originalName] = true;

                $tools[] = [
                    'name' => $toolName,
                    'description' => $tool['description'] ?? '',
                    'input_schema' => $tool['inputSchema'] ?? ['type' => 'object'],
                ];
                $mapping[$toolName] = [
                    'serverId' => $server->getId(),
                    'originalName' => $originalName,
                ];
            }
        }

        return ['tools' => $tools, 'mapping' => $mapping];
    }

    /**
     * Execute a tool call, resolving the server from the mapping.
     */
    public function executeTool(string $toolName, array $args, array $mapping): array {
        if (!isset($mapping[$toolName])) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => "Unknown tool: $toolName"],
                ],
                'isError' => true,
            ];
        }

        $info = $mapping[$toolName];
        try {
            $server = $this->mapper->findById($info['serverId']);
        } catch (\Throwable $e) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => 'MCP server not found for tool: ' . $toolName],
                ],
                'isError' => true,
            ];
        }

        return $this->callTool($server, $info['originalName'], $args);
    }

    /**
     * Test connection to an MCP server.
     */
    public function testConnection(McpServer $server): array {
        try {
            $tools = $this->listTools($server);
            $count = count($tools);
            $this->updateServerStatus($server, 'ok', null, $count);
            return [
                'success' => true,
                'message' => "Connected successfully. Found $count tools.",
                'tool_count' => $count,
            ];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $this->updateServerStatus($server, 'error', $message, null);
            return [
                'success' => false,
                'message' => $message,
                'tool_count' => 0,
            ];
        }
    }

    private function updateServerStatus(McpServer $server, string $status, ?string $error, ?int $toolCount): void {
        $server->setLastStatus($status);
        $server->setLastError($error);
        if ($toolCount !== null) {
            $server->setToolCount($toolCount);
        }
        if ($status === 'ok') {
            $server->setLastConnectedAt(time());
        }
        $server->setUpdatedAt(time());

        try {
            $this->mapper->update($server);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP: failed to update server status', [
                'server' => $server->getDisplayName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function slugify(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        return trim($slug, '_');
    }
}
