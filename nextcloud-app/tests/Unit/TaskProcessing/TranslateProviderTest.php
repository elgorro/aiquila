<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCA\AIquila\TaskProcessing\ProviderResolver;
use OCA\AIquila\TaskProcessing\TranslateProvider;
use PHPUnit\Framework\TestCase;

/**
 * Representative of the nine plain text-to-text providers: they share one
 * resolve()->ask() shape, so what is asserted here holds for all of them.
 */
class TranslateProviderTest extends TestCase {
    private $factory;
    private TranslateProvider $provider;

    protected function setUp(): void {
        $this->factory = $this->createMock(LLMProviderFactory::class);
        $this->provider = new TranslateProvider(new ProviderResolver($this->factory));
    }

    public function testIdAndTaskTypeAreUnchangedByTheRename(): void {
        $this->assertSame('aiquila:text2text:translate', $this->provider->getId());
        $this->assertSame('core:text2text:translate', $this->provider->getTaskTypeId());
        $this->assertSame('AIquila', $this->provider->getName());
    }

    public function testRunsOnTheUsersProviderRatherThanAnthropic(): void {
        $local = $this->createMock(LLMProviderInterface::class);
        $local->expects($this->once())
            ->method('ask')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('from German to French'),
                    $this->stringContains('Guten Tag'),
                ),
                '',
                'alice',
            )
            ->willReturn(['response' => 'Bonjour']);

        $this->factory->expects($this->once())
            ->method('getProviderForUser')
            ->with('alice', null)
            ->willReturn($local);

        $result = $this->provider->process('alice', [
            'input' => 'Guten Tag',
            'origin_language' => 'German',
            'target_language' => 'French',
        ], static fn (float $p) => null);

        $this->assertSame(['output' => 'Bonjour'], $result);
    }

    public function testOriginLanguageIsOptional(): void {
        $local = $this->createMock(LLMProviderInterface::class);
        $local->method('ask')
            ->with($this->logicalNot($this->stringContains(' from ')))
            ->willReturn(['response' => 'Bonjour']);
        $this->factory->method('getProviderForUser')->willReturn($local);

        $result = $this->provider->process('alice', [
            'input' => 'Guten Tag',
            'target_language' => 'French',
        ], static fn (float $p) => null);

        $this->assertSame(['output' => 'Bonjour'], $result);
    }

    public function testNoPermittedProviderIsReportedToTheUser(): void {
        $this->factory->method('getProviderForUser')
            ->willThrowException(new NoPermittedProviderException('alice'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(NoPermittedProviderException::USER_MESSAGE);
        $this->provider->process('alice', [
            'input' => 'Guten Tag',
            'target_language' => 'French',
        ], static fn (float $p) => null);
    }

    public function testMissingTargetLanguageIsRejected(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No target language provided');
        $this->provider->process('alice', ['input' => 'Guten Tag'], static fn (float $p) => null);
    }
}
