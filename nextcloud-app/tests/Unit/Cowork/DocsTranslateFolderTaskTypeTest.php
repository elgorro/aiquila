<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Cowork;

use OCA\AIquila\Cowork\DocsTranslateFolderTaskType;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DocsTranslateFolderTaskTypeTest extends TestCase {
    private DocsTranslateFolderTaskType $task;

    protected function setUp(): void {
        $this->task = new DocsTranslateFolderTaskType(
            $this->createMock(IRootFolder::class),
            $this->createMock(LLMProviderFactory::class),
            $this->createMock(ISystemTagManager::class),
            $this->createMock(ISystemTagObjectMapper::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /** Without a language there is nothing to translate into. */
    public function testTargetLanguageIsRequired(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->task->validateOptions(['recursive' => true]);
    }

    public function testTargetLanguageRejectsGarbage(): void {
        foreach (['', '123', 'de_DE!', str_repeat('a', 40)] as $bad) {
            try {
                $this->task->validateOptions(['targetLanguage' => $bad]);
                $this->fail("'$bad' should not be accepted as a language");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTargetLanguageAcceptsALanguageName(): void {
        $this->task->validateOptions(['targetLanguage' => 'Brazilian Portuguese']);
        $this->addToAssertionCount(1);
    }

    /** Inherited option validation still applies on top of the language check. */
    public function testInheritedOptionsAreStillValidated(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->task->validateOptions(['targetLanguage' => 'German', 'maxBytesPerFile' => 10]);
    }

    public function testOutputNameCarriesTheLanguage(): void {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('report.md');

        $name = $this->callOutputName($file, ['targetLanguage' => 'German']);

        $this->assertSame('report.german.md', $name);
    }

    /**
     * Two coworkers naming the same language differently should land on the
     * same output rather than quietly duplicating the work.
     */
    public function testOutputNameNormalisesSpacingAndCase(): void {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('report.md');

        $this->assertSame(
            $this->callOutputName($file, ['targetLanguage' => 'brazilian-portuguese']),
            $this->callOutputName($file, ['targetLanguage' => 'Brazilian Portuguese'])
        );
    }

    public function testPromptNamesTheTargetLanguage(): void {
        $method = new \ReflectionMethod($this->task, 'buildPrompt');
        $prompt = $method->invoke($this->task, 'the body', ['targetLanguage' => 'German']);

        $this->assertStringContainsString('into German', $prompt);
        $this->assertStringContainsString('the body', $prompt);
    }

    private function callOutputName(File $file, array $options): string {
        $method = new \ReflectionMethod($this->task, 'outputName');
        return $method->invoke($this->task, $file, $options);
    }
}
