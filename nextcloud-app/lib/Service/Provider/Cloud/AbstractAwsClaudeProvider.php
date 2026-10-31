<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use OCA\AIquila\Service\Provider\ProviderSettingsSchema;

/**
 * The AWS-hosted platforms share a region and three ways to authenticate: a
 * platform-issued bearer key, explicit access keys signed with SigV4, or the
 * AWS default credential chain (env, shared profile, ECS task role, EC2
 * instance role). Only the bearer key works without aws/aws-sdk-php.
 */
abstract class AbstractAwsClaudeProvider extends AbstractCloudClaudeProvider {
    protected function authModes(): array {
        return [self::AUTH_API_KEY, self::AUTH_ACCESS_KEYS, self::AUTH_DEFAULT_CHAIN];
    }

    protected function endpointFields(): array {
        return [
            $this->endpointField(
                'region',
                'AWS region',
                'The region whose endpoint Nextcloud calls, e.g. us-east-1 or eu-central-1. Cross-region routing is chosen through the model ID, not here.',
                ProviderSettingsSchema::FORMAT_AWS_REGION,
                'us-east-1',
            ),
        ];
    }

    protected function endpointConfigured(): bool {
        return $this->hasValidEndpointValue('region', CloudEndpoints::requireAwsRegion(...));
    }

    /** @return non-empty-string */
    protected function region(): string {
        return $this->endpointValue('region', CloudEndpoints::requireAwsRegion(...));
    }

    /** @return non-empty-string */
    protected function awsAccessKeyId(): string {
        $id = $this->appValue('aws_access_key_id');
        if ($id === '') {
            throw new \RuntimeException($this->unconfiguredMessage());
        }
        return $id;
    }

    /**
     * Mid-conversation system messages are GA on Amazon Bedrock and Claude
     * Platform on AWS, the two platforms behind this class.
     */
    protected function platformFeatures(): array {
        return [self::FEATURE_MID_CONVERSATION_SYSTEM => true] + parent::platformFeatures();
    }
}
