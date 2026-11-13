<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds MCP-server descriptors and reachability snapshots for the native
 * MCP connector path (mcp_servers parameter on Anthropic's beta messages
 * endpoint, beta header `mcp-client-2025-11-20`).
 */
class NativeMcpService {
    private const APP_NAME = 'aiquila';

    private McpServerMapper $mapper;
    private CredentialService $credentials;
    private AppSettings $config;
    private McpClientService $mcpClient;
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;

    public function __construct(
        McpServerMapper $mapper,
        CredentialService $credentials,
        AppSettings $config,
        McpClientService $mcpClient,
        LoggerInterface $logger,
    ) {
        $this->mapper = $mapper;
        $this->credentials = $credentials;
        $this->config = $config;
        $this->mcpClient = $mcpClient;
        $this->logger = $logger;
        $this->httpClient = HttpClient::create(['timeout' => 5]);
    }

    /**
     * Resolve the effective native-MCP enabled state for a user.
     * Per-user override wins; otherwise admin default.
     */
    public function isEnabledForUser(?string $userId): bool {
        if ($userId !== null) {
            $override = $this->config->getUserValue($userId, self::APP_NAME, 'native_mcp_enabled', '');
            if ($override === '1') return true;
            if ($override === '0') return false;
        }
        return $this->config->getAppValue(self::APP_NAME, 'native_mcp_enabled', '0') === '1';
    }

    /**
     * Build the list of mcp_servers definitions to pass to the Anthropic beta
     * messages endpoint. Filters out non-HTTPS URLs since Anthropic cannot
     * reach plaintext HTTP from its infrastructure (and we'd leak tokens).
     * An OAuth server without a usable access token is left out too: the
     * provider would only get a 401 from it.
     *
     * @return list<array{type: string, name: string, url: string, authorization_token?: string}>
     */
    public function buildServerDefinitions(): array {
        $defs = [];

        foreach ($this->mapper->findAllEnabled() as $server) {
            $url = $server->getUrl();
            if (!$this->isHttpsUrl($url)) {
                continue;
            }
            if ($server->getAuthType() === 'oauth2') {
                $token = $this->currentOAuthToken($server);
                if ($token === null) {
                    continue;
                }
            } else {
                $token = $this->resolveBearerToken($server);
            }
            $entry = [
                'type' => 'url',
                'name' => $this->slugifyName($server->getDisplayName()),
                'url' => $url,
            ];
            if ($token !== null && $token !== '') {
                $entry['authorization_token'] = $token;
            }
            $defs[] = $entry;
        }

        $extraUrl = $this->config->getAppValue(self::APP_NAME, 'native_mcp_extra_url', '');
        if ($extraUrl !== '' && $this->isHttpsUrl($extraUrl)) {
            $entry = [
                'type' => 'url',
                'name' => 'aiquila-extra',
                'url' => $extraUrl,
            ];
            $token = $this->credentials->getNativeMcpExtraToken();
            if ($token !== '') {
                $entry['authorization_token'] = $token;
            }
            $defs[] = $entry;
        }

        return $defs;
    }

    /**
     * Build the connector tool entries to attach to a Mistral Conversations
     * request. Mistral connectors are pre-registered in the admin's Mistral
     * workspace; AIquila only stores their IDs (admin-configured, comma- or
     * newline-separated in `mistral_connector_ids`). Each becomes a
     * `{type:'connector', connector_id}` entry consumed by MistralProvider.
     *
     * @return list<array{type: string, connector_id: string}>
     */
    public function buildMistralConnectorTools(): array {
        $raw = $this->config->getAppValue(self::APP_NAME, 'mistral_connector_ids', '');
        $tools = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $id) {
            $id = trim($id);
            if ($id !== '') {
                $tools[] = ['type' => 'connector', 'connector_id' => $id];
            }
        }
        return $tools;
    }

    /**
     * Probe each candidate MCP server URL for HTTPS reachability and return a
     * reporting structure for the admin settings page. This is best-effort:
     * we issue a HEAD from this Nextcloud instance, which proves the URL
     * resolves but does not prove Anthropic can reach it.
     *
     * @return list<array{id: int|null, name: string, url: string, scheme_ok: bool, http_status: int|null, reachable: bool, message: string}>
     */
    public function probeAll(): array {
        $rows = [];

        foreach ($this->mapper->findAllEnabled() as $server) {
            $rows[] = $this->probeOne(
                id: $server->getId(),
                name: $server->getDisplayName(),
                url: $server->getUrl()
            );
        }

        $extraUrl = $this->config->getAppValue(self::APP_NAME, 'native_mcp_extra_url', '');
        if ($extraUrl !== '') {
            $rows[] = $this->probeOne(id: null, name: 'aiquila-extra', url: $extraUrl);
        }

        return $rows;
    }

    /**
     * @return array{id: int|null, name: string, url: string, scheme_ok: bool, http_status: int|null, reachable: bool, message: string}
     */
    private function probeOne(?int $id, string $name, string $url): array {
        $schemeOk = $this->isHttpsUrl($url);
        if (!$schemeOk) {
            return [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'scheme_ok' => false,
                'http_status' => null,
                'reachable' => false,
                'message' => 'URL must be https:// for Anthropic to call it.',
            ];
        }

        try {
            $resp = $this->httpClient->request('HEAD', $url, [
                'timeout' => 5,
                'max_redirects' => 2,
            ]);
            $status = $resp->getStatusCode();
            // Treat 2xx, 401 (auth challenge), 405 (HEAD not allowed but server is up) as reachable.
            $reachable = ($status >= 200 && $status < 300) || $status === 401 || $status === 405;
            return [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'scheme_ok' => true,
                'http_status' => $status,
                'reachable' => $reachable,
                'message' => $reachable ? 'OK' : 'HTTP ' . $status,
            ];
        } catch (\Throwable $e) {
            return [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'scheme_ok' => true,
                'http_status' => null,
                'reachable' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function isHttpsUrl(string $url): bool {
        return str_starts_with(strtolower($url), 'https://');
    }

    private function slugifyName(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        return $slug !== '' ? $slug : 'mcp';
    }

    /**
     * The server's OAuth access token, refreshed first when it has expired,
     * the way the direct path does (McpClientService::buildHeaders(), under
     * the same per-server lock). The provider calls the server on its own,
     * so it cannot refresh a token itself. Null when there is no token or
     * the refresh failed.
     */
    private function currentOAuthToken(McpServer $server): ?string {
        if (!$server->getOauthAccessToken()) {
            $this->logger->info('MCP: leaving an OAuth server out of the native connector: not signed in', [
                'server' => $server->getDisplayName(),
            ]);
            return null;
        }
        if ($this->mcpClient->isTokenExpired($server)) {
            try {
                $this->mcpClient->refreshOAuthToken($server);
            } catch (\Throwable $e) {
                $this->logger->warning('MCP: leaving an OAuth server out of the native connector: token refresh failed', [
                    'server' => $server->getDisplayName(),
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }
        $token = $server->getOauthAccessToken() ? $this->credentials->decryptToken($server->getOauthAccessToken()) : null;
        return $token !== null && $token !== '' ? $token : null;
    }

    /** The decrypted bearer token of a `bearer` server, or null. */
    private function resolveBearerToken(McpServer $server): ?string {
        if ($server->getAuthType() === 'bearer' && $server->getAuthToken()) {
            return $this->credentials->decryptToken($server->getAuthToken());
        }
        return null;
    }
}
