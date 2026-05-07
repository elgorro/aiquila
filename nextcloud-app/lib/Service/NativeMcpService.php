<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\McpServerMapper;
use OCP\IConfig;
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
    private IConfig $config;
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;

    public function __construct(
        McpServerMapper $mapper,
        CredentialService $credentials,
        IConfig $config,
        LoggerInterface $logger
    ) {
        $this->mapper = $mapper;
        $this->credentials = $credentials;
        $this->config = $config;
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
            $entry = [
                'type' => 'url',
                'name' => $this->slugifyName($server->getDisplayName()),
                'url' => $url,
            ];
            $token = $this->resolveAuthToken($server);
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
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        return $slug !== '' ? $slug : 'mcp';
    }

    /**
     * Resolve the appropriate auth token for an McpServer entity, decrypting
     * via CredentialService. OAuth access token wins; bearer token next.
     */
    private function resolveAuthToken(\OCA\AIquila\Db\McpServer $server): ?string {
        if ($server->getAuthType() === 'oauth2' && $server->getOauthAccessToken()) {
            return $this->credentials->decryptToken($server->getOauthAccessToken());
        }
        if ($server->getAuthType() === 'bearer' && $server->getAuthToken()) {
            return $this->credentials->decryptToken($server->getAuthToken());
        }
        return null;
    }
}
