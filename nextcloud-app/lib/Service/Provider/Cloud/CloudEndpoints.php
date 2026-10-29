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
    /**
     * The public AWS partitions as region pattern, DNS suffix and dual-stack
     * DNS suffix, from botocore/data/partitions.json: the table the AWS SDKs'
     * aws.partition() endpoint function resolves against. A region is valid
     * only if it belongs to one of them, and its hosts take that partition's
     * suffixes, so China and the European Sovereign Cloud get amazonaws.com.cn
     * and amazonaws.eu rather than a commercial host that does not serve them.
     *
     * Patterns are upstream's verbatim apart from /D, without which $ would
     * also match before a trailing newline. The ISO partitions are left out.
     */
    private const AWS_PARTITIONS = [
        'aws' => ['/^(us|eu|ap|sa|ca|me|af|il|mx)\-\w+\-\d+$/D', 'amazonaws.com', 'api.aws'],
        'aws-us-gov' => ['/^us\-gov\-\w+\-\d+$/D', 'amazonaws.com', 'api.aws'],
        'aws-cn' => ['/^cn\-\w+\-\d+$/D', 'amazonaws.com.cn', 'api.amazonwebservices.com.cn'],
        'aws-eusc' => ['/^eusc\-(de)\-\w+\-\d+$/D', 'amazonaws.eu', 'api.amazonwebservices.eu'],
    ];
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
        self::awsPartition($value);
        return $value;
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
        [, $dnsSuffix] = self::awsPartition($region);
        return 'https://bedrock-runtime.' . $region . '.' . $dnsSuffix;
    }

    /**
     * Mantle sits on the dual-stack suffix: api.aws in the commercial partition,
     * api.amazonwebservices.eu in the European Sovereign Cloud.
     *
     * @return non-empty-string
     */
    public static function bedrockMantleUrl(string $region): string {
        [, , $dualStackDnsSuffix] = self::awsPartition($region);
        return 'https://bedrock-mantle.' . $region . '.' . $dualStackDnsSuffix . '/anthropic';
    }

    /**
     * Anthropic documents Claude Platform on AWS for the commercial regions
     * only, so other partitions are refused instead of guessing a host there.
     *
     * @return non-empty-string
     * @throws \InvalidArgumentException
     */
    public static function claudePlatformAwsUrl(string $region): string {
        [$partition, , $dualStackDnsSuffix] = self::awsPartition($region);
        if ($partition !== 'aws') {
            throw new \InvalidArgumentException('"' . $region . '": Claude Platform on AWS is only offered in the commercial AWS regions, not in ' . $partition . '.');
        }
        return 'https://aws-external-anthropic.' . $region . '.' . $dualStackDnsSuffix;
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
     * @return array{0: non-empty-string, 1: non-empty-string, 2: non-empty-string} partition, DNS suffix, dual-stack DNS suffix
     * @psalm-assert non-empty-string $region
     * @throws \InvalidArgumentException
     */
    private static function awsPartition(string $region): array {
        foreach (self::AWS_PARTITIONS as $partition => [$pattern, $dnsSuffix, $dualStackDnsSuffix]) {
            if (preg_match($pattern, $region) === 1) {
                return [$partition, $dnsSuffix, $dualStackDnsSuffix];
            }
        }
        throw new \InvalidArgumentException('"' . $region . '": enter an AWS region such as us-east-1.');
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
