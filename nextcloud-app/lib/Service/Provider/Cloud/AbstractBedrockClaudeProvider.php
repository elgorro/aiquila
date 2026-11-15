<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use Anthropic\Bedrock\Client as BedrockClient;
use Anthropic\Core\BaseClient;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use OCA\AIquila\Service\Provider\ModelCatalog;

/**
 * The two Bedrock routes (Messages on Mantle, and InvokeModel) share how
 * models are listed: neither data-plane endpoint has a models route, so both
 * ask the Bedrock control plane with the same credentials (BedrockModelCatalog).
 *
 * The SDK's Bedrock client does the auth — a bearer API key, SigV4 access
 * keys or the default credential chain — and passes any path that is not a
 * Messages call through untouched, so pointing it at the control-plane host
 * is all it takes. The SigV4 signing name is `bedrock` on both planes.
 */
abstract class AbstractBedrockClaudeProvider extends AbstractAwsClaudeProvider {
    /** ListInferenceProfiles pages; one page holds up to 1000 profiles. */
    private const MAX_PROFILE_PAGES = 10;

    protected function listsModels(): bool {
        return true;
    }

    /** Whether chat on this route streams, so a non-streaming model is flagged. */
    protected function streamsChat(): bool {
        return true;
    }

    public function fetchModelCatalog(?string $userId = null): ?ModelCatalog {
        if (!$this->isConfigured()) {
            return ModelCatalog::failed($this->getLabel(), $this->unconfiguredMessage() . '.');
        }
        try {
            $client = $this->controlPlaneClient();
            $models = $this->callControlPlane($client, 'foundation-models', [
                'byProvider' => 'Anthropic',
                'byOutputModality' => 'TEXT',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila SDK: Could not list Bedrock models', ['provider' => $this->getId(), 'error' => $e->getMessage()]);
            return ModelCatalog::failed($this->getLabel(), $this->listingErrorMessage($e));
        }
        try {
            $profiles = $this->listInferenceProfiles($client);
        } catch (\Throwable $e) {
            // The models alone still make a picker; profile-only models then
            // say that their profiles could not be listed.
            $this->logger->info('AIquila SDK: Could not list Bedrock inference profiles', ['provider' => $this->getId(), 'error' => $e->getMessage()]);
            $profiles = null;
        }
        $summaries = is_array($models['modelSummaries'] ?? null) ? array_values(array_filter($models['modelSummaries'], 'is_array')) : [];
        return BedrockModelCatalog::build($summaries, $profiles, $this->getLabel(), $this->streamsChat(), new \DateTimeImmutable());
    }

    /**
     * Both profile types: system-defined (cross-Region, `us.` / `eu.` /
     * `global.`) and the account's own application profiles.
     *
     * @return list<array<array-key, mixed>>
     */
    private function listInferenceProfiles(BaseClient $client): array {
        $out = [];
        foreach (['SYSTEM_DEFINED', 'APPLICATION'] as $type) {
            $token = null;
            for ($page = 0; $page < self::MAX_PROFILE_PAGES; $page++) {
                $query = ['type' => $type, 'maxResults' => 1000] + ($token !== null ? ['nextToken' => $token] : []);
                $data = $this->callControlPlane($client, 'inference-profiles', $query);
                foreach (is_array($data['inferenceProfileSummaries'] ?? null) ? $data['inferenceProfileSummaries'] : [] as $summary) {
                    if (is_array($summary)) {
                        $out[] = $summary;
                    }
                }
                $token = is_string($data['nextToken'] ?? null) && $data['nextToken'] !== '' ? $data['nextToken'] : null;
                if ($token === null) {
                    break;
                }
            }
        }
        return $out;
    }

    /** The control plane, with the same credentials as the data plane. */
    protected function controlPlaneClient(): BaseClient {
        $mode = $this->authMode();
        $sdk = $this->requiredSdk($mode);
        if ($sdk !== null) {
            $this->sdk->require($sdk, $this->getLabel() . ' with "' . $mode . '" authentication');
        }
        $region = $this->region();
        $baseUrl = CloudEndpoints::bedrockControlPlaneUrl($region);
        return match ($mode) {
            self::AUTH_API_KEY => BedrockClient::withApiKey($this->requiredApiKey(), $region, $baseUrl),
            self::AUTH_ACCESS_KEYS => BedrockClient::withCredentials(
                accessKeyId: $this->awsAccessKeyId(),
                secretAccessKey: $this->requiredSecret('aws_secret_access_key'),
                region: $region,
                securityToken: $this->optionalSecret('aws_session_token'),
                baseUrl: $baseUrl,
            ),
            default => BedrockClient::fromEnvironment($region, $baseUrl),
        };
    }

    /**
     * GET a control-plane path and decode its JSON. Overridable for tests.
     *
     * @param array<string, string|int> $query
     * @return array<array-key, mixed>
     */
    protected function callControlPlane(BaseClient $client, string $path, array $query): array {
        $response = $client->request(method: 'get', path: $path, query: $query);
        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Bedrock returned a ' . $path . ' response that is not JSON.');
        }
        return $data;
    }

    /**
     * AWS answers a bad credential with 403 as well as a missing permission
     * ("The security token included in the request is invalid", "Invalid API
     * Key format"), so a 403 quotes AWS's own reason before naming the
     * permissions listing needs.
     */
    protected function listingErrorMessage(\Throwable $e): string {
        if ($e instanceof PermissionDeniedException) {
            $reason = self::awsReason($e);
            $needs = $this->authMode() === self::AUTH_API_KEY
                ? 'Check the API key. Listing also needs bedrock:ListFoundationModels and bedrock:ListInferenceProfiles, which a key allowed only on the Mantle endpoint may not have; type the model ID instead.'
                : 'Check the credentials, and that the AWS principal has bedrock:ListFoundationModels and bedrock:ListInferenceProfiles.';
            return ($reason !== '' ? 'AWS refused the request: ' . rtrim($reason, '.') . '. ' : 'AWS refused the request. ') . $needs;
        }
        if ($e instanceof AuthenticationException) {
            return 'the credentials were rejected.';
        }
        return parent::listingErrorMessage($e);
    }

    /** AWS's `message` (or `Message`) from an error body; '' when there is none. */
    private static function awsReason(\Anthropic\Core\Exceptions\APIStatusException $e): string {
        $body = $e->body;
        if (!is_array($body)) {
            return '';
        }
        $message = $body['message'] ?? $body['Message'] ?? null;
        return is_string($message) ? trim($message) : '';
    }

    protected function modelNotFoundMessage(string $model): string {
        return sprintf(
            '%s isn\'t available on Bedrock in %s. Check the model or inference profile ID, and that the model is offered in this Region.',
            $model,
            $this->appValue('region'),
        );
    }

    protected function permissionDeniedMessage(): string {
        return sprintf(
            'Bedrock refused the request (403). Check that model access is enabled for %s in %s in the Bedrock console, and that the credentials may invoke it.',
            $this->getModel(),
            $this->appValue('region'),
        );
    }
}
