<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Search;

use OCA\AIquila\AppInfo\Application;
use OCA\AIquila\Db\Message;
use OCA\AIquila\Db\MessageMapper;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class AiquilaSearchProvider implements IProvider {
    public function __construct(
        private MessageMapper $messageMapper,
        private IURLGenerator $urlGenerator,
        private IL10N $l10n,
    ) {
    }

    public function getId(): string {
        return 'aiquila';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getOrder(string $route, array $routeParameters): int {
        return 10;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = $query->getTerm();
        $limit = $query->getLimit();
        $cursor = (int)$query->getCursor();

        $messages = $this->messageMapper->search($user->getUID(), $term, $limit, $cursor);

        $iconUrl = $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');

        $entries = array_map(function (Message $message) use ($iconUrl): SearchResultEntry {
            $content = $message->getContent();
            $title = mb_strlen($content) > 80
                ? mb_substr($content, 0, 80) . '…'
                : $content;

            $role = $message->getRole() === 'assistant'
                ? $this->l10n->t('Assistant')
                : $this->l10n->t('You');
            $date = new \DateTime('@' . $message->getCreatedAt());
            $subline = $role . ' · ' . $date->format('Y-m-d H:i');

            $resourceUrl = $this->urlGenerator->linkToRoute('aiquila.page.index')
                . '#/conversations/' . $message->getConversationId();

            return new SearchResultEntry(
                $iconUrl,
                $title,
                $subline,
                $resourceUrl,
                '',
                true,
            );
        }, $messages);

        $lastCursor = null;
        if (count($messages) >= $limit) {
            $lastMessage = end($messages);
            $lastCursor = (string)$lastMessage->getId();
        }

        return $lastCursor !== null
            ? SearchResult::paginated($this->getName(), $entries, $lastCursor)
            : SearchResult::complete($this->getName(), $entries);
    }
}
