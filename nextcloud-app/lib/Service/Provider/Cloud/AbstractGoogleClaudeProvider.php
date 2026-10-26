<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use OCA\AIquila\Service\Provider\ProviderSettingsSchema;

/**
 * The Google Cloud platforms share a project, a location and Google
 * credentials: a service account key, Application Default Credentials (the
 * attached service account on GCE/GKE/Cloud Run, or GOOGLE_APPLICATION_CREDENTIALS),
 * or a pasted access token for testing. The first two need google/auth.
 */
abstract class AbstractGoogleClaudeProvider extends AbstractCloudClaudeProvider {
    protected function authModes(): array {
        return [self::AUTH_SERVICE_ACCOUNT, self::AUTH_ADC, self::AUTH_ACCESS_TOKEN];
    }

    protected function endpointFields(): array {
        return [
            $this->endpointField(
                'project',
                'Google Cloud project ID',
                'The project that has the platform enabled and is billed for it.',
                ProviderSettingsSchema::FORMAT_GCP_PROJECT,
                'my-project-123',
            ),
            $this->endpointField(
                'location',
                'Location',
                $this->locationDescription(),
                ProviderSettingsSchema::FORMAT_GCP_LOCATION,
                'global',
                'global',
            ),
        ];
    }

    abstract protected function locationDescription(): string;

    protected function endpointConfigured(): bool {
        return $this->hasValidEndpointValue('project', CloudEndpoints::requireGcpProject(...))
            && $this->hasValidEndpointValue('location', CloudEndpoints::requireGcpLocation(...), 'global');
    }

    /** @return non-empty-string */
    protected function project(): string {
        return $this->endpointValue('project', CloudEndpoints::requireGcpProject(...));
    }

    /** @return non-empty-string */
    protected function location(): string {
        return $this->endpointValue('location', CloudEndpoints::requireGcpLocation(...), 'global');
    }
}
