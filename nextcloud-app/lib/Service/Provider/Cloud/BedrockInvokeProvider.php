<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use Anthropic\Bedrock\Client as BedrockClient;
use Anthropic\Core\BaseClient;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Claude on Amazon Bedrock through the original InvokeModel API
 * (`bedrock-runtime`), with ARN-versioned model IDs such as
 * `global.anthropic.claude-opus-4-6-v1`.
 *
 * This is the route for Opus 4.6 and earlier; newer models are served by
 * BedrockProvider. On top of what Bedrock lacks in general, InvokeModel does
 * not accept the top-level automatic `cache_control`, so only the explicit
 * cache breakpoints are sent.
 */
class BedrockInvokeProvider extends AbstractAwsClaudeProvider {
    /**
     * The SDK sends `anthropic_version: 2026-10-21`, which Bedrock accepts only
     * for Claude 4.7 and later — exactly the models this route does not serve.
     * Opus 4.6 and earlier keep the Bedrock version they were released under.
     */
    public const ANTHROPIC_VERSION = 'bedrock-2023-05-31';

    public function getId(): string {
        return 'bedrock_invoke';
    }

    public function getLabel(): string {
        return 'Claude on Amazon Bedrock (InvokeModel)';
    }

    protected function defaultModel(): string {
        return 'global.anthropic.claude-sonnet-4-6';
    }

    protected function modelDescription(): string {
        return 'A Bedrock model ID or inference profile such as global.anthropic.claude-opus-4-6-v1 or us.anthropic.claude-haiku-4-5-20251001-v1:0, or an inference profile ARN. For Opus 4.7 and later use the "Claude in Amazon Bedrock" provider instead.';
    }

    protected function apiKeyDescription(): string {
        return 'A Bedrock API key. Short-term keys expire within 12 hours, so use a long-term key scoped to bedrock:CallWithBearerToken and bedrock:InvokeModel*, or the credential chain with a role. Stored encrypted in Nextcloud\'s credential manager.';
    }

    protected function platformFeatures(): array {
        return [self::FEATURE_AUTO_CACHE => false] + parent::platformFeatures();
    }

    protected function buildClient(string $authMode): BaseClient {
        $region = $this->region();
        $baseUrl = CloudEndpoints::bedrockRuntimeUrl($region);

        $requestOptions = ['middleware' => [self::pinAnthropicVersion(...)]];

        return match ($authMode) {
            self::AUTH_API_KEY => BedrockClient::withApiKey($this->requiredApiKey(), $region, $baseUrl, $requestOptions),
            self::AUTH_ACCESS_KEYS => BedrockClient::withCredentials(
                accessKeyId: $this->awsAccessKeyId(),
                secretAccessKey: $this->requiredSecret('aws_secret_access_key'),
                region: $region,
                securityToken: $this->optionalSecret('aws_session_token'),
                baseUrl: $baseUrl,
                requestOptions: $requestOptions,
            ),
            default => BedrockClient::fromEnvironment($region, $baseUrl, $requestOptions),
        };
    }

    /**
     * Client middleware runs before the SDK's Bedrock middleware, which only
     * fills in `anthropic_version` when the body has none. Decoded as objects
     * so empty JSON objects such as a tool's `{}` input survive the round trip.
     *
     * @param \Closure(RequestInterface): ResponseInterface $next
     */
    private static function pinAnthropicVersion(RequestInterface $request, \Closure $next): ResponseInterface {
        $body = json_decode((string)$request->getBody(), false);
        if (!$body instanceof \stdClass) {
            return $next($request);
        }
        $body->anthropic_version = self::ANTHROPIC_VERSION;
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $next($request->withBody(Stream::create($json))->withHeader('Content-Length', (string)strlen($json)));
    }
}
