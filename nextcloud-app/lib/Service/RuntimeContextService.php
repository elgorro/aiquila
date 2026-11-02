<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\Message;
use OCA\AIquila\Db\MessageMapper;
use OCP\IDateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Tells the model what it cannot know on its own — today's date in the user's
 * time zone — as a `system` message in the conversation.
 *
 * The note is stored as a message row, written only when it changes (the
 * first turn, a new day, a new time zone), and replayed with the history on
 * every later turn. That keeps each request a pure extension of the previous
 * one: nothing earlier in the conversation changes, so the prompt cache keeps
 * matching. Putting the date in the top-level system prompt instead would
 * invalidate the whole cached conversation every day.
 *
 * Providers decide how the row is sent; see Provider\SystemMessages.
 */
class RuntimeContextService {
    public function __construct(
        private MessageMapper $messageMapper,
        private IDateTimeZone $dateTimeZone,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The note for $userId at $now. Without a user (a Talk guest or federated
     * actor) it uses the instance's default time zone and says so.
     */
    public function note(?string $userId, int $now): string {
        $zone = $this->dateTimeZone->getTimeZone($now, $userId);
        $date = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
        return 'Today is ' . $date->format('l, j F Y') . '. '
            . ($userId !== null ? 'The user\'s' : 'The server\'s') . ' time zone is ' . $zone->getName() . '.';
    }

    /**
     * Append the note to the conversation unless it is already the latest one.
     * Call right after storing the user's message, so the note follows it.
     * Best-effort: a failure here never blocks the chat turn.
     */
    public function recordIfChanged(int $conversationId, string $userId, int $now): void {
        try {
            $note = $this->note($userId, $now);
            if ($this->messageMapper->findLatestSystem($conversationId)?->getContent() === $note) {
                return;
            }

            $row = new Message();
            $row->setConversationId($conversationId);
            $row->setRole('system');
            $row->setContent($note);
            $row->setCreatedAt($now);
            $this->messageMapper->insert($row);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila: could not record the runtime context note', [
                'conversationId' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
