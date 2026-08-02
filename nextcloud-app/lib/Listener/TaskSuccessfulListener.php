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

    private static array $typeLabels = [
        'core:text2text' => 'Text generation',
        'core:text2text:summary' => 'Summarization',
        'core:text2text:headline' => 'Headline generation',
        'core:text2text:topics' => 'Topic extraction',
        'core:text2text:translate' => 'Translation',
        'core:text2text:proofread' => 'Proofreading',
        'core:text2text:reformulate' => 'Reformulation',
        'core:text2text:formalize' => 'Formalization',
        'core:text2text:simplify' => 'Simplification',
        'core:text2text:change-tone' => 'Tone adjustment',
        'core:image2text' => 'Image analysis',
        'core:analyze-images' => 'Multi-image analysis',
    ];

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
        if (!self::isAiquilaTask($this->taskProcessingManager, $task)) {
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

    /**
     * OCP\TaskProcessing\Task exposes no provider id, so the provider is resolved
     * through the manager to tell whether an aiquila provider handled the task.
     */
    public static function isAiquilaTask(ITaskProcessingManager $taskProcessingManager, Task $task): bool {
        try {
            $provider = $taskProcessingManager->getPreferredProvider($task->getTaskTypeId());
        } catch (\OCP\TaskProcessing\Exception\Exception) {
            // No provider for this task type -> not ours. Unexpected throwables
            // propagate to handle() above, which logs rather than hides them.
            return false;
        }

        return str_starts_with($provider->getId(), 'aiquila:');
    }

    public static function getTaskTypeLabel(string $taskTypeId): string {
        if (isset(self::$typeLabels[$taskTypeId])) {
            return self::$typeLabels[$taskTypeId];
        }

        $parts = explode(':', $taskTypeId);
        return ucfirst(end($parts));
    }
}
