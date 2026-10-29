<?php

namespace OCA\AIquila\Tests\Unit\Cloud;

use OCA\AIquila\Service\Provider\Cloud\CloudEndpoints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint parts end up in a hostname or URL path, so anything that could
 * move the request to another host must be refused.
 */
class CloudEndpointsTest extends TestCase {
    public static function hostInjections(): array {
        return [
            'dotted host' => ['us-east-1.evil.example'],
            'path' => ['us-east-1/x'],
            'userinfo' => ['x@evil.example'],
            'fragment' => ['us-east-1#'],
            'trailing newline' => ["us-east-1\n"],
            'port' => ['us-east-1:443'],
            'empty' => [''],
            'url' => ['https://evil.example'],
        ];
    }

    #[DataProvider('hostInjections')]
    public function testAwsRegionRejectsHostInjection(string $value): void {
        $this->expectException(\InvalidArgumentException::class);
        CloudEndpoints::bedrockMantleUrl($value);
    }

    #[DataProvider('hostInjections')]
    public function testGcpLocationRejectsHostInjection(string $value): void {
        $this->expectException(\InvalidArgumentException::class);
        CloudEndpoints::vertexUrl($value);
    }

    #[DataProvider('hostInjections')]
    public function testAzureResourceRejectsHostInjection(string $value): void {
        $this->expectException(\InvalidArgumentException::class);
        CloudEndpoints::foundryUrl($value);
    }

    public function testValidValuesBuildThePlatformUrls(): void {
        $this->assertSame('https://bedrock-mantle.eu-central-1.api.aws/anthropic', CloudEndpoints::bedrockMantleUrl('eu-central-1'));
        $this->assertSame('https://bedrock-runtime.us-gov-west-1.amazonaws.com', CloudEndpoints::bedrockRuntimeUrl('us-gov-west-1'));
        $this->assertSame('https://aws-external-anthropic.us-west-2.api.aws', CloudEndpoints::claudePlatformAwsUrl('us-west-2'));
        $this->assertSame('https://aiplatform.googleapis.com/v1', CloudEndpoints::vertexUrl('global'));
        $this->assertSame('https://aiplatform.eu.rep.googleapis.com/v1', CloudEndpoints::vertexUrl('eu'));
        $this->assertSame('https://us-east5-aiplatform.googleapis.com/v1', CloudEndpoints::vertexUrl('us-east5'));
        $this->assertSame('https://my-res.services.ai.azure.com/anthropic/', CloudEndpoints::foundryUrl('my-res'));
        $this->assertSame(
            'https://claude.googleapis.com/v1/projects/my-project-1/locations/global/workspaces/wrkspc_01Ab/invoke',
            CloudEndpoints::claudePlatformGoogleCloudUrl('my-project-1', 'global', 'wrkspc_01Ab'),
        );
    }

    public function testRequireAwsRegionAcceptsEveryPublicPartition(): void {
        foreach (['us-east-1', 'us-gov-west-1', 'cn-north-1', 'eusc-de-east-1'] as $region) {
            $this->assertSame($region, CloudEndpoints::requireAwsRegion($region));
        }
    }

    public static function awsRegionsOutsideThePublicPartitions(): array {
        return [
            'no such partition' => ['xx-east-1'],
            'iso partition' => ['us-iso-east-1'],
            'iso-e partition' => ['eu-isoe-west-1'],
            'eusc outside de' => ['eusc-fr-east-1'],
        ];
    }

    #[DataProvider('awsRegionsOutsideThePublicPartitions')]
    public function testAwsRegionOutsideThePublicPartitionsIsRejected(string $region): void {
        $this->expectException(\InvalidArgumentException::class);
        CloudEndpoints::requireAwsRegion($region);
    }

    public static function awsPartitionUrls(): array {
        return [
            'eusc runtime' => ['bedrockRuntimeUrl', 'eusc-de-east-1', 'https://bedrock-runtime.eusc-de-east-1.amazonaws.eu'],
            'eusc mantle' => ['bedrockMantleUrl', 'eusc-de-east-1', 'https://bedrock-mantle.eusc-de-east-1.api.amazonwebservices.eu/anthropic'],
            'china runtime' => ['bedrockRuntimeUrl', 'cn-north-1', 'https://bedrock-runtime.cn-north-1.amazonaws.com.cn'],
            'china mantle' => ['bedrockMantleUrl', 'cn-northwest-1', 'https://bedrock-mantle.cn-northwest-1.api.amazonwebservices.com.cn/anthropic'],
            'govcloud mantle' => ['bedrockMantleUrl', 'us-gov-west-1', 'https://bedrock-mantle.us-gov-west-1.api.aws/anthropic'],
        ];
    }

    #[DataProvider('awsPartitionUrls')]
    public function testAwsUrlsUseThePartitionDnsSuffix(string $builder, string $region, string $expected): void {
        $this->assertSame($expected, [CloudEndpoints::class, $builder]($region));
    }

    public static function nonCommercialAwsRegions(): array {
        return [
            'govcloud' => ['us-gov-west-1'],
            'china' => ['cn-north-1'],
            'eusc' => ['eusc-de-east-1'],
        ];
    }

    /** Anthropic documents Claude Platform on AWS for the commercial regions only. */
    #[DataProvider('nonCommercialAwsRegions')]
    public function testClaudePlatformAwsRejectsNonCommercialPartitions(string $region): void {
        $this->expectException(\InvalidArgumentException::class);
        CloudEndpoints::claudePlatformAwsUrl($region);
    }

    public function testGcpProjectAndWorkspaceAreChecked(): void {
        foreach (['ab', 'Upper-Case', 'my-project/../x', 'p@q'] as $bad) {
            try {
                CloudEndpoints::requireGcpProject($bad);
                $this->fail('accepted project ' . $bad);
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        CloudEndpoints::requireWorkspaceId('wrkspc_x/../other');
    }

    public function testModelIdsAcceptPlatformSpellingsOnly(): void {
        foreach ([
            'anthropic.claude-opus-5-5',
            'global.anthropic.claude-sonnet-5',
            'anthropic.claude-haiku-4-5-20251001-v1:0',
            'arn:aws:bedrock:us-east-1:123456789012:inference-profile/eu.anthropic.claude-sonnet-5',
            'claude-haiku-4-5@20251001',
            'my-claude-deployment',
        ] as $ok) {
            $this->assertSame($ok, CloudEndpoints::requireModelId($ok));
        }
        foreach (['../x', 'a..b', "claude\n", '', ' claude', 'claude?x=1', 'claude#x'] as $bad) {
            try {
                CloudEndpoints::requireModelId($bad);
                $this->fail('accepted model ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
