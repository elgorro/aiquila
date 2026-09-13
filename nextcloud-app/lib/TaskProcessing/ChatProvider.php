<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextChat;

/**
 * Chat TaskProcessing Provider (core:text2text:chat)
 *
 * The one task type that carries a conversation rather than a single string:
 * `history` is the turns before this one and `input` is the new user message.
 * That maps onto the provider-neutral chat() call, so unlike the other text
 * providers this one does not flatten the exchange into a prompt.
 */
class ChatProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text:chat';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return TextToTextChat::ID;
    }

    public function getExpectedRuntime(): int {
        return 30;
    }

    public function getOptionalInputShape(): array {
        return [
            'provider' => new ShapeDescriptor(
                'Provider',
                'Optional LLM provider id override (e.g. anthropic, mistral)',
                EShapeType::Text
            ),
        ];
    }

    public function getOptionalOutputShape(): array {
        return [];
    }

    public function getInputShapeEnumValues(): array {
        return [];
    }

    public function getInputShapeDefaults(): array {
        return [];
    }

    public function getOptionalInputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalInputShapeDefaults(): array {
        return [];
    }

    public function getOutputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalOutputShapeEnumValues(): array {
        return [];
    }

    public function process(?string $userId, array $input, callable $reportProgress): array {
        $message = $input['input'] ?? '';
        if (!is_string($message) || $message === '') {
            throw new \RuntimeException('No chat message provided');
        }

        $system = $input['system_prompt'] ?? '';
        $system = is_string($system) ? $system : '';

        $messages = ChatHistory::toMessages($input['history'] ?? []);
        $messages[] = ['role' => 'user', 'content' => $message];

        $reportProgress(0.1);

        $result = $this->providers->resolve($userId, $this->providers->requestedId($input))->chat(
            $messages,
            $system !== '' ? $system : null,
            $userId,
        );

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }
}
