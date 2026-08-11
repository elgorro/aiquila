<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<TaskSuccessfulEvent>
 */
class TaskSuccessfulListener implements IEventListener {
    use TaskListenerTrait;

    public function __construct(
        private INotificationManager $notificationManager,
        private ITaskProcessingManager $taskProcessingManager,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof TaskSuccessfulEvent) {
            return;
        }

        // Apps load alphabetically, so this listener runs before Assistant's in
        // the event chain; an uncaught throwable here would abort the chain and
        // stop Assistant persisting its chat reply. Never let one escape.
        try {
            $this->notifyTaskSuccess($event->getTask());
        } catch (\Throwable $e) {
            $this->logger->error('AIquila: failed to send task success notification', [
                'exception' => $e,
            ]);
        }
    }

    private function notifyTaskSuccess(Task $task): void {
        if (!$this->isAiquilaTask($task)) {
            return;
        }

        $userId = $task->getUserId();
        if ($userId === null) {
            return;
        }

        $taskTypeLabel = self::getTaskTypeLabel($task->getTaskTypeId());

        $notification = $this->notificationManager->createNotification();
        $notification->setApp('aiquila')
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('task_processing', (string)$task->getId())
            ->setSubject('task_success', [$taskTypeLabel]);

        $this->notificationManager->notify($notification);

        $this->logger->debug('AIquila: Task completion notification sent', [
            'taskId' => $task->getId(),
            'taskType' => $task->getTaskTypeId(),
            'user' => $userId,
        ]);
    }
}
