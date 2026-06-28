<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\AppInfo\Application;
use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\Message;
use OCA\AIquila\Db\MessageMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ContextChat\ContentItem;
use OCP\ContextChat\IContentManager;
use OCP\IServerContainer;
use Psr\Log\LoggerInterface;

/**
 * Feeds AIquila conversation history into Nextcloud's Context Chat so the
 * Assistant can answer questions grounded in past conversations.
 *
 * Context Chat is an optional app: the `OCP\ContextChat\*` classes only exist
 * on a server where it is installed. Every public method is therefore a no-op
 * when Context Chat is unavailable, and the manager is resolved lazily so this
 * service (and the app) boot cleanly without it.
 *
 * One Context Chat item is indexed per conversation (item id = conversation id),
 * with the full transcript as content. Re-submitting the same item id is how
 * "content updates when new messages are added" is satisfied, and the single
 * owning user in the access list keeps content private to that user.
 */
class ContextChatService {
    public const PROVIDER_ID = 'conversation';
    private const DOCUMENT_TYPE = 'AIquila Conversation';

    public function __construct(
        private readonly ConversationMapper $conversationMapper,
        private readonly MessageMapper $messageMapper,
        private readonly IServerContainer $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Submit (create or update) the Context Chat item for a single conversation.
     */
    public function indexConversation(int $conversationId): void {
        $manager = $this->getManager();
        if ($manager === null) {
            return;
        }

        try {
            $conversation = $this->conversationMapper->findById($conversationId);
        } catch (DoesNotExistException) {
            // Conversation deleted before the job ran — make sure it is gone.
            $this->removeConversation($conversationId);
            return;
        }

        $messages = $this->messageMapper->findByConversation($conversationId);
        if ($messages === []) {
            return;
        }

        try {
            $manager->submitContent(Application::APP_ID, [$this->buildItem($conversation, $messages)]);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Context Chat: indexing failed', [
                'conversationId' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove the Context Chat item for a conversation (e.g. after deletion).
     */
    public function removeConversation(int $conversationId): void {
        $manager = $this->getManager();
        if ($manager === null) {
            return;
        }

        try {
            $manager->deleteContent(Application::APP_ID, self::PROVIDER_ID, [(string)$conversationId]);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Context Chat: removal failed', [
                'conversationId' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Re-index every conversation. Used by the provider's initial import.
     */
    public function reindexAll(): void {
        if ($this->getManager() === null) {
            return;
        }

        foreach ($this->conversationMapper->findAll() as $conversation) {
            $this->indexConversation($conversation->getId());
        }
    }

    /**
     * @param Message[] $messages
     */
    private function buildItem(Conversation $conversation, array $messages): ContentItem {
        $title = $conversation->getTitle() ?: 'AIquila conversation #' . $conversation->getId();

        $lines = array_map(static function (Message $message): string {
            $role = $message->getRole() === 'assistant' ? 'Assistant' : 'User';
            return $role . ': ' . $message->getContent();
        }, $messages);

        return new ContentItem(
            (string)$conversation->getId(),
            self::PROVIDER_ID,
            $title,
            implode("\n\n", $lines),
            self::DOCUMENT_TYPE,
            (new \DateTime())->setTimestamp($conversation->getUpdatedAt()),
            [$conversation->getUserId()],
        );
    }

    /**
     * Lazily resolve the Context Chat manager, returning null when the optional
     * app is not installed or its content backend is unavailable.
     */
    private function getManager(): ?IContentManager {
        if (!interface_exists(IContentManager::class)) {
            return null;
        }

        try {
            /** @var IContentManager $manager */
            $manager = $this->container->get(IContentManager::class);
        } catch (\Throwable) {
            return null;
        }

        if (!$manager->isContextChatAvailable()) {
            return null;
        }

        return $manager;
    }
}
