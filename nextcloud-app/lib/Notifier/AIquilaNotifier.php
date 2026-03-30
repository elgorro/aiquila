<?php

declare(strict_types=1);

namespace OCA\AIquila\Notifier;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class AIquilaNotifier implements INotifier {

    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l10n,
    ) {
    }

    public function getID(): string {
        return 'aiquila';
    }

    public function getName(): string {
        return $this->l10n->t('AIquila');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'aiquila') {
            throw new \InvalidArgumentException();
        }

        $params = $notification->getSubjectParameters();

        switch ($notification->getSubject()) {
            case 'task_success':
                $taskType = $params[0] ?? 'AI';
                $notification->setParsedSubject(
                    $this->l10n->t('AIquila task completed')
                );
                $notification->setParsedMessage(
                    $this->l10n->t('Your %s task has completed successfully.', [$taskType])
                );
                break;

            case 'task_failure':
                $taskType = $params[0] ?? 'AI';
                $error = $params[1] ?? '';
                $notification->setParsedSubject(
                    $this->l10n->t('AIquila task failed')
                );
                $message = $error
                    ? $this->l10n->t('Your %1$s task failed: %2$s', [$taskType, $error])
                    : $this->l10n->t('Your %s task failed.', [$taskType]);
                $notification->setParsedMessage($message);
                break;

            default:
                throw new \InvalidArgumentException();
        }

        $notification->setIcon(
            $this->urlGenerator->imagePath('aiquila', 'app-dark.svg')
        );

        return $notification;
    }
}
