<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\TaskProcessing\MultimodalChatWithToolsProvider;
use OCA\AIquila\TaskProcessing\ProviderResolver;
use OCP\Files\File;
use OCP\TaskProcessing\TaskTypes\MultimodalChatWithTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\AIquila\Service\UsageStatService;

/**
 * core:text2text:multimodal-chatwithtools — chatwithtools plus input attachments.
 */
class MultimodalChatWithToolsProviderTest extends TestCase {
    private $factory;
    private $llm;
    private MultimodalChatWithToolsProvider $provider;

    protected function setUp(): void {
        $this->factory = $this->createStub(LLMProviderFactory::class);
        $this->factory->method('getProviderForUser')->willReturnCallback(fn () => $this->llm);
        $this->factory->method('getProviderIdsForUser')->willReturn([]);
        $this->provider = new MultimodalChatWithToolsProvider(
            new ProviderResolver($this->factory, $this->createStub(UsageStatService::class)),
            new ImageOptimizer($this->createStub(LoggerInterface::class)),
        );
    }

    /** @param array<string, bool> $capabilities */
    private function llmWith(array $capabilities): LLMProviderInterface {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->method('getCapabilities')->willReturn(ProviderSettingsSchema::capabilities($capabilities));
        $llm->method('getLabel')->willReturn('Test');
        return $llm;
    }

    private function file(string $name, string $mime, string $content): File {
        $file = $this->createStub(File::class);
        $file->method('getName')->willReturn($name);
        $file->method('getMimetype')->willReturn($mime);
        $file->method('getContent')->willReturn($content);
        $file->method('getSize')->willReturn(strlen($content));
        return $file;
    }

    public function testTaskTypeIdMatchesNextcloud(): void {
        $this->assertSame(MultimodalChatWithTools::ID, $this->provider->getTaskTypeId());
        $this->assertSame('aiquila:text2text:multimodal-chatwithtools', $this->provider->getId());
        $this->assertArrayHasKey('provider', $this->provider->getOptionalInputShape());
    }

    public function testTextOnlyTurnBehavesLikeChatWithTools(): void {
        $this->llm = $this->llmWith([]);
        $this->llm->expects($this->once())
            ->method('chatToolsTurn')
            ->with([['role' => 'user', 'content' => 'Hello']], [], null, 'alice')
            ->willReturn(['response' => 'Hi', 'tool_calls' => []]);

        $result = $this->provider->process('alice', ['input' => 'Hello'], static fn () => null);

        $this->assertSame(['output' => 'Hi', 'tool_calls' => '', 'output_attachments' => []], $result);
    }

    public function testTextAttachmentIsInlinedUnderItsName(): void {
        $this->llm = $this->llmWith([]);
        $this->llm->expects($this->once())
            ->method('chatToolsTurn')
            ->with([[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => "Attached file \"notes.md\":\n\n# Notes"],
                    ['type' => 'text', 'text' => 'Summarize'],
                ],
            ]])
            ->willReturn(['response' => 'ok', 'tool_calls' => []]);

        $this->provider->process('alice', [
            'input' => 'Summarize',
            'input_attachments' => [$this->file('notes.md', 'text/markdown', '# Notes')],
        ], static fn () => null);
    }

    public function testImageReachesAVisionCapableModel(): void {
        $this->llm = $this->llmWith(['vision' => true]);
        $this->llm->expects($this->once())
            ->method('chatToolsTurn')
            ->with($this->callback(function (array $messages): bool {
                $block = $messages[0]['content'][0];
                return $block['type'] === 'image'
                    && $block['source']['type'] === 'base64'
                    && base64_decode($block['source']['data']) === 'IMAGE-BYTES';
            }))
            ->willReturn(['response' => 'a cat', 'tool_calls' => []]);

        // A pass-through optimizer, so the bytes arrive as sent.
        $optimizer = $this->createStub(ImageOptimizer::class);
        $optimizer->method('isSupported')->willReturn(true);
        $optimizer->method('prepare')->willReturnCallback(
            fn (string $bytes, string $mime) => ['base64' => base64_encode($bytes), 'mimeType' => $mime]
        );
        $provider = new MultimodalChatWithToolsProvider(new ProviderResolver($this->factory, $this->createStub(UsageStatService::class)), $optimizer);

        $result = $provider->process('alice', [
            'input_attachments' => [$this->file('cat.png', 'image/png', 'IMAGE-BYTES')],
        ], static fn () => null);

        $this->assertSame('a cat', $result['output']);
    }

    public function testImageIsRefusedByABlindModel(): void {
        $this->llm = $this->llmWith([]);
        $this->llm->expects($this->never())->method('chatToolsTurn');

        $this->expectExceptionMessage('Test cannot process images');
        $this->provider->process('alice', [
            'input' => 'What is this?',
            'input_attachments' => [$this->file('cat.png', 'image/png', 'x')],
        ], static fn () => null);
    }

    public function testPdfNeedsADocumentCapableModel(): void {
        $this->llm = $this->llmWith(['vision' => true]);
        $this->llm->expects($this->never())->method('chatToolsTurn');

        $this->expectExceptionMessage('Test cannot read PDF documents');
        $this->provider->process('alice', [
            'input' => 'Summarize',
            'input_attachments' => [$this->file('a.pdf', 'application/pdf', '%PDF')],
        ], static fn () => null);
    }

    public function testPdfIsSentAsADocumentBlock(): void {
        $this->llm = $this->llmWith(['documents' => true]);
        $this->llm->expects($this->once())
            ->method('chatToolsTurn')
            ->with($this->callback(fn (array $m): bool => $m[0]['content'][0] === [
                'type' => 'document',
                'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF')],
                'title' => 'a.pdf',
            ]))
            ->willReturn(['response' => 'ok', 'tool_calls' => []]);

        $this->provider->process('alice', [
            'input_attachments' => [$this->file('a.pdf', 'application/pdf', '%PDF')],
        ], static fn () => null);
    }

    public function testUnsupportedTypeIsRefusedNotDropped(): void {
        $this->llm = $this->llmWith([]);
        $this->llm->expects($this->never())->method('chatToolsTurn');

        $this->expectExceptionMessage('cannot send to a model (application/zip)');
        $this->provider->process('alice', [
            'input' => 'Open this',
            'input_attachments' => [$this->file('a.zip', 'application/zip', 'PK')],
        ], static fn () => null);
    }
}
