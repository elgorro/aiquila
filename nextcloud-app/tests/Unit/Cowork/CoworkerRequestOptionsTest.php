<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Cowork;

use OCA\AIquila\Cowork\CoworkerRequestOptions;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use PHPUnit\Framework\TestCase;

class CoworkerRequestOptionsTest extends TestCase {
    public function testSystemIsOnlySetWhenInstructionsApply(): void {
        $coworker = new Coworker();
        $provider = $this->createStub(LLMProviderInterface::class);

        $this->assertArrayNotHasKey('system', CoworkerRequestOptions::build($coworker, $provider, []));
        $this->assertArrayNotHasKey('system', CoworkerRequestOptions::build($coworker, $provider, [], ''));
        $this->assertSame('We are ACME.', CoworkerRequestOptions::build($coworker, $provider, [], 'We are ACME.')['system']);
    }
}
