<?php

namespace OCA\AIquila\Tests\Unit\Listener;

use OCA\AIquila\Listener\TaskSuccessfulListener;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskSuccessfulListenerTest extends TestCase {
    private INotificationManager $notificationManager;
    private ITaskProcessingManager $taskProcessingManager;
    private LoggerInterface $logger;
    private TaskSuccessfulListener $listener;

    protected function setUp(): void {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->taskProcessingManager = $this->createMock(ITaskProcessingManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new TaskSuccessfulListener(
            $this->notificationManager,
            $this->taskProcessingManager,
            $this->logger,
        );
    }

    private function mockPreferredProvider(string $providerId): void {
        $provider = $this->createMock(IProvider::class);
        $provider->method('getId')->willReturn($providerId);
        $this->taskProcessingManager->method('getPreferredProvider')->willReturn($provider);
    }

    /**
     * `OCP\\TaskProcessing\\Task` is final and cannot be mocked; build a real one.
     */
    private function makeTask(
        string $taskTypeId = 'core:text2text',
        ?string $userId = 'testuser',
        ?int $id = null,
    ): Task {
        $task = new Task($taskTypeId, ['input' => 'hello'], 'aiquila', $userId);
        $task->setId($id);

        return $task;
    }

    public function testIgnoresNonAiquilaProvider(): void {
        $this->mockPreferredProvider('other_app:text2text');

        $task = $this->makeTask();
        $event = $this->createMock(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->listener->handle($event);
    }

    public function testIgnoresNullUser(): void {
        $this->mockPreferredProvider('aiquila:text2text');

        $task = $this->makeTask(userId: null);

        $event = $this->createMock(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->listener->handle($event);
    }

    public function testCreatesNotificationOnSuccess(): void {
        $this->mockPreferredProvider('aiquila:text2text');

        $task = $this->makeTask('core:text2text:summary', id: 42);

        $event = $this->createMock(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $notification->expects($this->once())->method('setUser')->with('testuser');
        $notification->expects($this->once())->method('setObject')->with('task_processing', '42');
        $notification->expects($this->once())->method('setSubject')->with('task_success', ['Summarization']);

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify')->with($notification);

        $this->listener->handle($event);
    }

    public function testUnknownTaskTypeIsSkippedSilently(): void {
        // A task type with no registered provider is not ours: skip without
        // notifying and without logging an error.
        $this->taskProcessingManager->method('getPreferredProvider')
            ->willThrowException(new \OCP\TaskProcessing\Exception\Exception('no provider'));

        $task = $this->makeTask();
        $event = $this->createMock(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->notificationManager->expects($this->never())->method('notify');
        $this->logger->expects($this->never())->method('error');
        $this->listener->handle($event);
    }

    public function testUnexpectedErrorIsLoggedNotPropagated(): void {
        // Anything other than "no provider" is unexpected: it must be logged by
        // the guarded handle() and must not propagate to break the event chain.
        $this->taskProcessingManager->method('getPreferredProvider')
            ->willThrowException(new \RuntimeException('boom'));

        $task = $this->makeTask();
        $event = $this->createMock(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->logger->expects($this->once())->method('error');
        $this->listener->handle($event);
    }

    public function testNotificationFailureDoesNotBreakTheEventChain(): void {
        // This listener runs before other apps' listeners in the same event
        // chain, so a failure while notifying must be logged and swallowed.
        $this->mockPreferredProvider('aiquila:text2text');

        $task = $this->makeTask(id: 42);

        $event = $this->createMock(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->method('notify')
            ->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error');

        $this->listener->handle($event);
    }

    public function testTaskTypeLabelMapping(): void {
        $this->assertSame('Summarization', TaskSuccessfulListener::getTaskTypeLabel('core:text2text:summary'));
        $this->assertSame('Image analysis', TaskSuccessfulListener::getTaskTypeLabel('core:image2text'));
        $this->assertSame('Unknown', TaskSuccessfulListener::getTaskTypeLabel('core:unknown'));
    }
}
