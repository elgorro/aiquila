<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use Anthropic\Bedrock\Client as BedrockClient;
use Anthropic\Core\BaseClient;

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

        return match ($authMode) {
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
}
