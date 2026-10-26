<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use Anthropic\Aws\Client as AwsClient;
use Anthropic\Core\BaseClient;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;

/**
 * Claude Platform on AWS: the Claude API operated by Anthropic, billed and
 * authorised through AWS, at `https://aws-external-anthropic.{region}.api.aws`.
 *
 * Model IDs are the first-party ones and the platform tracks the Claude API
 * closely, so Batches, the Models API and the MCP connector are on. Fast mode
 * is documented as unavailable. Files stay off: uploads go through the Claude
 * API provider's key (FilesService), which this workspace cannot read.
 */
class ClaudePlatformAwsProvider extends AbstractAwsClaudeProvider {
    public function getId(): string {
        return 'claude_aws';
    }

    public function getLabel(): string {
        return 'Claude Platform on AWS';
    }

    protected function defaultModel(): string {
        return ClaudeModels::DEFAULT_MODEL;
    }

    protected function modelDescription(): string {
        return 'The same model IDs as the Claude API, e.g. claude-sonnet-5.';
    }

    protected function apiKeyDescription(): string {
        return 'Created in the AWS console under Claude Platform on AWS → API keys; keys from the Claude Console do not work here. Stored encrypted in Nextcloud\'s credential manager.';
    }

    protected function platformFeatures(): array {
        return [
            self::FEATURE_BATCHES => true,
            self::FEATURE_MODELS_API => true,
            self::FEATURE_NATIVE_MCP => true,
            self::FEATURE_METADATA => true,
        ] + parent::platformFeatures();
    }

    protected function endpointFields(): array {
        $fields = parent::endpointFields();
        $fields[] = $this->endpointField(
            'workspace_id',
            'Workspace ID',
            'The wrkspc_… ID of the workspace requests run in. Its region must match the AWS region above.',
            ProviderSettingsSchema::FORMAT_WORKSPACE_ID,
            'wrkspc_…',
        );
        return $fields;
    }

    protected function endpointConfigured(): bool {
        return parent::endpointConfigured()
            && $this->hasValidEndpointValue('workspace_id', CloudEndpoints::requireWorkspaceId(...));
    }

    protected function buildClient(string $authMode): BaseClient {
        $region = $this->region();
        $baseUrl = CloudEndpoints::claudePlatformAwsUrl($region);
        $workspaceId = $this->endpointValue('workspace_id', CloudEndpoints::requireWorkspaceId(...));

        return match ($authMode) {
            self::AUTH_API_KEY => new AwsClient(apiKey: $this->requiredApiKey(), awsRegion: $region, workspaceId: $workspaceId, baseUrl: $baseUrl),
            self::AUTH_ACCESS_KEYS => new AwsClient(
                awsAccessKey: $this->awsAccessKeyId(),
                awsSecretAccessKey: $this->requiredSecret('aws_secret_access_key'),
                awsSessionToken: $this->optionalSecret('aws_session_token'),
                awsRegion: $region,
                workspaceId: $workspaceId,
                baseUrl: $baseUrl,
            ),
            default => new AwsClient(awsRegion: $region, workspaceId: $workspaceId, baseUrl: $baseUrl),
        };
    }
}
