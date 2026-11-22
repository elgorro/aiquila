<?php

namespace OCA\AIquila\Tests\Unit\Notifier;

use OCA\AIquila\Notifier\AIquilaNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

class AIquilaNotifierTest extends TestCase {
    private IURLGenerator $urlGenerator;
    private IL10N $l10n;
    private AIquilaNotifier $notifier;

    protected function setUp(): void {
        $this->urlGenerator = $this->createStub(IURLGenerator::class);
        $this->l10n = $this->createStub(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(
            fn(string $text, array $params = []) => $params ? vsprintf($text, $params) : $text
        );
        // As OC\L10N\L10NString does: %n first, then the parameters.
        $this->l10n->method('n')->willReturnCallback(
            fn(string $one, string $many, int $count, array $params = []) => vsprintf(str_replace('%n', (string)$count, $count === 1 ? $one : $many), $params)
        );
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
            static fn (string $route, array $args = []) => 'https://cloud.example/' . $route . ($args ? '?' . http_build_query($args) : '')
        );
        $this->urlGenerator->method('imagePath')->willReturn('/apps/aiquila/img/app-dark.svg');
        $this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $url) => 'https://cloud.example' . $url
        );
        $this->notifier = new AIquilaNotifier($this->urlGenerator, $this->l10n);
    }

    public function testGetId(): void {
        $this->assertSame('aiquila', $this->notifier->getID());
    }

    public function testGetName(): void {
        $this->assertSame('AIquila', $this->notifier->getName());
    }

    public function testPrepareThrowsForOtherApp(): void {
        $notification = $this->createStub(INotification::class);
        $notification->method('getApp')->willReturn('other_app');

        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareTaskSuccess(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('aiquila');
        $notification->method('getSubject')->willReturn('task_success');
        $notification->method('getSubjectParameters')->willReturn(['Summarization']);

        $notification->expects($this->once())->method('setParsedSubject')
            ->with('AIquila task completed');
        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Your Summarization task has completed successfully.');
        $notification->expects($this->once())->method('setIcon')
            // Nextcloud rejects relative icon URLs, dropping the notification.
            ->with('https://cloud.example/apps/aiquila/img/app-dark.svg');

        // Chain methods return self
        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareTaskFailureWithError(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('aiquila');
        $notification->method('getSubject')->willReturn('task_failure');
        $notification->method('getSubjectParameters')->willReturn(['Text generation', 'API timeout']);

        $notification->expects($this->once())->method('setParsedSubject')
            ->with('AIquila task failed');
        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Your Text generation task failed: API timeout');
        $notification->expects($this->once())->method('setIcon');

        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareTaskFailureWithoutError(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('aiquila');
        $notification->method('getSubject')->willReturn('task_failure');
        $notification->method('getSubjectParameters')->willReturn(['Image analysis', '']);

        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Your Image analysis task failed.');

        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareAskResponse(): void {
        // The subject AIquilaService::askAsync() sends; it used to be free text,
        // which landed in the default branch and threw.
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('aiquila');
        $notification->method('getSubject')->willReturn('ask_response');
        $notification->method('getSubjectParameters')->willReturn(['Here is the answer']);

        $notification->expects($this->once())->method('setParsedSubject')
            ->with('AIquila response');
        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Here is the answer');

        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareAskResponseWithoutTextSetsNoMessage(): void {
        // Nextcloud throws on an empty parsed message, which would drop the notification.
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('aiquila');
        $notification->method('getSubject')->willReturn('ask_response');
        $notification->method('getSubjectParameters')->willReturn([]);

        $notification->expects($this->once())->method('setParsedSubject')->willReturn($notification);
        $notification->expects($this->never())->method('setParsedMessage');
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    // ── Manual batch runs ─────────────────────────────────────────────────

    /**
     * A notification stub that keeps what prepare() parses into it, with
     * real-enough actions.
     *
     * @return array{0: INotification, 1: \ArrayObject}
     */
    private function batchRunNotification(string $subject, array $params): array {
        $seen = new \ArrayObject(['actions' => []]);
        $states = new \WeakMap();
        $n = $this->createStub(INotification::class);
        $n->method('getApp')->willReturn('aiquila');
        $n->method('getSubject')->willReturn($subject);
        $n->method('getSubjectParameters')->willReturn($params);
        foreach (['setParsedSubject' => 'subject', 'setParsedMessage' => 'message', 'setLink' => 'link', 'setIcon' => 'icon'] as $method => $key) {
            $n->method($method)->willReturnCallback(function (string $v) use ($n, $seen, $key) {
                $seen[$key] = $v;
                return $n;
            });
        }
        $n->method('createAction')->willReturnCallback(function () use ($states) {
            $action = $this->createStub(IAction::class);
            $state = new \ArrayObject();
            $action->method('setParsedLabel')->willReturnCallback(function (string $l) use ($action, $state) {
                $state['label'] = $l;
                return $action;
            });
            $action->method('setLink')->willReturnCallback(function (string $link, string $type) use ($action, $state) {
                $state['link'] = $link;
                $state['type'] = $type;
                return $action;
            });
            $action->method('setPrimary')->willReturnCallback(function (bool $p) use ($action, $state) {
                $state['primary'] = $p;
                return $action;
            });
            $action->method('getParsedLabel')->willReturnCallback(fn () => $state['label']);
            $states[$action] = $state;
            return $action;
        });
        $n->method('addParsedAction')->willReturnCallback(function (IAction $a) use ($n, $seen, $states) {
            $actions = $seen['actions'];
            $actions[] = $states[$a]->getArrayCopy();
            $seen['actions'] = $actions;
            return $n;
        });
        return [$n, $seen];
    }

    public function testPrepareBatchRunFinishedLinksToTheResultsAndTheRun(): void {
        [$n, $seen] = $this->batchRunNotification('batch_run_success', [
            'runId' => 42, 'operation' => 'summarize', 'processed' => 3, 'total' => 5,
            'failed' => 1, 'skipped' => 2, 'error' => '', 'outputDir' => '/Docs',
        ]);

        $this->notifier->prepare($n, 'en');

        $this->assertSame('AIquila: Summarize run finished', $seen['subject']);
        $this->assertSame('3 of 5 documents done, 1 failed, 2 files skipped', $seen['message']);
        $this->assertSame('https://cloud.example/aiquila.page.index#/cowork?run=42', $seen['link']);
        $this->assertSame([
            ['label' => 'Show results', 'link' => 'https://cloud.example/files.view.index?dir=%2FDocs', 'type' => 'WEB', 'primary' => true],
            ['label' => 'View run', 'link' => 'https://cloud.example/aiquila.page.index#/cowork?run=42', 'type' => 'WEB', 'primary' => false],
        ], $seen['actions']);
        $this->assertSame('https://cloud.example/apps/aiquila/img/app-dark.svg', $seen['icon']);
    }

    public function testPrepareBatchRunWithOneOutputOpensThatFile(): void {
        [$n, $seen] = $this->batchRunNotification('batch_run_success', [
            'runId' => 7, 'operation' => 'translate', 'processed' => 1, 'total' => 1,
            'failed' => 0, 'skipped' => 0, 'error' => '', 'outputDir' => '/Docs', 'outputFileId' => 777,
        ]);

        $this->notifier->prepare($n, 'en');

        $this->assertSame('AIquila: Translate run finished', $seen['subject']);
        $this->assertSame('1 of 1 document done', $seen['message']);
        $this->assertSame('https://cloud.example/files.view.showFile?fileid=777', $seen['actions'][0]['link']);
    }

    public function testPrepareBatchRunFailedShowsTheErrorAndOnlyTheRunLink(): void {
        [$n, $seen] = $this->batchRunNotification('batch_run_error', [
            'runId' => 9, 'operation' => 'summarize', 'processed' => 0, 'total' => 0,
            'failed' => 0, 'skipped' => 0, 'error' => 'Batch b_1 did not end within 26 hours',
        ]);

        $this->notifier->prepare($n, 'en');

        $this->assertSame('AIquila: Summarize run failed', $seen['subject']);
        $this->assertSame('Batch b_1 did not end within 26 hours', $seen['message']);
        $this->assertCount(1, $seen['actions']);
        $this->assertSame('View run', $seen['actions'][0]['label']);
        $this->assertTrue($seen['actions'][0]['primary']);
    }

    /** Nextcloud rejects an empty parsed message, which would drop the notification. */
    public function testPrepareBatchRunWithNothingDoneStillHasAMessage(): void {
        [$n, $seen] = $this->batchRunNotification('batch_run_success', [
            'runId' => 3, 'operation' => 'summarize', 'processed' => 0, 'total' => 0,
            'failed' => 0, 'skipped' => 0, 'error' => '',
        ]);
        $this->notifier->prepare($n, 'en');
        $this->assertSame('Nothing to do: no documents that still need work.', $seen['message']);

        [$n, $seen] = $this->batchRunNotification('batch_run_error', [
            'runId' => 3, 'operation' => 'summarize', 'processed' => 0, 'total' => 0,
            'failed' => 0, 'skipped' => 0, 'error' => '',
        ]);
        $this->notifier->prepare($n, 'en');
        $this->assertSame('The run ended without a result.', $seen['message']);
    }

    public function testPrepareUnknownSubjectThrows(): void {
        $notification = $this->createStub(INotification::class);
        $notification->method('getApp')->willReturn('aiquila');
        $notification->method('getSubject')->willReturn('unknown_subject');

        $this->expectException(UnknownNotificationException::class);
        $this->notifier->prepare($notification, 'en');
    }
}
