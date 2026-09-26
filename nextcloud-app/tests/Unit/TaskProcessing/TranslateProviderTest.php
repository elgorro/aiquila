<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCA\AIquila\TaskProcessing\ProviderResolver;
use OCA\AIquila\TaskProcessing\TranslateProvider;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\TaskProcessing\ShapeEnumValue;
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
        $l10n = $this->createStub(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $l10nFactory = $this->createStub(IFactory::class);
        $l10nFactory->method('getLanguages')->willReturn([
            'commonLanguages' => [
                ['code' => 'en', 'name' => 'English (US)'],
                ['code' => 'de', 'name' => 'Deutsch'],
            ],
            'otherLanguages' => [
                ['code' => 'fr', 'name' => 'Français'],
                ['code' => 'de', 'name' => 'Deutsch (duplicate)'],
            ],
        ]);
        $this->provider = new TranslateProvider(new ProviderResolver($this->factory), $l10nFactory, $l10n);
    }

    /** @param list<ShapeEnumValue> $values */
    private static function values(array $values): array {
        return array_map(static fn (ShapeEnumValue $v) => $v->getValue(), $values);
    }

    public function testLanguagesComeFromTheServerWithDetectOnlyAsOrigin(): void {
        $enums = $this->provider->getInputShapeEnumValues();

        // The Assistant takes origin_language[0] as its default when none is set.
        $this->assertSame(['detect_language', 'en', 'de', 'fr'], self::values($enums['origin_language']));
        $this->assertSame(['en', 'de', 'fr'], self::values($enums['target_language']));
        $this->assertSame('Deutsch', $enums['target_language'][1]->getName());
    }

    public function testOriginDefaultsToDetection(): void {
        $this->assertSame(['origin_language' => 'detect_language'], $this->provider->getInputShapeDefaults());
    }

    public function testLanguageCodesReachThePromptAsNames(): void {
        $local = $this->createMock(LLMProviderInterface::class);
        $local->expects($this->once())
            ->method('ask')
            ->with($this->stringContains('from Deutsch to Français'))
            ->willReturn(['response' => 'Bonjour']);
        $this->factory->method('getProviderForUser')->willReturn($local);

        $this->provider->process('alice', [
            'input' => 'Guten Tag',
            'origin_language' => 'de',
            'target_language' => 'fr',
        ], static fn (float $p) => null);
    }

    public function testDetectLanguageLeavesTheSourceToTheModel(): void {
        $local = $this->createMock(LLMProviderInterface::class);
        $local->expects($this->once())
            ->method('ask')
            ->with($this->logicalAnd(
                $this->logicalNot($this->stringContains(' from ')),
                $this->logicalNot($this->stringContains('detect_language')),
                $this->stringContains('to Français'),
            ))
            ->willReturn(['response' => 'Bonjour']);
        $this->factory->method('getProviderForUser')->willReturn($local);

        $this->provider->process('alice', [
            'input' => 'Guten Tag',
            'origin_language' => 'detect_language',
            'target_language' => 'fr',
        ], static fn (float $p) => null);
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
