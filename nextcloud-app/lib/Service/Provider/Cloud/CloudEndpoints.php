<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

/**
 * Validation and URL construction for the cloud platforms' endpoints.
 *
 * Every cloud SDK client derives its host from a value an admin types in — an
 * AWS region, a Google Cloud location and project, an Azure resource name. A
 * crafted value (`us-east-1.evil.example/x`) would send the platform
 * credentials, bearer tokens or SigV4-signed requests to a host of the
 * attacker's choosing, which is the same SSRF class LocalProvider guards
 * against for `local_base_url`. So the values are checked here, against the
 * shapes the platforms actually issue, both when the settings page stores them
 * (ProviderSettingsService) and again when a client is built from whatever is
 * in the config — occ writes bypass the settings API.
 *
 * Base URLs are always built here and handed to the SDK explicitly, so the
 * SDK's own `ANTHROPIC_*_BASE_URL` environment fallbacks never take part.
 */
final class CloudEndpoints {
    /** e.g. us-east-1, eu-central-2, us-gov-west-1, ap-southeast-4 */
    private const AWS_REGION = '/^[a-z]{2}(?:-[a-z]+)+-\d{1,2}$/D';
    /** global, us, eu, or a region such as us-east5 / europe-west1 */
    private const GCP_LOCATION = '/^(?:global|us|eu|[a-z]+-[a-z]+\d{1,2})$/D';
    /** Project IDs: 6–30 chars, lowercase, digits, hyphens; optional legacy domain prefix. */
    private const GCP_PROJECT = '/^(?:[a-z0-9][a-z0-9.-]{0,62}:)?[a-z][a-z0-9-]{4,28}[a-z0-9]$/D';
    /** Azure resource names: letters, digits and hyphens, 2–64 chars, no leading/trailing hyphen. */
    private const AZURE_RESOURCE = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?$/D';
    /** Claude Console workspace IDs. */
    private const WORKSPACE_ID = '/^wrkspc_[A-Za-z0-9]{1,64}$/D';
    /**
     * Model IDs, inference profiles, Foundry deployment names and Bedrock ARNs.
     * The SDK URL-encodes the model where it goes into a path; this keeps the
     * value to the characters any of the platforms issue anyway.
     */
    private const MODEL_ID = '/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,255}$/D';

    /**
     * @return non-empty-string
     * @throws \InvalidArgumentException
     */
    public static function requireAwsRegion(string $value): string {
        return self::require($value, self::AWS_REGION, 'enter an AWS region such as us-east-1.');
    }

    /**
     * @return non-empty-string
     * @throws \InvalidArgumentException
     */
    public static function requireGcpLocation(string $value): string {
        return self::require($value, self::GCP_LOCATION, 'enter global, us, eu or a region such as us-east5.');
    }

    /**
     * @return non-empty-string
     * @throws \InvalidArgumentException
     */
    public static function requireGcpProject(string $value): string {
        return self::require($value, self::GCP_PROJECT, 'enter a Google Cloud project ID such as my-project-123.');
    }

    /**
     * @return non-empty-string
     * @throws \InvalidArgumentException
     */
    public static function requireAzureResource(string $value): string {
        return self::require($value, self::AZURE_RESOURCE, 'enter the Foundry resource name only, not a URL.');
    }

    /**
     * @return non-empty-string
     * @throws \InvalidArgumentException
     */
    public static function requireWorkspaceId(string $value): string {
        return self::require($value, self::WORKSPACE_ID, 'enter a workspace ID starting with wrkspc_.');
    }

    /**
     * @return non-empty-string
     * @throws \InvalidArgumentException
     */
    public static function requireModelId(string $value): string {
        if (str_contains($value, '..')) {
            throw new \InvalidArgumentException('"' . $value . '" is not a valid model ID.');
        }
        return self::require($value, self::MODEL_ID, 'not a valid model ID.');
    }

    /** @return non-empty-string */
    public static function bedrockRuntimeUrl(string $region): string {
        return 'https://bedrock-runtime.' . self::requireAwsRegion($region) . '.amazonaws.com';
    }

    /** @return non-empty-string */
    public static function bedrockMantleUrl(string $region): string {
        return 'https://bedrock-mantle.' . self::requireAwsRegion($region) . '.api.aws/anthropic';
    }

    /** @return non-empty-string */
    public static function claudePlatformAwsUrl(string $region): string {
        return 'https://aws-external-anthropic.' . self::requireAwsRegion($region) . '.api.aws';
    }

    /** @return non-empty-string */
    public static function vertexUrl(string $location): string {
        $location = self::requireGcpLocation($location);
        return match ($location) {
            'global' => 'https://aiplatform.googleapis.com/v1',
            'us' => 'https://aiplatform.us.rep.googleapis.com/v1',
            'eu' => 'https://aiplatform.eu.rep.googleapis.com/v1',
            default => 'https://' . $location . '-aiplatform.googleapis.com/v1',
        };
    }

    /** @return non-empty-string */
    public static function claudePlatformGoogleCloudUrl(string $project, string $location, string $workspaceId): string {
        return 'https://claude.googleapis.com/v1/projects/' . self::requireGcpProject($project)
            . '/locations/' . self::requireGcpLocation($location)
            . '/workspaces/' . self::requireWorkspaceId($workspaceId) . '/invoke';
    }

    /** @return non-empty-string */
    public static function foundryUrl(string $resource): string {
        return 'https://' . self::requireAzureResource($resource) . '.services.ai.azure.com/anthropic/';
    }

    /**
     * @param non-empty-string $pattern
     * @return non-empty-string
     * @throws \InvalidArgumentException
     */
    private static function require(string $value, string $pattern, string $hint): string {
        if ($value === '' || preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException('"' . $value . '" — ' . $hint);
        }
        return $value;
    }
}
