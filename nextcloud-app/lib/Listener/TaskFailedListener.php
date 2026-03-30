<?php

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<TaskFailedEvent>
 */
class TaskFailedListener implements IEventListener {

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
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof TaskFailedEvent) {
            return;
        }

        $task = $event->getTask();

        if (!str_starts_with($task->getProviderId(), 'aiquila:')) {
            return;
        }

        $userId = $task->getUserId();
        if ($userId === null) {
            return;
        }

        $taskTypeLabel = TaskSuccessfulListener::getTaskTypeLabel($task->getTaskTypeId());
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
