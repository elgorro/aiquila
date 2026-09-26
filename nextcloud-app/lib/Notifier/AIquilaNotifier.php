<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Notifier;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

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
            throw new UnknownNotificationException();
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

            case 'ask_response':
                $notification->setParsedSubject(
                    $this->l10n->t('AIquila response')
                );
                // Nextcloud rejects an empty parsed message.
                $response = (string)($params[0] ?? '');
                if ($response !== '') {
                    $notification->setParsedMessage($response);
                }
                break;

            default:
                throw new UnknownNotificationException();
        }

        // Nextcloud only accepts absolute icon URLs (for the desktop and
        // mobile clients) and rejects the whole notification otherwise.
        $notification->setIcon(
            $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('aiquila', 'app-dark.svg'))
        );

        return $notification;
    }
}
