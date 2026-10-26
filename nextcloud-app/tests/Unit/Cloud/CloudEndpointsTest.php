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
