<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use Anthropic\Core\BaseClient;
use Anthropic\Vertex\Client as VertexClient;

/**
 * Claude Models on Google Cloud (Google managed), the Vertex AI / Agent
 * Platform route: `rawPredict` on `aiplatform.googleapis.com`, with the model
 * in the URL and older snapshots spelled `claude-haiku-4-5@20251001`.
 *
 * Messages endpoint only, like Bedrock. Every auth mode needs google/auth —
 * even the pasted token, because the SDK wraps it in a google/auth interface.
 */
class VertexProvider extends AbstractGoogleClaudeProvider {
    public function getId(): string {
        return 'vertex';
    }

    public function getLabel(): string {
        return 'Claude on Vertex AI (Google managed)';
    }

    protected function defaultModel(): string {
        return 'claude-sonnet-5';
    }

    protected function modelDescription(): string {
        return 'A Vertex model ID such as claude-opus-5-5, or claude-haiku-4-5@20251001 for snapshots before Claude 4.6.';
    }

    protected function locationDescription(): string {
        return 'global (recommended), us or eu for a multi-region endpoint, or a region such as us-east5. Regional endpoints only serve Sonnet 4.6 and older.';
    }

    protected function requiredSdk(string $authMode): ?string {
        return CloudSdk::GOOGLE;
    }

    protected function buildClient(string $authMode): BaseClient {
        $location = $this->location();
        $project = $this->project();
        $baseUrl = CloudEndpoints::vertexUrl($location);

        return match ($authMode) {
            self::AUTH_ACCESS_TOKEN => VertexClient::withAccessToken($this->requiredSecret('access_token'), $location, $project, $baseUrl),
            self::AUTH_SERVICE_ACCOUNT => VertexClient::withCredentials($this->googleServiceAccountCredentials(), $location, $project, $baseUrl),
            default => VertexClient::fromEnvironment($location, $project, $baseUrl),
        };
    }
}
