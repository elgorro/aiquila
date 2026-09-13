<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\TaskProcessing\ChatProvider;
use OCA\AIquila\TaskProcessing\ContextWriteProvider;
use OCA\AIquila\TaskProcessing\GenerateEmojiProvider;
use OCA\AIquila\TaskProcessing\ProviderResolver;
use OCA\AIquila\TaskProcessing\ReformatParagraphsProvider;
use OCP\TaskProcessing\TaskTypes\ContextWrite;
use OCP\TaskProcessing\TaskTypes\GenerateEmoji;
use OCP\TaskProcessing\TaskTypes\TextToTextChat;
use PHPUnit\Framework\TestCase;

/**
 * The four task types added on top of the original text-to-text set.
 */
class TextProvidersTest extends TestCase {
    private $factory;
    private $llm;
    private ProviderResolver $resolver;

    protected function setUp(): void {
        $this->factory = $this->createMock(LLMProviderFactory::class);
        $this->llm = $this->createMock(LLMProviderInterface::class);
        $this->factory->method('getProviderForUser')->willReturn($this->llm);
        $this->resolver = new ProviderResolver($this->factory);
    }

    public function testTaskTypeIdsMatchNextcloud(): void {
        $this->assertSame(TextToTextChat::ID, (new ChatProvider($this->resolver))->getTaskTypeId());
        $this->assertSame(GenerateEmoji::ID, (new GenerateEmojiProvider($this->resolver))->getTaskTypeId());
        $this->assertSame(ContextWrite::ID, (new ContextWriteProvider($this->resolver))->getTaskTypeId());
        // Nextcloud 34+; spelled out because the OCP class is absent on 33.
        $this->assertSame(
            'core:text2text:reformatparagraphs',
            (new ReformatParagraphsProvider($this->resolver))->getTaskTypeId()
        );
    }

    public function testEveryProviderOffersAProviderOverride(): void {
        foreach ([ChatProvider::class, GenerateEmojiProvider::class, ContextWriteProvider::class, ReformatParagraphsProvider::class] as $class) {
            $provider = new $class($this->resolver);
            $this->assertArrayHasKey('provider', $provider->getOptionalInputShape(), $class);
        }
    }

    public function testChatMapsHistoryToAlternatingRoles(): void {
        $this->llm->expects($this->once())
            ->method('chat')
            ->with(
                [
                    ['role' => 'user', 'content' => 'Hi'],
                    ['role' => 'assistant', 'content' => 'Hello'],
                    ['role' => 'user', 'content' => 'And now?'],
                ],
                'Be terse.',
                'alice',
            )
            ->willReturn(['response' => 'Now this.']);

        $result = (new ChatProvider($this->resolver))->process('alice', [
            'system_prompt' => 'Be terse.',
            'input' => 'And now?',
            'history' => ['Hi', 'Hello'],
        ], static fn (float $p) => null);

        $this->assertSame(['output' => 'Now this.'], $result);
    }

    public function testChatWithoutHistoryOrSystemPromptSendsOnlyTheMessage(): void {
        $this->llm->expects($this->once())
            ->method('chat')
            ->with([['role' => 'user', 'content' => 'Hi']], null, 'alice')
            ->willReturn(['response' => 'Hello']);

        $result = (new ChatProvider($this->resolver))->process('alice', [
            'input' => 'Hi',
        ], static fn (float $p) => null);

        $this->assertSame(['output' => 'Hello'], $result);
    }

    public function testChatRejectsAnEmptyMessage(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No chat message provided');
        (new ChatProvider($this->resolver))->process('alice', ['input' => ''], static fn (float $p) => null);
    }

    public function testEmojiIsTrimmed(): void {
        $this->llm->method('ask')->willReturn(['response' => "  🎉\n"]);

        $result = (new GenerateEmojiProvider($this->resolver))->process('alice', [
            'input' => 'Release day',
        ], static fn (float $p) => null);

        $this->assertSame(['output' => '🎉'], $result);
    }

    public function testContextWriteSendsBothStyleAndSubject(): void {
        $this->llm->expects($this->once())
            ->method('ask')
            ->with(
                $this->logicalAnd($this->stringContains('a formal memo'), $this->stringContains('the office move')),
                '',
                'alice',
            )
            ->willReturn(['response' => 'Dear all, ...']);

        $result = (new ContextWriteProvider($this->resolver))->process('alice', [
            'style_input' => 'a formal memo',
            'source_input' => 'the office move',
        ], static fn (float $p) => null);

        $this->assertSame(['output' => 'Dear all, ...'], $result);
    }

    public function testContextWriteNeedsBothInputs(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No source material provided');
        (new ContextWriteProvider($this->resolver))->process('alice', [
            'style_input' => 'a formal memo',
        ], static fn (float $p) => null);
    }

    public function testReformatParagraphsPropagatesProviderErrors(): void {
        $this->llm->method('ask')->willReturn(['error' => 'rate limited']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rate limited');
        (new ReformatParagraphsProvider($this->resolver))->process('alice', [
            'input' => 'one long wall of text',
        ], static fn (float $p) => null);
    }

    public function testProviderOverrideReachesTheFactory(): void {
        $factory = $this->createMock(LLMProviderFactory::class);
        $factory->expects($this->once())
            ->method('getProviderForUser')
            ->with('alice', 'mistral')
            ->willReturn($this->llm);
        $this->llm->method('ask')->willReturn(['response' => 'ok']);

        (new ReformatParagraphsProvider(new ProviderResolver($factory)))->process('alice', [
            'input' => 'text',
            'provider' => 'mistral',
        ], static fn (float $p) => null);
    }
}
