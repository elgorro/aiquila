<?php

namespace OCA\AIquila\Tests\Unit\Listener;

use OCA\AIquila\Listener\TaskFailedListener;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskFailedListenerTest extends TestCase {
    private INotificationManager $notificationManager;
    private ITaskProcessingManager $taskProcessingManager;
    private LoggerInterface $logger;
    private TaskFailedListener $listener;

    protected function setUp(): void {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->taskProcessingManager = $this->createMock(ITaskProcessingManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new TaskFailedListener(
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
        ?int $id = null,
        ?string $errorMessage = null,
        string $taskTypeId = 'core:text2text',
        ?string $userId = 'testuser',
    ): Task {
        $task = new Task($taskTypeId, ['input' => 'hello'], 'aiquila', $userId);
        $task->setId($id);
        $task->setErrorMessage($errorMessage);

        return $task;
    }

    public function testIgnoresNonAiquilaProvider(): void {
        $this->mockPreferredProvider('other_app:text2text');

        $task = $this->makeTask();
        $event = $this->createMock(TaskFailedEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->listener->handle($event);
    }

    public function testCreatesNotificationOnFailure(): void {
        $this->mockPreferredProvider('aiquila:text2text');

        $task = $this->makeTask(99, 'API rate limit exceeded');

        $event = $this->createMock(TaskFailedEvent::class);
        $event->method('getTask')->willReturn($task);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $notification->expects($this->once())->method('setSubject')
            ->with('task_failure', ['Text generation', 'API rate limit exceeded']);

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify')->with($notification);

        $this->listener->handle($event);
    }

    public function testTruncatesLongErrorMessage(): void {
        $this->mockPreferredProvider('aiquila:text2text');

        $longError = str_repeat('x', 300);

        $task = $this->makeTask(100, $longError);

        $event = $this->createMock(TaskFailedEvent::class);
        $event->method('getTask')->willReturn($task);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $notification->expects($this->once())->method('setSubject')
            ->with('task_failure', $this->callback(function (array $params) {
                return strlen($params[1]) <= 204; // 200 + '…' (3 bytes)
            }));

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $this->listener->handle($event);
    }

    public function testHandlesNullErrorMessage(): void {
        $this->mockPreferredProvider('aiquila:text2text');

        $task = $this->makeTask(101, null);

        $event = $this->createMock(TaskFailedEvent::class);
        $event->method('getTask')->willReturn($task);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $notification->expects($this->once())->method('setSubject')
            ->with('task_failure', ['Text generation', '']);

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $this->listener->handle($event);
    }

    public function testUnknownTaskTypeIsSkippedSilently(): void {
        $this->taskProcessingManager->method('getPreferredProvider')
            ->willThrowException(new \OCP\TaskProcessing\Exception\Exception('no provider'));

        $task = $this->makeTask();
        $event = $this->createMock(TaskFailedEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->notificationManager->expects($this->never())->method('notify');
        $this->logger->expects($this->never())->method('error');
        $this->listener->handle($event);
    }

    public function testUnexpectedErrorIsLoggedNotPropagated(): void {
        $this->taskProcessingManager->method('getPreferredProvider')
            ->willThrowException(new \RuntimeException('boom'));

        $task = $this->makeTask();
        $event = $this->createMock(TaskFailedEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->logger->expects($this->once())->method('error');
        $this->listener->handle($event);
    }

    public function testNotificationFailureDoesNotBreakTheEventChain(): void {
        $this->mockPreferredProvider('aiquila:text2text');

        $task = $this->makeTask(99, 'boom');

        $event = $this->createMock(TaskFailedEvent::class);
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
}
