<?php

namespace OCA\AIquila\Tests\Unit\Listener;

use OCA\AIquila\Listener\TaskSuccessfulListener;
use OCA\AIquila\Service\AppSettings;
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
    private AppSettings $config;
    private LoggerInterface $logger;
    /** Stubbed preference value; null lets getUserValue return its own default. */
    private ?string $prefValue = null;
    private TaskSuccessfulListener $listener;

    protected function setUp(): void {
        $this->notificationManager = $this->createStub(INotificationManager::class);
        $this->taskProcessingManager = $this->createStub(ITaskProcessingManager::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->config = $this->createStub(AppSettings::class);
        $this->config->method('getUserValue')
            ->willReturnCallback(fn (string $u, string $a, string $k, $d = '') => $this->prefValue ?? $d);
        $this->buildListener();
    }

    private function buildListener(): void {
        $this->listener = new TaskSuccessfulListener(
            $this->notificationManager,
            $this->taskProcessingManager,
            $this->config,
            $this->logger,
        );
    }

    private function mockPreferredProvider(string $providerId): void {
        $provider = $this->createStub(IProvider::class);
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
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->buildListener();

        // These assertions are about the provider/notification path, so opt in.
        $this->prefValue = '1';

        $this->mockPreferredProvider('other_app:text2text');

        $task = $this->makeTask();
        $event = $this->createStub(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->listener->handle($event);
    }

    public function testIgnoresNullUser(): void {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->buildListener();

        $this->mockPreferredProvider('aiquila:text2text');

        $task = $this->makeTask(userId: null);

        $event = $this->createStub(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->listener->handle($event);
    }

    public function testCreatesNotificationOnSuccess(): void {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->buildListener();

        // These assertions are about the provider/notification path, so opt in.
        $this->prefValue = '1';

        $this->mockPreferredProvider('aiquila:text2text');

        $task = $this->makeTask('core:text2text:summary', id: 42);

        $event = $this->createStub(TaskSuccessfulEvent::class);
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
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->buildListener();

        // These assertions are about the provider/notification path, so opt in.
        $this->prefValue = '1';

        // A task type with no registered provider is not ours: skip without
        // notifying and without logging an error.
        $this->taskProcessingManager->method('getPreferredProvider')
            ->willThrowException(new \OCP\TaskProcessing\Exception\Exception('no provider'));

        $task = $this->makeTask();
        $event = $this->createStub(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('createNotification');
        $this->notificationManager->expects($this->never())->method('notify');
        $this->logger->expects($this->never())->method('error');
        $this->listener->handle($event);
    }

    public function testUnexpectedErrorIsLoggedNotPropagated(): void {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->buildListener();

        // These assertions are about the provider/notification path, so opt in.
        $this->prefValue = '1';

        // Anything other than "no provider" is unexpected: it must be logged by
        // the guarded handle() and must not propagate to break the event chain.
        $this->taskProcessingManager->method('getPreferredProvider')
            ->willThrowException(new \RuntimeException('boom'));

        $task = $this->makeTask();
        $event = $this->createStub(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->logger->expects($this->once())->method('error');
        $this->listener->handle($event);
    }

    public function testNotificationFailureDoesNotBreakTheEventChain(): void {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->buildListener();

        // These assertions are about the provider/notification path, so opt in.
        $this->prefValue = '1';

        // This listener runs before other apps' listeners in the same event
        // chain, so a failure while notifying must be logged and swallowed.
        $this->mockPreferredProvider('aiquila:text2text');

        $task = $this->makeTask(id: 42);

        $event = $this->createStub(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $notification = $this->createStub(INotification::class);
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

    public function testSuccessNotificationsAreOffByDefault(): void {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->buildListener();

        // AIquila never schedules the tasks it serves, so silence is the default.
        $this->mockPreferredProvider('aiquila:text2text');

        $event = $this->createStub(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($this->makeTask());

        $this->notificationManager->expects($this->never())->method('notify');
        $this->listener->handle($event);
    }

    public function testPreferenceIsCheckedBeforeTheProviderLookup(): void {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->taskProcessingManager = $this->createMock(ITaskProcessingManager::class);
        $this->buildListener();

        // Resolving the provider costs a manager call; the opt-out must not pay it.
        $this->prefValue = '0';

        $this->taskProcessingManager->expects($this->never())->method('getPreferredProvider');

        $event = $this->createStub(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($this->makeTask());

        $this->notificationManager->expects($this->never())->method('notify');
        $this->listener->handle($event);
    }

    public function testTaskTypeLabelMapping(): void {
        $this->assertSame('Summarization', TaskSuccessfulListener::getTaskTypeLabel('core:text2text:summary'));
        $this->assertSame('Image analysis', TaskSuccessfulListener::getTaskTypeLabel('core:image2text'));
        $this->assertSame('Unknown', TaskSuccessfulListener::getTaskTypeLabel('core:unknown'));
    }
}
