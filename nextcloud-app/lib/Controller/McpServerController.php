<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\McpClientService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class McpServerController extends Controller {
    use ErrorResponseTrait;
    private McpServerMapper $mapper;
    private McpClientService $mcpClient;
    private CredentialService $credentials;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        McpServerMapper $mapper,
        McpClientService $mcpClient,
        CredentialService $credentials,
        IURLGenerator $urlGenerator,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->mapper = $mapper;
        $this->mcpClient = $mcpClient;
        $this->credentials = $credentials;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
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
     * @param string $registrationToken RFC 7591 initial access token for gated OAuth client registration
     *
     * 200: Created MCP server
     * 400: Validation error
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, display_name: string, url: string, auth_type: string, is_enabled: bool}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function create(
        string $displayName = '',
        string $url = '',
        string $authType = 'none',
        string $authToken = '',
        string $registrationToken = ''
    ): JSONResponse {
        if (empty($displayName) || empty($url)) {
            return $this->clientError(400, 'Display name and URL are required');
        }

        if (!in_array($authType, ['none', 'bearer', 'oauth2'], true)) {
            return $this->clientError(400, 'Invalid auth type. Must be "none", "bearer", or "oauth2"');
        }

        $now = time();
        $server = new McpServer();
        $server->setDisplayName($displayName);
        $server->setUrl($url);
        $server->setAuthType($authType);
        $server->setAuthToken($authType === 'bearer' ? $this->credentials->encryptToken($authToken) : null);
        $server->setOauthRegistrationToken(
            $authType === 'oauth2' && $registrationToken !== ''
                ? $this->credentials->encryptToken($registrationToken)
                : null
        );
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
     * @param string|null $registrationToken Updated registration token; null leaves it unchanged, an empty string clears it
     * @param bool $isEnabled Whether the server is enabled
     *
     * 200: Updated MCP server
     * 404: Server not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, display_name: string, url: string, auth_type: string, is_enabled: bool}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function update(
        int $id,
        string $displayName = '',
        string $url = '',
        string $authType = '',
        string $authToken = '',
        ?string $registrationToken = null,
        ?bool $isEnabled = null
    ): JSONResponse {
        try {
            $server = $this->mapper->findById($id);
        } catch (\Throwable $e) {
            return $this->clientError(404, 'Server not found');
        }

        if (!empty($displayName)) {
            $server->setDisplayName($displayName);
        }
        if (!empty($url)) {
            $server->setUrl($url);
        }
        if (!empty($authType) && in_array($authType, ['none', 'bearer', 'oauth2'], true)) {
            $oldAuthType = $server->getAuthType();
            $server->setAuthType($authType);
            if ($authType === 'none') {
                $server->setAuthToken(null);
            }
            // Clear OAuth fields when switching away from oauth2
            if ($oldAuthType === 'oauth2' && $authType !== 'oauth2') {
                $this->mcpClient->clearOAuthFields($server);
            }
            // Clear bearer token when switching to oauth2
            if ($authType === 'oauth2') {
                $server->setAuthToken(null);
            }
        }
        if (!empty($authToken) && $server->getAuthType() === 'bearer') {
            $server->setAuthToken($this->credentials->encryptToken($authToken));
        }
        // Unlike the other fields, null (not '') means "unchanged" here, so an
        // admin can also clear a stored registration token by submitting ''.
        if ($registrationToken !== null) {
            $stored = $this->credentials->decryptToken($server->getOauthRegistrationToken()) ?? '';
            if ($stored !== $registrationToken) {
                $server->setOauthRegistrationToken(
                    $registrationToken === '' ? null : $this->credentials->encryptToken($registrationToken)
                );
                // The existing client_id was registered under the old token (or
                // under none): drop it plus the tokens it earned so the next
                // Authorize re-registers against the gated endpoint.
                $server->setOauthClientId(null);
                $server->setOauthAccessToken(null);
                $server->setOauthRefreshToken(null);
                $server->setOauthTokenExpiresAt(null);
            }
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
     * @return JSONResponse<Http::STATUS_OK, array{status: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function destroy(int $id): JSONResponse {
        try {
            $server = $this->mapper->findById($id);
        } catch (\Throwable $e) {
            return $this->clientError(404, 'Server not found');
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
     * @return JSONResponse<Http::STATUS_OK, array{success: bool, message: string, tool_count: int}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function test(int $id): JSONResponse {
        try {
            $server = $this->mapper->findById($id);
        } catch (\Throwable $e) {
            return $this->clientError(404, 'Server not found');
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
     * 500: Listing the tools failed
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{name: string, description: string}>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function tools(int $id): JSONResponse {
        try {
            $server = $this->mapper->findById($id);
        } catch (\Throwable $e) {
            return $this->clientError(404, 'Server not found');
        }

        try {
            $tools = $this->mcpClient->listTools($server);
            return new JSONResponse($tools);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 500, 'Failed to list tools on the MCP server', 'McpServerController::listTools');
        }
    }

    /**
     * Initiate the OAuth 2.1 PKCE flow for an MCP server
     *
     * @param int $id Server ID
     *
     * 200: Authorize URL to open in popup
     * 404: Server not found
     * 500: Starting the authorization flow failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{authorize_url: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function authorize(int $id): JSONResponse {
        try {
            $server = $this->mapper->findById($id);
        } catch (\Throwable $e) {
            return $this->clientError(404, 'Server not found');
        }

        try {
            $callbackUrl = $this->urlGenerator->linkToRouteAbsolute(
                'aiquila.mcp_server.oauthCallback',
                ['id' => $id]
            );
            $authorizeUrl = $this->mcpClient->initiateOAuth($server, $callbackUrl);
            return new JSONResponse(['authorize_url' => $authorizeUrl]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, 500, 'Could not start the OAuth flow', 'McpServerController::initiateOAuth');
        }
    }

    /**
     * OAuth callback endpoint — receives the authorization code from the popup
     *
     * @param int $id Server ID
     * @param string $code Authorization code from the OAuth provider
     * @param string $state CSRF state parameter
     */
    #[NoCSRFRequired]
    // Browser-redirect target carrying attacker-influenced code/state, so it is
    // the most exposed endpoint in the app. Limit by IP and throttle guessing.
    #[AnonRateLimit(limit: 10, period: 60)]
    #[BruteForceProtection(action: 'aiquilaMcpOauthCallback')]
    #[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
    public function oauthCallback(int $id, string $code = '', string $state = ''): DataDisplayResponse {

        $failed = false;

        try {
            $server = $this->mapper->findById($id);
            $callbackUrl = $this->urlGenerator->linkToRouteAbsolute(
                'aiquila.mcp_server.oauthCallback',
                ['id' => $id]
            );
            $this->mcpClient->completeOAuth($server, $code, $state, $callbackUrl);

            $html = '<!DOCTYPE html><html><body><script>'
                . 'window.opener.postMessage({type:"aiquila-oauth-complete",serverId:' . $id . '}, window.location.origin);'
                . 'window.close();'
                . '</script><p>Authentication successful. This window should close automatically.</p></body></html>';
        } catch (\Throwable $e) {
            $failed = true;
            // Rendered into the popup the user sees. Guzzle/cURL failures embed
            // request URLs, CA bundle paths and config paths, so show only a
            // correlation id and keep the detail in the log.
            $errorId = $this->newErrorId();
            $this->logger->error(
                'McpServerController::oauthCallback failed [' . $errorId . ']',
                ['exception' => $e, 'errorId' => $errorId],
            );
            $html = '<!DOCTYPE html><html><body>'
                . '<h3>Authentication Failed</h3>'
                . '<p>Ask your administrator to check the log for reference '
                . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p><button onclick="window.close()">Close</button></p></body></html>';
        }

        $response = new DataDisplayResponse($html);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');

        // BruteForceProtection only counts an attempt when the response is
        // throttled, so a failed exchange must mark itself explicitly. A wrong
        // or replayed state lands here, which is what makes guessing expensive.
        if ($failed) {
            $response->throttle(['action' => 'aiquilaMcpOauthCallback']);
        }

        return $response;
    }

    /**
     * Serialize an McpServer entity for API response, masking the auth token.
     */
    private function serializeServer(McpServer $server): array {
        $token = $server->getAuthToken();
        $maskedToken = $token ? '****' : null;

        // Determine OAuth status
        $oauthStatus = null;
        if ($server->getAuthType() === 'oauth2') {
            if ($server->getOauthAccessToken()) {
                $oauthStatus = $this->mcpClient->isTokenExpired($server) ? 'expired' : 'authenticated';
            } else {
                $oauthStatus = 'not_authenticated';
            }
        }

        return [
            'id' => $server->getId(),
            'display_name' => $server->getDisplayName(),
            'url' => $server->getUrl(),
            'auth_type' => $server->getAuthType(),
            'auth_token_masked' => $maskedToken,
            'registration_token_masked' => $server->getOauthRegistrationToken() ? '****' : null,
            'is_enabled' => $server->getIsEnabled(),
            'last_status' => $server->getLastStatus(),
            'last_error' => $server->getLastError(),
            'tool_count' => $server->getToolCount(),
            'last_connected_at' => $server->getLastConnectedAt(),
            'created_at' => $server->getCreatedAt(),
            'updated_at' => $server->getUpdatedAt(),
            'oauth_status' => $oauthStatus,
        ];
    }
}
