<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\TaskProcessing\ChangeToneProvider;
use OCA\AIquila\TaskProcessing\ProviderResolver;
use OCP\IL10N;
use OCP\TaskProcessing\ShapeEnumValue;
use PHPUnit\Framework\TestCase;

class ChangeToneProviderTest extends TestCase {
    private $factory;
    private ChangeToneProvider $provider;

    protected function setUp(): void {
        $this->factory = $this->createMock(LLMProviderFactory::class);
        $l10n = $this->createStub(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $this->provider = new ChangeToneProvider(new ProviderResolver($this->factory), $l10n);
    }

    public function testToneIsAnEnumWithFormalAsDefault(): void {
        $tones = array_map(
            static fn (ShapeEnumValue $v) => $v->getValue(),
            $this->provider->getInputShapeEnumValues()['tone'],
        );

        $this->assertContains('friendly', $tones);
        $this->assertSame(['tone' => 'formal'], $this->provider->getInputShapeDefaults());
        $this->assertContains('formal', $tones);
    }

    public function testChosenToneReachesThePrompt(): void {
        $local = $this->createMock(LLMProviderInterface::class);
        $local->expects($this->once())
            ->method('ask')
            ->with($this->stringContains('in a friendly tone'))
            ->willReturn(['response' => 'Hey there!']);
        $this->factory->method('getProviderForUser')->willReturn($local);

        $result = $this->provider->process('alice', [
            'input' => 'Greetings.',
            'tone' => 'friendly',
        ], static fn (float $p) => null);

        $this->assertSame(['output' => 'Hey there!'], $result);
    }
}
