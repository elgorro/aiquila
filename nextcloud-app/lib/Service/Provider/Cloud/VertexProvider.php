<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use Anthropic\Core\BaseClient;
use Anthropic\Vertex\Client as VertexClient;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\Provider\ModelCompatibility;

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

    /** Mid-conversation system messages are GA on Claude models on Google Cloud (Google managed). */
    protected function platformFeatures(): array {
        return [
            self::FEATURE_MID_CONVERSATION_SYSTEM => true,
            // Claude Models on Google Cloud (Google managed) takes task budgets.
            self::FEATURE_TASK_BUDGET => true,
            self::FEATURE_MID_CONVERSATION_TOOL_CHANGES => true,
        ] + parent::platformFeatures();
    }

    // ── Models ──────────────────────────────────────────────────────────────
    //
    // No listing: Model Garden's publishers.models.list (v1beta1) is the
    // global catalogue, not what this project has enabled in this location,
    // so a picker built from it would offer models that then fail. The id
    // stays free text and the probe confirms it with a one-token request.

    public function assessUnlistedModel(string $model): ?ModelCompatibility {
        if ($model === '' || ClaudeModels::isKnown($model)) {
            return null;
        }
        return ModelCompatibility::unverified(
            $model,
            '',
            'Vertex AI has no list of the models enabled in a project, and AIquila does not know this model, so its limits, thinking and effort are not checked. Test the connection to confirm it answers.',
        );
    }

    protected function modelNotFoundMessage(string $model): string {
        return sprintf(
            '%s isn\'t available in project %s at location %s. Enable it from its Model Garden card, or pick a location that offers it, such as global.',
            $model,
            $this->appValue('project'),
            $this->appValue('location', 'global'),
        );
    }

    protected function permissionDeniedMessage(): string {
        return 'Vertex AI refused the request (403). Check that the credentials may call Vertex AI on the project (for example with the Vertex AI User role) and that the model is enabled in Model Garden.';
    }
}
