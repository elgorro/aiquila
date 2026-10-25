<?php

namespace OCA\AIquila\Tests\Unit\SetupCheck;

use OCA\AIquila\SetupCheck\TextAssistantAvailable;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;

class TextAssistantAvailableTest extends TestCase {
    private IAppManager $appManager;
    private IManager $taskManager;
    private TextAssistantAvailable $check;

    protected function setUp(): void {
        $this->appManager = $this->createMock(IAppManager::class);
        $this->taskManager = $this->createMock(IManager::class);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            fn(string $text, array $params = []) => $params ? vsprintf($text, $params) : $text
        );
        $this->check = new TextAssistantAvailable($this->appManager, $this->taskManager, $l10n);
    }

    /** @param array<string, bool> $enabled */
    private function enabledApps(array $enabled): void {
        $this->appManager->method('isEnabledForAnyone')
            ->willReturnCallback(fn(string $app) => $enabled[$app] ?? false);
    }

    public function testGetCategory(): void {
        $this->assertSame('system', $this->check->getCategory());
    }

    public function testSuccessWhenTextDisabled(): void {
        $this->enabledApps(['text' => false, 'assistant' => false]);
        $this->taskManager->expects($this->never())->method('getAvailableTaskTypes');
        $this->assertSame('success', $this->check->run()->getSeverity());
    }

    public function testSuccessWhenAssistantEnabled(): void {
        $this->enabledApps(['text' => true, 'assistant' => true]);
        $this->assertSame('success', $this->check->run()->getSeverity());
    }

    public function testSuccessWhenNoMenuTaskTypeAvailable(): void {
        $this->enabledApps(['text' => true]);
        $this->taskManager->method('getAvailableTaskTypes')
            ->willReturn(['core:text2text:proofread' => [], 'core:audio2text' => []]);
        $this->assertSame('success', $this->check->run()->getSeverity());
    }

    public function testWarningWhenTextMenuShownWithoutAssistant(): void {
        $this->enabledApps(['text' => true]);
        $this->taskManager->method('getAvailableTaskTypes')
            ->willReturn(['core:text2text:summary' => []]);
        $result = $this->check->run();
        $this->assertSame('warning', $result->getSeverity());
        $this->assertStringContainsString('Assistant app', $result->getDescription());
    }

    public function testTranslateAloneShowsTheMenu(): void {
        $this->enabledApps(['text' => true]);
        $this->taskManager->method('getAvailableTaskTypes')
            ->willReturn(['core:text2text:translate' => []]);
        $this->assertSame('warning', $this->check->run()->getSeverity());
    }
}
