<?php

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use OCA\AIquila\Service\McpClientService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class McpServerController extends Controller {
    private McpServerMapper $mapper;
    private McpClientService $mcpClient;

    public function __construct(
        string $appName,
        IRequest $request,
        McpServerMapper $mapper,
        McpClientService $mcpClient
    ) {
        parent::__construct($appName, $request);
        $this->mapper = $mapper;
        $this->mcpClient = $mcpClient;
    }

    /**
     * List all configured MCP servers
     *
     * 200: List of MCP servers with masked auth tokens
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{id: int, display_name: string, url: string, auth_type: string, is_enabled: bool, last_status: string|null, last_error: string|null, tool_count: int|null, last_connected_at: int|null}>, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function index(): JSONResponse {
        $servers = $this->mapper->findAll();
        $result = array_map(fn(McpServer $s) => $this->serializeServer($s), $servers);
        return new JSONResponse($result);
    }

    /**
     * Add a new MCP server
     *
     * @param string $displayName Human-readable server name
     * @param string $url MCP server endpoint URL
     * @param string $authType Authentication type (none or bearer)
     * @param string $authToken Bearer token for authentication
     *
     * 200: Created MCP server
     * 400: Validation error
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, display_name: string, url: string, auth_type: string, is_enabled: bool}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function create(
        string $displayName = '',
        string $url = '',
        string $authType = 'none',
        string $authToken = ''
    ): JSONResponse {
        if (empty($displayName) || empty($url)) {
            return new JSONResponse(['error' => 'Display name and URL are required'], 400);
        }

        if (!in_array($authType, ['none', 'bearer'], true)) {
            return new JSONResponse(['error' => 'Invalid auth type. Must be "none" or "bearer"'], 400);
        }

        $now = time();
        $server = new McpServer();
        $server->setDisplayName($displayName);
        $server->setUrl($url);
        $server->setAuthType($authType);
        $server->setAuthToken($authType === 'bearer' ? $authToken : null);
        $server->setIsEnabled(true);
        $server->setCreatedAt($now);
        $server->setUpdatedAt($now);

        $server = $this->mapper->insert($server);
        return new JSONResponse($this->serializeServer($server));
    }

    /**
     * Update an existing MCP server
     *
     * @param int $id Server ID
     * @param string $displayName Updated server name
     * @param string $url Updated endpoint URL
     * @param string $authType Updated auth type
     * @param string $authToken Updated bearer token
     * @param bool $isEnabled Whether the server is enabled
     *
     * 200: Updated MCP server
     * 404: Server not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, display_name: string, url: string, auth_type: string, is_enabled: bool}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function update(
        int $id,
        string $displayName = '',
        string $url = '',
        string $authType = '',
        string $authToken = '',
        ?bool $isEnabled = null
    ): JSONResponse {
        try {
            $server = $this->mapper->findById($id);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Server not found'], 404);
        }

        if (!empty($displayName)) {
            $server->setDisplayName($displayName);
        }
        if (!empty($url)) {
            $server->setUrl($url);
        }
        if (!empty($authType) && in_array($authType, ['none', 'bearer'], true)) {
            $server->setAuthType($authType);
            if ($authType === 'none') {
                $server->setAuthToken(null);
            }
        }
        if (!empty($authToken) && $server->getAuthType() === 'bearer') {
            $server->setAuthToken($authToken);
        }
        if ($isEnabled !== null) {
            $server->setIsEnabled($isEnabled);
        }
        $server->setUpdatedAt(time());

        $this->mapper->update($server);
        return new JSONResponse($this->serializeServer($server));
    }

    /**
     * Delete an MCP server
     *
     * @param int $id Server ID
     *
     * 200: Server deleted
     * 404: Server not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{status: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function destroy(int $id): JSONResponse {
        try {
            $server = $this->mapper->findById($id);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Server not found'], 404);
        }

        $this->mapper->delete($server);
        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Test connection to an MCP server
     *
     * @param int $id Server ID
     *
     * 200: Test result with success status and tool count
     * 404: Server not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{success: bool, message: string, tool_count: int}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function test(int $id): JSONResponse {
        try {
            $server = $this->mapper->findById($id);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Server not found'], 404);
        }

        $result = $this->mcpClient->testConnection($server);
        return new JSONResponse($result);
    }

    /**
     * List tools from a specific MCP server
     *
     * @param int $id Server ID
     *
     * 200: List of tools from the server
     * 404: Server not found
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{name: string, description: string}>, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function tools(int $id): JSONResponse {
        try {
            $server = $this->mapper->findById($id);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Server not found'], 404);
        }

        try {
            $tools = $this->mcpClient->listTools($server);
            return new JSONResponse($tools);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Failed to list tools: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Serialize an McpServer entity for API response, masking the auth token.
     */
    private function serializeServer(McpServer $server): array {
        $token = $server->getAuthToken();
        $maskedToken = null;
        if ($token) {
            $maskedToken = str_repeat('*', max(0, strlen($token) - 4)) . substr($token, -4);
        }

        return [
            'id' => $server->getId(),
            'display_name' => $server->getDisplayName(),
            'url' => $server->getUrl(),
            'auth_type' => $server->getAuthType(),
            'auth_token_masked' => $maskedToken,
            'is_enabled' => $server->getIsEnabled(),
            'last_status' => $server->getLastStatus(),
            'last_error' => $server->getLastError(),
            'tool_count' => $server->getToolCount(),
            'last_connected_at' => $server->getLastConnectedAt(),
            'created_at' => $server->getCreatedAt(),
            'updated_at' => $server->getUpdatedAt(),
        ];
    }
}
