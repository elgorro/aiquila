<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use Anthropic\Core\BaseClient;
use Anthropic\Foundry\Client as FoundryClient;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;

/**
 * Claude in Microsoft Foundry: `https://{resource}.services.ai.azure.com/anthropic`,
 * authenticated with an Azure-issued key or a Microsoft Entra ID token.
 *
 * The `model` sent is the deployment name, which the admin may have chosen
 * freely. When it is not a model ID, the "Base model" setting says which model
 * is behind it so thinking, effort and the output ceiling are resolved for the
 * right one.
 *
 * Foundry has no Batches or Models API, and the SDK's Foundry client exposes
 * only the Messages endpoint, so the MCP connector and the Files API are off
 * even on deployments hosted on Anthropic.
 */
class FoundryProvider extends AbstractCloudClaudeProvider {
    public function getId(): string {
        return 'foundry';
    }

    public function getLabel(): string {
        return 'Claude in Microsoft Foundry';
    }

    protected function defaultModel(): string {
        return 'claude-sonnet-5';
    }

    protected function modelDescription(): string {
        return 'The deployment name. It defaults to the model ID (e.g. claude-opus-5-5) when the deployment is created; if you chose another name, set the base model below.';
    }

    protected function apiKeyDescription(): string {
        return 'The Key shown on the deployment\'s Details tab in the Foundry portal. Stored encrypted in Nextcloud\'s credential manager.';
    }

    protected function authModes(): array {
        return [self::AUTH_API_KEY, self::AUTH_ACCESS_TOKEN];
    }

    protected function endpointFields(): array {
        return [
            $this->endpointField(
                'resource',
                'Foundry resource name',
                'The {resource} in https://{resource}.services.ai.azure.com — the name only, not the URL.',
                ProviderSettingsSchema::FORMAT_AZURE_RESOURCE,
                'my-foundry-resource',
            ),
        ];
    }

    protected function endpointConfigured(): bool {
        return $this->hasValidEndpointValue('resource', CloudEndpoints::requireAzureResource(...));
    }

    public function getSettingsSchema(): array {
        $fields = parent::getSettingsSchema();
        $baseModel = ProviderSettingsSchema::select(
            'base_model',
            $this->configKey('base_model'),
            'Base model',
            'Which model the deployment serves, for when its name is not the model ID. Blank reads the model from the deployment name.',
            array_merge([''], ClaudeModels::getAllModels()),
            group: ProviderSettingsSchema::GROUP_BASIC,
        );
        // Right after the model field it qualifies.
        $at = array_search('model', array_column($fields, 'id'), true);
        array_splice($fields, $at === false ? count($fields) : (int)$at + 1, 0, [$baseModel]);
        return $fields;
    }

    protected function capabilityModel(string $model): string {
        $base = $this->appValue('base_model');
        return $base !== '' && in_array($base, ClaudeModels::getAllModels(), true) ? $base : $model;
    }

    protected function buildClient(string $authMode): BaseClient {
        $baseUrl = CloudEndpoints::foundryUrl($this->endpointValue('resource', CloudEndpoints::requireAzureResource(...)));

        // `resource: ''` keeps the SDK from reading ANTHROPIC_FOUNDRY_RESOURCE,
        // which would clash with the explicit base URL.
        return $authMode === self::AUTH_ACCESS_TOKEN
            ? FoundryClient::withCredentials(authToken: $this->requiredSecret('access_token'), baseUrl: $baseUrl, resource: '')
            : FoundryClient::withCredentials(apiKey: $this->requiredApiKey(), baseUrl: $baseUrl, resource: '');
    }
}
