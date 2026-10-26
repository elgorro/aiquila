<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use Anthropic\Core\BaseClient;
use Anthropic\GoogleCloud\Client as GoogleCloudClient;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;

/**
 * Claude Models on Google Cloud (Anthropic managed): the Claude API operated by
 * Anthropic and reached through `claude.googleapis.com` with Google
 * credentials. There are no API keys on this platform.
 *
 * First-party model IDs and close feature parity: Batches, the Models API, the
 * MCP connector and fast mode. Files stay off for the same reason as on
 * Claude Platform on AWS — uploads go through the Claude API provider's key.
 */
class ClaudePlatformGoogleCloudProvider extends AbstractGoogleClaudeProvider {
    public function getId(): string {
        return 'claude_gcp';
    }

    public function getLabel(): string {
        return 'Claude on Google Cloud (Anthropic managed)';
    }

    protected function defaultModel(): string {
        return ClaudeModels::DEFAULT_MODEL;
    }

    protected function modelDescription(): string {
        return 'The same model IDs as the Claude API, e.g. claude-sonnet-5.';
    }

    protected function locationDescription(): string {
        return 'Leave at global. Where inference runs is a separate setting of the Anthropic organisation, not of this location.';
    }

    protected function platformFeatures(): array {
        return [
            self::FEATURE_BATCHES => true,
            self::FEATURE_MODELS_API => true,
            self::FEATURE_NATIVE_MCP => true,
            self::FEATURE_FAST_MODE => true,
            self::FEATURE_METADATA => true,
        ] + parent::platformFeatures();
    }

    protected function endpointFields(): array {
        $fields = parent::endpointFields();
        $fields[] = $this->endpointField(
            'workspace_id',
            'Workspace ID',
            'The wrkspc_… ID from Settings → Workspaces in the Claude Console of the organisation this project created.',
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
        $project = $this->project();
        $location = $this->location();
        $workspaceId = $this->endpointValue('workspace_id', CloudEndpoints::requireWorkspaceId(...));
        $baseUrl = CloudEndpoints::claudePlatformGoogleCloudUrl($project, $location, $workspaceId);

        return match ($authMode) {
            // A static token needs no google/auth: the client takes a closure.
            self::AUTH_ACCESS_TOKEN => new GoogleCloudClient(
                project: $project,
                location: $location,
                workspaceId: $workspaceId,
                baseUrl: $baseUrl,
                tokenProvider: fn (): string => $this->requiredSecret('access_token'),
            ),
            self::AUTH_SERVICE_ACCOUNT => new GoogleCloudClient(
                project: $project,
                location: $location,
                workspaceId: $workspaceId,
                baseUrl: $baseUrl,
                googleCredentials: $this->googleServiceAccountCredentials(),
            ),
            default => new GoogleCloudClient(project: $project, location: $location, workspaceId: $workspaceId, baseUrl: $baseUrl),
        };
    }
}
