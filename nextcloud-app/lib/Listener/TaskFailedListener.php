<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<TaskFailedEvent>
 */
class TaskFailedListener implements IEventListener {
    use TaskListenerTrait;

    public function __construct(
        private INotificationManager $notificationManager,
        private ITaskProcessingManager $taskProcessingManager,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof TaskFailedEvent) {
            return;
        }

        // Same reasoning as TaskSuccessfulListener: a throwable here would
        // abort the whole TaskProcessing event chain, so guard against it.
        try {
            $this->notifyTaskFailure($event->getTask());
        } catch (\Throwable $e) {
            $this->logger->error('AIquila: failed to send task failure notification', [
                'exception' => $e,
            ]);
        }
    }

    private function notifyTaskFailure(Task $task): void {
        if (!$this->isAiquilaTask($task)) {
            return;
        }

        $userId = $task->getUserId();
        if ($userId === null) {
            return;
        }

        $taskTypeLabel = self::getTaskTypeLabel($task->getTaskTypeId());
        $errorMessage = $task->getErrorMessage() ?? '';
        if (strlen($errorMessage) > 200) {
            $errorMessage = substr($errorMessage, 0, 200) . '…';
        }

        $notification = $this->notificationManager->createNotification();
        $notification->setApp('aiquila')
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('task_processing', (string)$task->getId())
            ->setSubject('task_failure', [$taskTypeLabel, $errorMessage]);

        $this->notificationManager->notify($notification);

        $this->logger->warning('AIquila: Task failure notification sent', [
            'taskId' => $task->getId(),
            'taskType' => $task->getTaskTypeId(),
            'user' => $userId,
            'error' => $errorMessage,
        ]);
    }
}
