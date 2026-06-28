<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Dashboard;

use OCA\AIquila\Db\ConversationMapper;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Dashboard widget listing the current user's most recent conversations.
 */
class ConversationsWidget extends AbstractAIquilaWidget implements IAPIWidgetV2 {
    private const MAX_ITEMS = 5;

    public function __construct(
        IL10N $l10n,
        IURLGenerator $urlGenerator,
        IUserSession $userSession,
        private readonly ConversationMapper $conversationMapper,
        private readonly IDateTimeFormatter $dateTimeFormatter,
    ) {
        parent::__construct($l10n, $urlGenerator, $userSession);
    }

    public function getId(): string {
        return 'aiquila_conversations';
    }

    public function getTitle(): string {
        return $this->l10n->t('AIquila conversations');
    }

    public function getOrder(): int {
        return 10;
    }

    protected function hashRoute(): string {
        return '/chat';
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $conversations = $this->conversationMapper->findAllByUser($userId);
        $conversations = array_slice($conversations, 0, min($limit, self::MAX_ITEMS));

        $items = [];
        foreach ($conversations as $conversation) {
            $title = $conversation->getTitle();
            if ($title === null || $title === '') {
                $title = $this->l10n->t('Untitled conversation');
            }
            $items[] = new WidgetItem(
                $title,
                $this->dateTimeFormatter->formatTimeSpan($conversation->getUpdatedAt()),
                $this->appUrl('/chat/' . $conversation->getId()),
                $this->getIconUrl(),
                (string)$conversation->getUpdatedAt(),
            );
        }

        return new WidgetItems(
            $items,
            $this->l10n->t('No conversations yet'),
        );
    }
}
