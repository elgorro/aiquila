<?php

namespace OCA\AIquila\Tests\Unit\Flow;

use OCA\AIquila\BackgroundJob\FlowOperationJob;
use OCA\AIquila\Flow\AIquilaOperation;
use OCA\AIquila\Flow\FlowConfig;
use OCA\AIquila\Flow\FlowProcessor;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\WorkflowEngine\IRuleMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\AIquila\Service\UsageStatService;

/**
 * "Process with AIquila" in Settings → Flow: rule settings, event handling,
 * and the action that writes `<name>.aiquila.md`.
 */
class FlowTest extends TestCase {
    // ── FlowConfig ────────────────────────────────────────────────────────

    public function testEmptyValueMeansAuto(): void {
        $this->assertSame(['action' => 'auto', 'prompt' => ''], FlowConfig::parse('')->toArray());
    }

    public function testAskKeepsItsPromptAndOtherActionsDropIt(): void {
        $this->assertSame('Any deadlines?', FlowConfig::parse('{"action":"ask","prompt":" Any deadlines? "}')->prompt);
        $this->assertSame('', FlowConfig::parse('{"action":"summarize","prompt":"ignored"}')->prompt);
    }

    /** @return array<string, array{string}> */
    public static function invalidValues(): array {
        return [
            'not json' => ['nope'],
            'unknown action' => ['{"action":"delete"}'],
            'ask without prompt' => ['{"action":"ask","prompt":"  "}'],
            'prompt too long' => ['{"action":"ask","prompt":"' . str_repeat('x', FlowConfig::MAX_PROMPT_LENGTH + 1) . '"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(string $value): void {
        $this->expectException(\UnexpectedValueException::class);
        FlowConfig::parse($value);
    }

    // ── AIquilaOperation ──────────────────────────────────────────────────

    private function operation(IJobList $jobList): AIquilaOperation {
        $l = $this->createStub(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        return new AIquilaOperation(
            $l,
            $this->createStub(IURLGenerator::class),
            $jobList,
            $this->createStub(IRootFolder::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function file(string $path, int $id = 42): File {
        $owner = $this->createStub(IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $file = $this->createStub(File::class);
        $file->method('getPath')->willReturn($path);
        $file->method('getName')->willReturn(basename($path));
        $file->method('getId')->willReturn($id);
        $file->method('getOwner')->willReturn($owner);
        return $file;
    }

    /** @param list<array{operation: string}> $flows */
    private function matcher(array $flows): IRuleMatcher {
        $matcher = $this->createStub(IRuleMatcher::class);
        $matcher->method('getFlows')->willReturn($flows);
        return $matcher;
    }

    public function testMatchingRuleQueuesOneJobPerDistinctConfiguration(): void {
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->exactly(2))->method('add')->willReturnCallback(function (string $class, array $arg): void {
            $this->assertSame(FlowOperationJob::class, $class);
            $this->assertSame(42, $arg['fileId']);
            $this->assertSame('alice', $arg['user']);
        });

        $this->operation($jobList)->onEvent(
            '\OCP\Files::postCreate',
            new GenericEvent($this->file('/alice/files/report.txt')),
            $this->matcher([['operation' => ''], ['operation' => '{"action":"auto"}'], ['operation' => '{"action":"ask","prompt":"Q"}']]),
        );
    }

    public function testRenameUsesTheNewNode(): void {
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->once())->method('add')->with(FlowOperationJob::class, $this->callback(fn (array $a) => $a['fileId'] === 7));

        $this->operation($jobList)->onEvent(
            '\OCP\Files::postRename',
            new GenericEvent([$this->file('/alice/files/old.txt', 6), $this->file('/alice/files/new.txt', 7)]),
            $this->matcher([['operation' => '']]),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function skippedEvents(): array {
        return [
            'own result file' => ['\OCP\Files::postCreate', '/alice/files/report.txt.aiquila.md'],
            'outside files/' => ['\OCP\Files::postCreate', '/alice/files_versions/report.txt'],
            'deleted' => ['\OCP\Files::postDelete', '/alice/files/report.txt'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('skippedEvents')]
    public function testEventsThatQueueNothing(string $eventName, string $path): void {
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->never())->method('add');

        $this->operation($jobList)->onEvent($eventName, new GenericEvent($this->file($path)), $this->matcher([['operation' => '']]));
    }

    public function testValidateRejectsBadSettings(): void {
        $this->expectException(\UnexpectedValueException::class);
        $this->operation($this->createStub(IJobList::class))->validateOperation('rule', [], '{"action":"ask"}');
    }

    // ── FlowProcessor ─────────────────────────────────────────────────────

    /**
     * @param array<string, bool> $capabilities
     * @return array{FlowProcessor, LLMProviderInterface, Folder}
     */
    private function processor(File $file, array $capabilities = []): array {
        $parent = $this->createMock(Folder::class);
        $parent->method('isCreatable')->willReturn(true);
        $parent->method('nodeExists')->willReturn(false);
        $file->method('getParent')->willReturn($parent);

        $userFolder = $this->createStub(Folder::class);
        $userFolder->method('getFirstNodeById')->willReturn($file);
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->method('getCapabilities')->willReturn(ProviderSettingsSchema::capabilities($capabilities));
        $llm->method('getLabel')->willReturn('Test');
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturn($llm);

        return [
            new FlowProcessor($root, $factory, new ImageOptimizer($this->createStub(LoggerInterface::class)), $this->usageRecorder(), $this->createStub(LoggerInterface::class)),
            $llm,
            $parent,
        ];
    }

    private function sourceFile(string $name, string $mime, string $content): File {
        $file = $this->createStub(File::class);
        $file->method('getName')->willReturn($name);
        $file->method('getMimetype')->willReturn($mime);
        $file->method('getContent')->willReturn($content);
        $file->method('getId')->willReturn(42);
        return $file;
    }

    /** @var list<array<int, mixed>> UsageStatService::record() calls */
    private array $usageRecorded = [];

    private function usageRecorder(): UsageStatService {
        $usage = $this->createStub(UsageStatService::class);
        $usage->method('record')->willReturnCallback(function (mixed ...$args): void {
            $this->usageRecorded[] = $args;
        });
        return $usage;
    }

    public function testTextFileIsSummarizedIntoAResultFile(): void {
        [$processor, $llm, $parent] = $this->processor($this->sourceFile('notes.txt', 'text/plain', 'Meeting on Friday.'));
        $llm->expects($this->once())->method('ask')
            ->with($this->stringStartsWith('Summarize'), 'Meeting on Friday.', 'alice')
            ->willReturn(['response' => 'A meeting is planned.']);

        $result = $this->createStub(File::class);
        $result->method('getPath')->willReturn('/alice/files/notes.txt.aiquila.md');
        $parent->expects($this->once())->method('newFile')
            ->with('notes.txt.aiquila.md', $this->stringContains('A meeting is planned.'))
            ->willReturn($result);

        $this->assertSame('/alice/files/notes.txt.aiquila.md', $processor->process(42, 'alice', FlowConfig::parse('')));
        $this->assertCount(1, $this->usageRecorded);
        $this->assertSame(UsageStatService::TYPE_FLOW, $this->usageRecorded[0][3]);
    }

    public function testAskQuotesThePromptAboveTheAnswer(): void {
        [$processor, $llm, $parent] = $this->processor($this->sourceFile('notes.txt', 'text/plain', 'x'));
        $llm->expects($this->once())->method('ask')->willReturn(['response' => 'Friday.']);
        $parent->expects($this->once())->method('newFile')
            ->with($this->anything(), $this->stringContains("> When?\n\nFriday."))
            ->willReturn($this->createStub(File::class));

        $processor->process(42, 'alice', FlowConfig::parse('{"action":"ask","prompt":"When?"}'));
    }

    public function testImageOnABlindProviderWritesNothing(): void {
        [$processor, $llm, $parent] = $this->processor($this->sourceFile('cat.png', 'image/png', 'png'));
        $llm->expects($this->never())->method('askWithImage');
        $parent->expects($this->never())->method('newFile');

        $this->assertNull($processor->process(42, 'alice', FlowConfig::parse('')));
    }

    public function testUnsupportedTypeWritesNothing(): void {
        [$processor, $llm, $parent] = $this->processor($this->sourceFile('a.zip', 'application/zip', 'PK'));
        $llm->expects($this->never())->method('ask');
        $parent->expects($this->never())->method('newFile');

        $this->assertNull($processor->process(42, 'alice', FlowConfig::parse('')));
    }

    public function testFailedModelCallWritesNothing(): void {
        [$processor, $llm, $parent] = $this->processor($this->sourceFile('notes.txt', 'text/plain', 'x'));
        $llm->expects($this->once())->method('ask')->willReturn(['error' => 'rate limited']);
        $parent->expects($this->never())->method('newFile');

        $this->assertNull($processor->process(42, 'alice', FlowConfig::parse('')));
    }
}
