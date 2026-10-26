<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use Anthropic\Bedrock\MantleClient;
use Anthropic\Core\BaseClient;

/**
 * Claude in Amazon Bedrock: the Messages API at
 * `https://bedrock-mantle.{region}.api.aws/anthropic`, which serves Claude
 * Opus 4.7 and later with `anthropic.`-prefixed model IDs.
 *
 * Bedrock serves the Messages endpoint only — no Files, Batches or Models API,
 * no MCP connector, no fast mode — so attachments are always inlined and
 * summaries take the synchronous path.
 */
class BedrockProvider extends AbstractAwsClaudeProvider {
    public function getId(): string {
        return 'bedrock';
    }

    public function getLabel(): string {
        return 'Claude in Amazon Bedrock';
    }

    protected function defaultModel(): string {
        return 'anthropic.claude-sonnet-5';
    }

    protected function modelDescription(): string {
        return 'A Bedrock model ID such as anthropic.claude-opus-5-5, or an inference profile such as global.anthropic.claude-sonnet-5 or eu.anthropic.claude-sonnet-5.';
    }

    protected function apiKeyDescription(): string {
        return 'A Bedrock API key. Short-term keys expire within 12 hours, so use a long-term key scoped to bedrock:CallWithBearerToken, or the credential chain with a role. Stored encrypted in Nextcloud\'s credential manager.';
    }

    protected function buildClient(string $authMode): BaseClient {
        $region = $this->region();
        $baseUrl = CloudEndpoints::bedrockMantleUrl($region);

        return match ($authMode) {
            self::AUTH_API_KEY => new MantleClient(apiKey: $this->requiredApiKey(), awsRegion: $region, baseUrl: $baseUrl),
            self::AUTH_ACCESS_KEYS => new MantleClient(
                awsAccessKey: $this->awsAccessKeyId(),
                awsSecretAccessKey: $this->requiredSecret('aws_secret_access_key'),
                awsSessionToken: $this->optionalSecret('aws_session_token'),
                awsRegion: $region,
                baseUrl: $baseUrl,
            ),
            default => new MantleClient(awsRegion: $region, baseUrl: $baseUrl),
        };
    }
}
