<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\BackgroundJob;

use OCA\AIquila\Service\InstructionsService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Talk\TalkBotService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Answers one message that addressed the AIquila Talk bot.
 *
 * The buffered conversation up to and including that message becomes the
 * context, the asking user's provider produces the answer (guests get the
 * instance default), and the answer is posted as a reply to the message.
 * The admin's instance instructions, when enabled for Talk, go in front of the
 * bot's own prompt rather than replacing it.
 *
 * Argument: {token, messageId, userId: ?string, roomName}.
 */
class TalkBotReplyJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private TalkBotService $bot,
        private LLMProviderFactory $providers,
        private LoggerInterface $logger,
        private InstructionsService $instructions,
    ) {
        parent::__construct($time);
    }

    /** @param mixed $argument */
    protected function run($argument): void {
        if (!is_array($argument) || !is_string($argument['token'] ?? null) || !is_int($argument['messageId'] ?? null)) {
            return;
        }
        $token = $argument['token'];
        $messageId = $argument['messageId'];
        $userId = is_string($argument['userId'] ?? null) ? $argument['userId'] : null;
        $roomName = is_string($argument['roomName'] ?? null) ? $argument['roomName'] : '';

        try {
            $provider = $this->providers->getProviderForUser($userId, null);
            $result = $provider->chat(
                $this->buildMessages($token, $messageId),
                $this->instructions->withInstance(InstructionsService::SCOPE_TALK, $userId, $this->systemPrompt($roomName)),
                $userId,
                LLMProviderInterface::TASK_OPTIONS,
            );
            if (isset($result['error'])) {
                throw new \RuntimeException((string)$result['error']);
            }
            $answer = trim((string)($result['response'] ?? ''));
            if ($answer === '') {
                return;
            }
            $this->bot->postReply($token, $answer, $messageId);
            $this->bot->remember($token, 0, TalkBotService::NAME, $answer, true);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Talk bot: could not answer', ['token' => $token, 'error' => $e->getMessage()]);
            try {
                $this->bot->postReply($token, 'Sorry, I could not answer that right now.', $messageId);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * The buffered conversation as alternating turns: other people's messages
     * are user turns prefixed with their name, the bot's own are assistant
     * turns. Messages after the one being answered are left out.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $token, int $messageId): array {
        $turns = [];
        foreach ($this->bot->recentMessages($token) as $row) {
            $turns[] = $row->getFromBot()
                ? ['role' => 'assistant', 'content' => $row->getContent()]
                : ['role' => 'user', 'content' => $row->getActorName() . ': ' . $row->getContent()];
            if ($row->getMessageId() === $messageId) {
                break;
            }
        }
        // A conversation has to start with the user.
        while ($turns !== [] && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        // Consecutive messages from the same side become one turn.
        $messages = [];
        foreach ($turns as $turn) {
            $n = count($messages);
            if ($n > 0 && $messages[$n - 1]['role'] === $turn['role']) {
                $messages[$n - 1] = ['role' => $turn['role'], 'content' => $messages[$n - 1]['content'] . "\n" . $turn['content']];
            } else {
                $messages[] = $turn;
            }
        }
        return $messages;
    }

    private function systemPrompt(string $roomName): string {
        return 'You are AIquila, an assistant taking part in the Nextcloud Talk conversation'
            . ($roomName !== '' ? ' "' . $roomName . '"' : '') . '. '
            . 'Each user message starts with the sender\'s name. Answer the latest message that addresses you '
            . '(it mentions @AIquila or replies to you), using the earlier messages as context. '
            . 'Keep the answer short and conversational; Markdown is rendered.';
    }
}
