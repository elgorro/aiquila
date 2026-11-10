<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class McpClientService {
    private McpServerMapper $mapper;
    private LoggerInterface $logger;
    private CredentialService $credentials;
    private IURLGenerator $urlGenerator;
    private HttpClientInterface $httpClient;

    private const PROTOCOL_VERSION = '2025-03-26';
    private const CLIENT_NAME = 'aiquila-nextcloud';

    /** Per-server callback, registered by dynamic client registration. */
    public const CALLBACK_ROUTE = 'aiquila.mcp_server.oauthCallback';
    /** One callback for every server, for clients whose redirect URIs are fixed up front. */
    public const SHARED_CALLBACK_ROUTE = 'aiquila.mcp_server.oauthCallbackShared';
    /** The Client ID Metadata Document this instance publishes. */
    public const CLIENT_METADATA_ROUTE = 'aiquila.mcp_server.clientMetadata';

    /** @var array<int, string> Session IDs keyed by server ID */
    private array $sessions = [];

    /** @var array<int, bool> Track which servers have been initialized */
    private array $initialized = [];

    public function __construct(
        McpServerMapper $mapper,
        LoggerInterface $logger,
        CredentialService $credentials,
        IURLGenerator $urlGenerator,
    ) {
        $this->mapper = $mapper;
        $this->logger = $logger;
        $this->credentials = $credentials;
        $this->urlGenerator = $urlGenerator;
        $this->httpClient = HttpClient::create(['timeout' => 30]);
    }

    /**
     * Assemble a JSON-RPC 2.0 request body.
     *
     * `params` is optional in JSON-RPC and MUST be an object when present. An
     * empty PHP array encodes to `[]`, not `{}`, which the MCP SDK's schema
     * rejects with -32700 "Invalid JSON-RPC message" — so the key is omitted
     * entirely for parameterless methods such as `tools/list`.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function buildRequestBody(string $method, array $params, int $id): array {
        $body = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
        ];
        if ($params !== []) {
            $body['params'] = $params;
        }
        return $body;
    }

    /**
     * Build the HTTP headers shared by every POST to an MCP server: content
     * negotiation, auth (refreshing an expired OAuth token first) and the
     * session ID once `initialize` has returned one.
     *
     * @return array<string, string>
     */
    private function buildHeaders(McpServer $server): array {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ];

        if ($server->getAuthType() === 'oauth2' && $server->getOauthAccessToken()) {
            if ($this->isTokenExpired($server)) {
                $this->refreshOAuthToken($server);
            }
            $headers['Authorization'] = 'Bearer ' . $this->credentials->decryptToken($server->getOauthAccessToken());
        } elseif ($server->getAuthType() === 'bearer' && $server->getAuthToken()) {
            $headers['Authorization'] = 'Bearer ' . $this->credentials->decryptToken($server->getAuthToken());
        }

        $serverId = $server->getId();
        if (isset($this->sessions[$serverId])) {
            $headers['Mcp-Session-Id'] = $this->sessions[$serverId];
        }

        return $headers;
    }

    /**
     * Send a JSON-RPC 2.0 request to an MCP server.
     */
    private function jsonRpc(McpServer $server, string $method, array $params = [], ?int $id = null): array {
        $id = $id ?? random_int(1, 999999);

        $body = $this->buildRequestBody($method, $params, $id);
        $headers = $this->buildHeaders($server);

        try {
            $response = $this->httpClient->request('POST', $server->getUrl(), [
                'headers' => $headers,
                'json' => $body,
            ]);

            $statusCode = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);

            // Capture session ID from response headers. It must be kept whether
            // the server answers as JSON or as an SSE stream.
            $sessionHeaders = $responseHeaders['mcp-session-id'] ?? [];
            if (!empty($sessionHeaders)) {
                $this->sessions[$server->getId()] = $sessionHeaders[0];
            }

            // Handle SSE responses — extract the final JSON-RPC result
            $contentType = $responseHeaders['content-type'][0] ?? '';
            if (str_contains($contentType, 'text/event-stream')) {
                return $this->parseSseResponse($response->getContent(false), $statusCode);
            }

            $result = $response->toArray(false);

            if ($statusCode >= 400) {
                throw new \RuntimeException("HTTP $statusCode: " . (string)json_encode($result));
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
     * Send a JSON-RPC 2.0 notification to an MCP server.
     *
     * Notifications have no request ID and therefore do not return a JSON-RPC
     * result. A successful HTTP response is sufficient; any 4xx/5xx response
     * is surfaced as an MCP error.
     */
    private function jsonRpcNotification(McpServer $server, string $method, array $params = []): void {
        $body = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];
        if ($params !== []) {
            $body['params'] = $params;
        }

        $headers = $this->buildHeaders($server);

        try {
            $response = $this->httpClient->request('POST', $server->getUrl(), [
                'headers' => $headers,
                'json' => $body,
            ]);

            $statusCode = $response->getStatusCode();

            // A notification has no JSON-RPC response body to parse. Accept any
            // successful HTTP response, including 202/204 with an empty body.
            if ($statusCode >= 400) {
                throw new \RuntimeException("HTTP $statusCode: " . $response->getContent(false));
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('MCP notification failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Parse an SSE response to extract JSON-RPC result.
     */
    private function parseSseResponse(string $content, int $statusCode): array {
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

        // MCP requires the client to acknowledge the initialize response before
        // sending normal requests such as tools/list or tools/call.
        $this->jsonRpcNotification($server, 'notifications/initialized');

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
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        return trim($slug, '_');
    }

    /**
     * Discover the MCP server's authorization server and cache its metadata.
     *
     * MCP 2026-07-28 "Authorization Server Discovery":
     *  1. Protected Resource Metadata (RFC 9728) comes from the
     *     `resource_metadata` URL of the 401 challenge, or else from the
     *     well-known URIs, path-based first and then the root.
     *  2. The authorization server is one of its `authorization_servers`.
     *  3. That server's metadata is fetched from the RFC 8414 and OpenID
     *     Connect well-known URIs in the spec's order, and used only when its
     *     `issuer` is identical to the identifier the URL was built from
     *     (RFC 8414 §3.3).
     *
     * The authorization request's scope follows "Scope Selection Strategy":
     * the challenge's `scope`, else all of `scopes_supported`, else none.
     *
     * A server that publishes no Protected Resource Metadata is treated as
     * its own authorization server at its origin, as MCP revisions up to
     * 2025-03-26 described.
     *
     * Credentials belong to one authorization server ("Authorization Server
     * Binding"): when the issuer changes, the tokens from the old one are
     * dropped, and resolveClient() refuses or replaces a client bound to it.
     *
     * @return array<string, mixed> The authorization server metadata
     */
    public function discoverOAuth(McpServer $server): array {
        $challenge = $this->probeChallenge($server);
        $resourceMetadata = $this->fetchResourceMetadata($server, $challenge['resource_metadata'] ?? null);

        if ($resourceMetadata !== null) {
            $issuers = array_values(array_filter(
                (array)($resourceMetadata['authorization_servers'] ?? []),
                static fn ($v): bool => is_string($v) && $v !== ''
            ));
            if ($issuers === []) {
                throw new \RuntimeException(
                    'The MCP server\'s protected resource metadata lists no authorization_servers.'
                );
            }
        } else {
            $this->logger->info('MCP: no protected resource metadata, using the server origin as authorization server', [
                'server' => $server->getDisplayName(),
            ]);
            $issuers = [$this->originOf($server->getUrl())];
        }

        $metadata = null;
        $tried = [];
        // RFC 9728 §7.6 leaves the choice among several authorization
        // servers to the client: take the first whose metadata validates.
        foreach ($issuers as $issuer) {
            $metadata = $this->fetchAuthorizationServerMetadata($issuer, $tried);
            if ($metadata !== null) {
                break;
            }
        }
        if ($metadata === null) {
            throw new \RuntimeException(
                'No valid authorization server metadata found. Tried: ' . implode(', ', $tried)
            );
        }

        $scope = $challenge['scope'] ?? null;
        if ($scope === null || $scope === '') {
            $supported = $resourceMetadata['scopes_supported'] ?? null;
            $scope = is_array($supported)
                ? implode(' ', array_filter($supported, static fn ($v): bool => is_string($v) && $v !== ''))
                : null;
        }

        $previous = $server->getOauthMetadata();
        $previousIssuer = $previous ? (json_decode($previous, true)['issuer'] ?? null) : null;
        if ($previousIssuer !== null && $previousIssuer !== $metadata['issuer']) {
            // MUST NOT assume tokens from one authorization server are
            // accepted by another.
            $server->setOauthAccessToken(null);
            $server->setOauthRefreshToken(null);
            $server->setOauthTokenExpiresAt(null);
        }

        $server->setOauthMetadata(json_encode($metadata) ?: null);
        $server->setOauthResourceMetadata($resourceMetadata !== null ? (json_encode($resourceMetadata) ?: null) : null);
        $server->setOauthScope($scope !== null && $scope !== '' ? $scope : null);
        $server->setUpdatedAt(time());
        $this->mapper->update($server);

        return $metadata;
    }

    /**
     * Send an unauthenticated MCP request and return the Bearer challenge's
     * parameters when the server answers 401 (MCP 2026-07-28: clients MUST
     * parse WWW-Authenticate on a 401). Any other outcome yields [] and
     * discovery falls back to the well-known URIs.
     *
     * @return array<string, string>
     */
    private function probeChallenge(McpServer $server): array {
        try {
            $response = $this->httpClient->request('POST', $server->getUrl(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json, text/event-stream',
                ],
                'json' => $this->buildRequestBody('initialize', [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => self::CLIENT_NAME, 'version' => '0.1.58'],
                ], 1),
            ]);
            if ($response->getStatusCode() !== 401) {
                $response->cancel();
                return [];
            }
            $values = $response->getHeaders(false)['www-authenticate'] ?? [];
            return self::parseBearerChallenge($values) ?? [];
        } catch (\Throwable $e) {
            $this->logger->debug('MCP: discovery probe failed', [
                'server' => $server->getDisplayName(),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Pull the parameters of the first Bearer challenge out of WWW-Authenticate
     * field values (RFC 9110 §11.6.1, RFC 6750 §3). Parameter names are
     * case-insensitive and returned lower-cased; quoted-string values are
     * unescaped. A challenge carrying a token68 instead of parameters is
     * skipped.
     *
     * @param list<string> $values
     * @return array<string, string>|null Null when there is no Bearer challenge
     */
    public static function parseBearerChallenge(array $values): ?array {
        $tchar = '[!#$%&\'*+.^_`|~0-9A-Za-z-]';
        /** @var list<array{0: string, 1: array<string, string>}> $challenges */
        $challenges = [];
        $current = null;
        foreach ($values as $value) {
            $pos = 0;
            $len = strlen($value);
            while ($pos < $len) {
                if (!preg_match('/\G[\s,]*(' . $tchar . '+)\s*/A', $value, $m, 0, $pos)) {
                    break;
                }
                $pos += strlen($m[0]);
                $token = $m[1];
                if ($pos < $len && $value[$pos] === '=' && $current !== null
                    && preg_match('/\G=\s*("(?:[^"\\\\]|\\\\.)*"|' . $tchar . '*)/A', $value, $v, 0, $pos)) {
                    $pos += strlen($v[0]);
                    $raw = $v[1];
                    if ($raw !== '' && $raw[0] === '"') {
                        $raw = (string)preg_replace('/\\\\(.)/s', '$1', substr($raw, 1, -1));
                    }
                    $challenges[$current][1][strtolower($token)] = $raw;
                    continue;
                }
                // A token not followed by "=" starts a new challenge.
                $challenges[] = [strtolower($token), []];
                $current = count($challenges) - 1;
                // Skip a token68 credential (RFC 9110 §11.2).
                if (preg_match('/\G[A-Za-z0-9\-._~+\/]+=*(?=\s*(,|$))/A', $value, $t, 0, $pos)
                    && !preg_match('/\G' . $tchar . '+\s*=[^=]/A', $value, $ignored, 0, $pos)) {
                    $pos += strlen($t[0]);
                }
            }
        }
        foreach ($challenges as $challenge) {
            if ($challenge[0] === 'bearer') {
                return $challenge[1];
            }
        }
        return null;
    }

    /**
     * Fetch and validate the MCP server's Protected Resource Metadata.
     *
     * The challenge's `resource_metadata` URL is used when present; otherwise
     * (or when it yields nothing usable) the well-known URIs (RFC 9728 §3.1),
     * path-based first and then the root.
     *
     * RFC 9728 §3.3 requires `resource` to be identical to the resource
     * identifier: the URL requested for a challenge-supplied document, or the
     * URL the well-known suffix was inserted into. MCP 2026-07-28 also lets an
     * MCP server at a path publish its metadata at the root, so a root document
     * may name either the origin or the MCP endpoint. Scheme and host are
     * compared case-insensitively and a trailing slash is ignored, as the MCP
     * "Canonical Server URI" section asks.
     *
     * @return array<string, mixed>|null
     */
    private function fetchResourceMetadata(McpServer $server, ?string $challengeUrl): ?array {
        $resource = $server->getUrl();
        $parts = parse_url($resource);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('The MCP server URL is not an absolute URL: ' . $resource);
        }
        $origin = $this->originOf($resource);
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        $candidates = [];
        if ($challengeUrl !== null && $challengeUrl !== '') {
            $candidates[] = [$challengeUrl, [$resource]];
        }
        if ($path !== '' || $query !== '') {
            $candidates[] = [$origin . '/.well-known/oauth-protected-resource' . $path . $query, [$resource]];
        }
        $candidates[] = [$origin . '/.well-known/oauth-protected-resource', [$origin, $resource]];

        foreach ($candidates as [$url, $expected]) {
            $document = $this->fetchJson($url);
            if ($document === null) {
                continue;
            }
            $actual = $document['resource'] ?? null;
            $matches = is_string($actual) && in_array(
                self::comparableUrl($actual),
                array_map(static fn (string $u): string => self::comparableUrl($u), $expected),
                true
            );
            if (!$matches) {
                $this->logger->warning('MCP: ignoring protected resource metadata for another resource', [
                    'server' => $server->getDisplayName(),
                    'url' => $url,
                    'resource' => is_string($actual) ? $actual : null,
                ]);
                continue;
            }
            return $document;
        }
        return null;
    }

    /**
     * Fetch authorization server metadata for an issuer from the well-known
     * URIs MCP 2026-07-28 lists, in its order, and return the first document
     * whose `issuer` is identical to $issuer (RFC 8414 §3.3, OpenID Connect
     * Discovery §4.3).
     *
     * @param list<string> $tried URLs attempted, appended to for error reporting
     * @return array<string, mixed>|null
     */
    private function fetchAuthorizationServerMetadata(string $issuer, array &$tried): ?array {
        $parts = parse_url($issuer);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            // RFC 8414 §2: an issuer has no query or fragment component.
            $tried[] = $issuer . ' (not a valid issuer)';
            return null;
        }
        $origin = $this->originOf($issuer);
        // RFC 8414 §3.1: a terminating "/" is removed before inserting.
        $path = rtrim($parts['path'] ?? '', '/');

        $urls = $path !== ''
            ? [
                $origin . '/.well-known/oauth-authorization-server' . $path,
                $origin . '/.well-known/openid-configuration' . $path,
                $origin . $path . '/.well-known/openid-configuration',
            ]
            : [
                $origin . '/.well-known/oauth-authorization-server',
                $origin . '/.well-known/openid-configuration',
            ];

        foreach ($urls as $url) {
            $tried[] = $url;
            $document = $this->fetchJson($url);
            if ($document === null) {
                continue;
            }
            if (($document['issuer'] ?? null) !== $issuer) {
                // MUST NOT use metadata whose issuer differs.
                $this->logger->warning('MCP: ignoring authorization server metadata with a different issuer', [
                    'url' => $url,
                    'expected' => $issuer,
                    'issuer' => is_string($document['issuer'] ?? null) ? $document['issuer'] : null,
                ]);
                continue;
            }
            return $document;
        }
        return null;
    }

    /**
     * GET a URL and return its body as a JSON object, or null on any failure.
     *
     * @return array<string, mixed>|null
     */
    private function fetchJson(string $url): ?array {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() !== 200) {
                $response->cancel();
                return null;
            }
            $data = json_decode($response->getContent(false), true);
            return is_array($data) && !array_is_list($data) ? $data : null;
        } catch (\Throwable $e) {
            $this->logger->debug('MCP: metadata request failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** scheme://host[:port] of a URL, as written. */
    private function originOf(string $url): string {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return rtrim($url, '/');
        }
        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[')
            ? '[' . $parts['host'] . ']'
            : $parts['host'];
        return $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** A URL with lower-cased scheme and host and no trailing slash, for comparison. */
    private static function comparableUrl(string $url): string {
        $url = rtrim($url, '/');
        if (!preg_match('#^([A-Za-z][A-Za-z0-9+.-]*://)([^/?\#]*)(.*)$#s', $url, $m)) {
            return $url;
        }
        return strtolower($m[1]) . strtolower($m[2]) . $m[3];
    }

    /**
     * Register a dynamic OAuth client with the MCP server.
     */
    public function registerOAuthClient(McpServer $server, string $callbackUrl): string {
        $metadata = $this->getOAuthMetadata($server);
        $registrationEndpoint = $metadata['registration_endpoint'] ?? null;

        if (!$registrationEndpoint) {
            throw new \RuntimeException('No registration_endpoint in OAuth metadata');
        }

        $options = [
            'json' => [
                'client_name' => self::CLIENT_NAME,
                'redirect_uris' => [$callbackUrl],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => 'none',
                // MCP 2026-07-28 "Dynamic Client Registration": clients MUST send an
                // application_type; a remote web app SHOULD use "web", one reached
                // via localhost "native" (an OIDC server would refuse a loopback
                // redirect for "web").
                'application_type' => $this->isLoopbackUrl($callbackUrl) ? 'native' : 'web',
            ],
        ];

        // RFC 7591 §3.1 initial access token. Servers that gate dynamic registration
        // (aiquila-mcp does so via MCP_REGISTRATION_TOKEN) answer 401 without it;
        // servers with open registration ignore the header.
        $registrationToken = $this->credentials->decryptToken($server->getOauthRegistrationToken());
        if ($registrationToken !== null && $registrationToken !== '') {
            $options['headers'] = ['Authorization' => 'Bearer ' . $registrationToken];
        }

        try {
            $response = $this->httpClient->request('POST', $registrationEndpoint, $options);
            $result = $response->toArray();
        } catch (\Throwable $e) {
            // A 401/403 here almost always means the endpoint is gated and the
            // configured registration token is missing or wrong — say so, rather
            // than surfacing a bare HTTP status to the admin.
            $status = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface
                ? $e->getResponse()->getStatusCode()
                : 0;
            if ($status === 401 || $status === 403) {
                throw new \RuntimeException(
                    'Dynamic client registration was rejected (HTTP ' . $status . '). '
                    . ($registrationToken === null || $registrationToken === ''
                        ? 'This MCP server requires a registration token — set one on the server entry.'
                        : 'The configured registration token was not accepted.'),
                    0,
                    $e
                );
            }
            throw $e;
        }

        $clientId = $result['client_id'] ?? null;

        if (!$clientId) {
            throw new \RuntimeException('No client_id returned from dynamic registration');
        }

        $server->setOauthClientId($clientId);
        $server->setOauthClientIssuer($this->issuerOf($server, $metadata));
        $server->setUpdatedAt(time());
        $this->mapper->update($server);

        return $clientId;
    }

    /**
     * Pick the client this server's authorization flow runs as, registering
     * one if needed, and return its client_id.
     *
     * MCP 2026-07-28 "Client Registration" priority order (SHOULD):
     *  1. a client the admin pre-registered with the authorization server;
     *  2. a Client ID Metadata Document, when the authorization server
     *     advertises `client_id_metadata_document_supported`;
     *  3. dynamic client registration (RFC 7591) as the fallback.
     *
     * "Authorization Server Binding": a pre-registered or dynamically
     * registered client is bound to the `issuer` it was registered with and
     * MUST NOT be reused against another one. A metadata document URL is
     * portable and needs no binding.
     */
    public function resolveClient(McpServer $server): string {
        $metadata = $this->getOAuthMetadata($server);
        $issuer = $this->issuerOf($server, $metadata);
        $current = $server->getOauthClientId();
        $bound = $server->getOauthClientIssuer();

        $static = $server->getOauthStaticClientId();
        if ($static !== null && $static !== '') {
            if ($current === $static && $bound !== null && $bound !== $issuer) {
                // SHOULD surface an error rather than use mismatched credentials.
                throw new \RuntimeException(
                    'The pre-registered OAuth client was registered with ' . $bound
                    . ', but this MCP server now uses the authorization server ' . $issuer
                    . '. Register a client there and update the client ID.'
                );
            }
            $this->bindClient($server, $static, $issuer);
            return $static;
        }

        $documentUrl = $this->getClientMetadataUrl();
        if ($this->supportsClientMetadataDocument($metadata, $documentUrl)) {
            $this->bindClient($server, $documentUrl, null);
            return $documentUrl;
        }

        $reusable = $current !== null && $current !== ''
            && $current !== $documentUrl
            && ($bound === null || $bound === $issuer);
        if ($reusable) {
            // Rows registered before the binding was recorded adopt the current issuer.
            if ($bound === null) {
                $this->bindClient($server, $current, $issuer);
            }
            return $current;
        }

        if (empty($metadata['registration_endpoint'])) {
            throw new \RuntimeException(
                'The authorization server supports neither Client ID Metadata Documents'
                . ($this->isHttpsUrl($documentUrl) ? '' : ' (which need Nextcloud to be served over HTTPS)')
                . ' nor dynamic client registration. Register an OAuth client with it and enter'
                . ' the client ID on the MCP server entry.'
            );
        }

        return $this->registerOAuthClient($server, $this->getCallbackUrl($server));
    }

    /**
     * The redirect URI the flow for the server's current client uses. Dynamic
     * registration registers a per-server callback; a pre-registered client
     * and the metadata document share one fixed callback, so the admin has a
     * single URI to register and the document needs no per-server entries.
     */
    public function getRedirectUri(McpServer $server): string {
        $clientId = $server->getOauthClientId();
        $static = $server->getOauthStaticClientId();
        $usesShared = $clientId !== null && $clientId !== ''
            && ($clientId === $static || $clientId === $this->getClientMetadataUrl());
        return $usesShared ? $this->getSharedCallbackUrl() : $this->getCallbackUrl($server);
    }

    public function getCallbackUrl(McpServer $server): string {
        return $this->urlGenerator->linkToRouteAbsolute(self::CALLBACK_ROUTE, ['id' => $server->getId()]);
    }

    public function getSharedCallbackUrl(): string {
        return $this->urlGenerator->linkToRouteAbsolute(self::SHARED_CALLBACK_ROUTE);
    }

    /** The client_id this instance uses with Client ID Metadata Documents. */
    public function getClientMetadataUrl(): string {
        return $this->urlGenerator->linkToRouteAbsolute(self::CLIENT_METADATA_ROUTE);
    }

    /**
     * The Client ID Metadata Document (draft-ietf-oauth-client-id-metadata-document-03;
     * MCP 2026-07-28 "Client ID Metadata Documents").
     *
     * MCP requires client_id, client_name and redirect_uris, with client_id
     * equal to the document URL (draft §4). The client is public: the draft
     * (§4.1) forbids secrets and the secret-based auth methods, and §5 asks for
     * token_endpoint_auth_methods_supported. token_endpoint_auth_method "none"
     * is kept as well because the MCP example uses it and servers written
     * against earlier drafts read it.
     *
     * @return array<string, mixed>
     */
    public function getClientMetadataDocument(): array {
        return [
            'client_id' => $this->getClientMetadataUrl(),
            'client_name' => 'AIquila (Nextcloud)',
            'client_uri' => $this->urlGenerator->getAbsoluteURL('/'),
            'redirect_uris' => [$this->getSharedCallbackUrl()],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'token_endpoint_auth_methods_supported' => ['none'],
        ];
    }

    /**
     * True when the authorization server takes a metadata document URL as
     * client_id and this instance can publish one: the URL must be https with
     * a path (draft §3), and the server must accept public clients.
     *
     * @param array<string, mixed> $metadata
     */
    private function supportsClientMetadataDocument(array $metadata, string $documentUrl): bool {
        if (($metadata['client_id_metadata_document_supported'] ?? false) !== true) {
            return false;
        }
        if (!$this->isHttpsUrl($documentUrl)) {
            return false;
        }
        $methods = $metadata['token_endpoint_auth_methods_supported'] ?? null;
        return !is_array($methods) || in_array('none', $methods, true);
    }

    private function bindClient(McpServer $server, string $clientId, ?string $issuer): void {
        if ($server->getOauthClientId() === $clientId && $server->getOauthClientIssuer() === $issuer) {
            return;
        }
        $server->setOauthClientId($clientId);
        $server->setOauthClientIssuer($issuer);
        $server->setUpdatedAt(time());
        $this->mapper->update($server);
    }

    /**
     * The authorization server's issuer identifier (RFC 8414 §2, REQUIRED).
     * Servers that leave it out are identified by the URL they were
     * discovered at.
     *
     * @param array<string, mixed> $metadata
     */
    private function issuerOf(McpServer $server, array $metadata): string {
        $issuer = $metadata['issuer'] ?? null;
        return is_string($issuer) && $issuer !== '' ? $issuer : $this->getOAuthBaseUrl($server);
    }

    /**
     * Client authentication for a token request (RFC 6749 §2.3). A public
     * client sends its client_id (§4.1.3, §6). A pre-registered confidential
     * client authenticates with its secret: HTTP Basic, which every
     * authorization server MUST support (§2.3.1) and which is the default
     * when metadata lists no methods (RFC 8414 §2), else client_secret_post.
     * Only one method is used per request (§2.3).
     *
     * @param array<string, mixed> $metadata
     * @param array<string, string> $body
     * @return array{body: array<string, string>, headers: array<string, string>}
     */
    private function withClientAuth(McpServer $server, array $metadata, array $body): array {
        $clientId = (string)$server->getOauthClientId();
        $secret = null;
        if ($clientId !== '' && $clientId === $server->getOauthStaticClientId()) {
            $secret = $this->credentials->decryptToken($server->getOauthStaticClientSecret());
        }

        if ($secret === null || $secret === '') {
            $body['client_id'] = $clientId;
            return ['body' => $body, 'headers' => []];
        }

        $methods = $metadata['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        $methods = is_array($methods) ? $methods : ['client_secret_basic'];
        if (in_array('client_secret_basic', $methods, true)) {
            // §2.3.1: both parts are form-urlencoded before the Basic encoding.
            $credentials = base64_encode(urlencode($clientId) . ':' . urlencode($secret));
            return ['body' => $body, 'headers' => ['Authorization' => 'Basic ' . $credentials]];
        }
        if (in_array('client_secret_post', $methods, true)) {
            $body['client_id'] = $clientId;
            $body['client_secret'] = $secret;
            return ['body' => $body, 'headers' => []];
        }
        throw new \RuntimeException(
            'The authorization server accepts none of the client authentication methods AIquila'
            . ' supports for a client secret (client_secret_basic, client_secret_post).'
        );
    }

    private function isHttpsUrl(string $url): bool {
        return str_starts_with(strtolower($url), 'https://');
    }

    private function isLoopbackUrl(string $url): bool {
        $host = strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));
        return $host === 'localhost' || str_ends_with($host, '.localhost')
            || $host === '::1' || str_starts_with($host, '127.');
    }

    /**
     * Initiate the OAuth 2.1 PKCE flow. Returns the authorize URL for the popup.
     */
    public function initiateOAuth(McpServer $server): string {
        // Rediscover on every sign-in: the protected resource may have moved
        // to another authorization server since the metadata was cached.
        $this->discoverOAuth($server);
        $clientId = $this->resolveClient($server);
        $redirectUri = $this->getRedirectUri($server);

        $metadata = $this->getOAuthMetadata($server);
        $authorizeEndpoint = $metadata['authorization_endpoint'] ?? null;

        if (!$authorizeEndpoint) {
            throw new \RuntimeException('No authorization_endpoint in OAuth metadata');
        }

        // PKCE: generate verifier and challenge
        $verifier = rtrim(strtr(base64_encode(random_bytes(96)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        // CSRF state
        $state = bin2hex(random_bytes(16));

        // Store verifier + state for the callback
        $server->setOauthCodeVerifier($verifier);
        $server->setOauthState($state);
        $server->setUpdatedAt(time());
        $this->mapper->update($server);

        // Build authorize URL
        $query = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ];
        // MCP 2026-07-28 "Scope Selection Strategy"; omitted when discovery found none.
        $scope = $server->getOauthScope();
        if ($scope !== null && $scope !== '') {
            $query['scope'] = $scope;
        }
        $params = http_build_query($query);

        return $authorizeEndpoint . '?' . $params;
    }

    /**
     * Complete the OAuth flow by exchanging the authorization code for tokens.
     */
    public function completeOAuth(McpServer $server, string $code, string $state): void {
        // Verify CSRF state
        $expected = $server->getOauthState();
        if ($expected === null || $expected === '' || !hash_equals($expected, $state)) {
            throw new \RuntimeException('OAuth state mismatch — possible CSRF attack');
        }

        $metadata = $this->getOAuthMetadata($server);
        $tokenEndpoint = $metadata['token_endpoint'] ?? null;

        if (!$tokenEndpoint) {
            throw new \RuntimeException('No token_endpoint in OAuth metadata');
        }

        $request = $this->withClientAuth($server, $metadata, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => (string)$server->getOauthCodeVerifier(),
            'redirect_uri' => $this->getRedirectUri($server),
        ]);
        $response = $this->httpClient->request('POST', $tokenEndpoint, $request);

        $result = $response->toArray();

        if (!isset($result['access_token'])) {
            throw new \RuntimeException('No access_token in token response');
        }

        $server->setOauthAccessToken($this->credentials->encryptToken($result['access_token']));
        $server->setOauthRefreshToken($this->credentials->encryptToken($result['refresh_token'] ?? null));
        $server->setOauthTokenExpiresAt(time() + ($result['expires_in'] ?? 3600));
        $server->setOauthCodeVerifier(null);
        $server->setOauthState(null);
        $server->setLastStatus('ok');
        $server->setUpdatedAt(time());
        $this->mapper->update($server);
    }

    /**
     * Refresh the OAuth access token using the refresh token.
     */
    public function refreshOAuthToken(McpServer $server): void {
        $metadata = $this->getOAuthMetadata($server);
        $tokenEndpoint = $metadata['token_endpoint'] ?? null;

        if (!$tokenEndpoint || !$server->getOauthRefreshToken()) {
            $this->markAuthExpired($server);
            throw new \RuntimeException('Cannot refresh: missing token endpoint or refresh token');
        }

        try {
            $request = $this->withClientAuth($server, $metadata, [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string)$this->credentials->decryptToken($server->getOauthRefreshToken()),
            ]);
            $response = $this->httpClient->request('POST', $tokenEndpoint, $request);

            $result = $response->toArray();

            if (!isset($result['access_token'])) {
                throw new \RuntimeException('No access_token in refresh response');
            }

            $server->setOauthAccessToken($this->credentials->encryptToken($result['access_token']));
            if (isset($result['refresh_token'])) {
                $server->setOauthRefreshToken($this->credentials->encryptToken($result['refresh_token']));
            }
            $server->setOauthTokenExpiresAt(time() + ($result['expires_in'] ?? 3600));
            $server->setUpdatedAt(time());
            $this->mapper->update($server);
        } catch (\Throwable $e) {
            $this->markAuthExpired($server);
            throw new \RuntimeException('OAuth token refresh failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function isTokenExpired(McpServer $server): bool {
        $expiresAt = $server->getOauthTokenExpiresAt();
        if ($expiresAt === null) {
            return true;
        }
        // Refresh when less than 60 seconds remaining
        return time() >= ($expiresAt - 60);
    }

    public function getOAuthBaseUrl(McpServer $server): string {
        $url = $server->getUrl();
        // Strip /mcp suffix to get the base URL
        if (str_ends_with($url, '/mcp')) {
            return substr($url, 0, -4);
        }
        return $url;
    }

    public function getOAuthMetadata(McpServer $server): array {
        $cached = $server->getOauthMetadata();
        if ($cached) {
            return json_decode($cached, true);
        }
        return $this->discoverOAuth($server);
    }

    private function markAuthExpired(McpServer $server): void {
        $server->setLastStatus('auth_expired');
        $server->setOauthAccessToken(null);
        $server->setOauthRefreshToken(null);
        $server->setOauthTokenExpiresAt(null);
        $server->setUpdatedAt(time());
        try {
            $this->mapper->update($server);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP: failed to mark auth expired', [
                'server' => $server->getDisplayName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear all OAuth fields from a server entity.
     */
    public function clearOAuthFields(McpServer $server): void {
        $server->setOauthClientId(null);
        $server->setOauthClientIssuer(null);
        $server->setOauthStaticClientId(null);
        $server->setOauthStaticClientSecret(null);
        $server->setOauthAccessToken(null);
        $server->setOauthRefreshToken(null);
        $server->setOauthTokenExpiresAt(null);
        $server->setOauthCodeVerifier(null);
        $server->setOauthState(null);
        $server->setOauthMetadata(null);
        $server->setOauthResourceMetadata(null);
        $server->setOauthScope(null);
        $server->setOauthRegistrationToken(null);
    }
}
