<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Notifier;

use OCA\AIquila\Service\BatchRunNotifications;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\IAction;
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

            case BatchRunNotifications::SUBJECT_SUCCESS:
            case BatchRunNotifications::SUBJECT_ERROR:
                $this->prepareBatchRun($notification, $params);
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

    /**
     * A manual batch run from Files ended. The parameters are the ones
     * {@see BatchRunNotifications} stores: counts, the error of a failed run,
     * and where its outputs are.
     *
     * @param array<array-key, mixed> $params
     */
    private function prepareBatchRun(INotification $notification, array $params): void {
        $failed = $notification->getSubject() === BatchRunNotifications::SUBJECT_ERROR;
        $operation = match ((string)($params['operation'] ?? '')) {
            'summarize' => $this->l10n->t('Summarize'),
            'translate' => $this->l10n->t('Translate'),
            default => ucfirst((string)($params['operation'] ?? '')),
        };
        $notification->setParsedSubject($failed
            ? $this->l10n->t('AIquila: %s run failed', [$operation])
            : $this->l10n->t('AIquila: %s run finished', [$operation]));

        $total = (int)($params['total'] ?? 0);
        $processed = (int)($params['processed'] ?? 0);
        $failedItems = (int)($params['failed'] ?? 0);
        $skipped = (int)($params['skipped'] ?? 0);

        $counts = [];
        if ($total > 0) {
            // %n is the total; the plural follows it ("1 of 5 documents").
            $counts[] = $this->l10n->n('%1$s of %n document done', '%1$s of %n documents done', $total, [(string)$processed]);
        }
        if ($failedItems > 0) {
            $counts[] = $this->l10n->n('%n failed', '%n failed', $failedItems);
        }
        if ($skipped > 0) {
            $counts[] = $this->l10n->n('%n file skipped', '%n files skipped', $skipped);
        }
        $lines = [];
        $error = (string)($params['error'] ?? '');
        if ($failed && $error !== '') {
            $lines[] = $error;
        }
        if ($counts !== []) {
            $lines[] = implode(', ', $counts);
        } elseif (!$failed) {
            $lines[] = $this->l10n->t('Nothing to do: no text documents that still need work.');
        }
        // Nextcloud rejects an empty parsed message.
        $notification->setParsedMessage($lines === [] ? $this->l10n->t('The run ended without a result.') : implode("\n", $lines));

        $runId = (int)($params['runId'] ?? 0);
        $runLink = $this->urlGenerator->linkToRouteAbsolute('aiquila.page.index') . '#/cowork?run=' . $runId;
        $notification->setLink($runLink);

        $resultsLink = null;
        $fileId = $params['outputFileId'] ?? null;
        if (is_int($fileId) && $fileId > 0) {
            $resultsLink = $this->urlGenerator->linkToRouteAbsolute('files.view.showFile', ['fileid' => $fileId]);
        } elseif (is_string($params['outputDir'] ?? null) && $params['outputDir'] !== '') {
            $resultsLink = $this->urlGenerator->linkToRouteAbsolute('files.view.index', ['dir' => $params['outputDir']]);
        }
        if ($resultsLink !== null) {
            $results = $notification->createAction();
            $results->setParsedLabel($this->l10n->t('Show results'))
                ->setLink($resultsLink, IAction::TYPE_WEB)
                ->setPrimary(true);
            $notification->addParsedAction($results);
        }
        $details = $notification->createAction();
        $details->setParsedLabel($this->l10n->t('View run'))
            ->setLink($runLink, IAction::TYPE_WEB)
            ->setPrimary($resultsLink === null);
        $notification->addParsedAction($details);
    }
}
