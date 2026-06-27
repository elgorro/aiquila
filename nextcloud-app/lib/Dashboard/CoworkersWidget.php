<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Dashboard;

use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Service\CoworkerService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Dashboard widget showing the user's coworkers (recurring AI tasks) with their
 * current status and next scheduled run.
 */
class CoworkersWidget extends AbstractAIquilaWidget implements IAPIWidgetV2, IButtonWidget {
    public function __construct(
        IL10N $l10n,
        IURLGenerator $urlGenerator,
        IUserSession $userSession,
        private readonly CoworkerService $coworkerService,
        private readonly IDateTimeFormatter $dateTimeFormatter,
    ) {
        parent::__construct($l10n, $urlGenerator, $userSession);
    }

    public function getId(): string {
        return 'aiquila_coworkers';
    }

    public function getTitle(): string {
        return $this->l10n->t('AIquila coworkers');
    }

    public function getOrder(): int {
        return 20;
    }

    protected function hashRoute(): string {
        return '/cowork';
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $coworkers = $this->coworkerService->listForUser($userId);

        $items = [];
        foreach (array_slice($coworkers, 0, $limit) as $coworker) {
            $items[] = new WidgetItem(
                $coworker->getTitle(),
                $this->statusSubtitle($coworker),
                $this->appUrl('/cowork'),
                $this->getIconUrl(),
                (string)$coworker->getUpdatedAt(),
            );
        }

        return new WidgetItems(
            $items,
            $this->l10n->t('No coworkers yet'),
        );
    }

    public function getWidgetButtons(string $userId): array {
        return [
            new WidgetButton(
                WidgetButton::TYPE_MORE,
                $this->appUrl('/cowork'),
                $this->l10n->t('All coworkers'),
            ),
        ];
    }

    private function statusSubtitle(Coworker $coworker): string {
        if ($coworker->getPaused() === 1) {
            return $this->l10n->t('Paused');
        }
        if ($coworker->getLastStatus() === 'error') {
            $error = $coworker->getLastError();
            return $error !== null && $error !== ''
                ? $this->l10n->t('Error: %s', [$error])
                : $this->l10n->t('Last run failed');
        }
        $nextRun = $coworker->getNextRunAt();
        if ($nextRun !== null && $nextRun > 0) {
            return $this->l10n->t('Next run %s', [$this->dateTimeFormatter->formatTimeSpan($nextRun)]);
        }
        return $this->l10n->t('Scheduled');
    }
}
