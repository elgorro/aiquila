<?php

namespace OCA\AIquila\Tests\Unit\SetupCheck;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\SetupCheck\HetznerExperimentEnding;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class HetznerExperimentEndingTest extends TestCase {
    private function check(bool $hasToken): HetznerExperimentEnding {
        $credentials = $this->createMock(CredentialService::class);
        $credentials->expects($this->once())->method('hasApiKey')->with(null, 'hetzner')->willReturn($hasToken);
        $l10n = $this->createStub(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        return new HetznerExperimentEnding($credentials, $l10n);
    }

    public function testWarnsWhenATokenIsConfigured(): void {
        $result = $this->check(true)->run();

        $this->assertSame('warning', $result->getSeverity());
        $this->assertStringContainsString('8 December 2026', (string)$result->getDescription());
    }

    public function testStaysQuietWithoutAToken(): void {
        $this->assertSame('success', $this->check(false)->run()->getSeverity());
    }
}
