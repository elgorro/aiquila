<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Dashboard;

use OCA\AIquila\Db\UsageStatMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Dashboard widget summarising token usage for the current user
 * (today / this week / all time).
 */
class UsageWidget extends AbstractAIquilaWidget implements IAPIWidgetV2 {
    private const DAY = 86400;
    private const WEEK = 604800;

    public function __construct(
        IL10N $l10n,
        IURLGenerator $urlGenerator,
        IUserSession $userSession,
        private readonly UsageStatMapper $usageStatMapper,
        private readonly ITimeFactory $timeFactory,
    ) {
        parent::__construct($l10n, $urlGenerator, $userSession);
    }

    public function getId(): string {
        return 'aiquila_usage';
    }

    public function getTitle(): string {
        return $this->l10n->t('AIquila token usage');
    }

    public function getOrder(): int {
        return 30;
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $now = $this->timeFactory->getTime();

        $today = $this->usageStatMapper->sumTokensByUserSince($userId, $now - self::DAY);
        $week = $this->usageStatMapper->sumTokensByUserSince($userId, $now - self::WEEK);
        $total = $this->usageStatMapper->sumTokensByUser($userId);

        $link = $this->appUrl();
        $items = [
            $this->usageItem($this->l10n->t('Today'), $today['input_tokens'] + $today['output_tokens'], $link, '1'),
            $this->usageItem($this->l10n->t('This week'), $week['input_tokens'] + $week['output_tokens'], $link, '2'),
            $this->usageItem($this->l10n->t('All time'), $total['input_tokens'] + $total['output_tokens'], $link, '3'),
        ];

        return new WidgetItems($items, $this->l10n->t('No usage recorded yet'));
    }

    private function usageItem(string $title, int $tokens, string $link, string $sinceId): WidgetItem {
        return new WidgetItem(
            $title,
            $this->l10n->n('%n token', '%n tokens', $tokens),
            $link,
            $this->getIconUrl(),
            $sinceId,
        );
    }
}
