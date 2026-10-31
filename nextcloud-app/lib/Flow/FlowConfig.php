<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Flow;

/**
 * The value a Flow rule stores for the AIquila operation: which action to run
 * and, for `ask`, the prompt. Stored as JSON in the rule's operation field; an
 * empty value (a rule saved without touching the options) means `auto`.
 */
final class FlowConfig {
    /** Summarize text and PDFs, describe images. */
    public const ACTION_AUTO = 'auto';
    public const ACTION_SUMMARIZE = 'summarize';
    public const ACTION_DESCRIBE = 'describe';
    /** Run the rule's own prompt against the file. */
    public const ACTION_ASK = 'ask';

    public const ACTIONS = [self::ACTION_AUTO, self::ACTION_SUMMARIZE, self::ACTION_DESCRIBE, self::ACTION_ASK];

    /** Longest prompt a rule may carry. */
    public const MAX_PROMPT_LENGTH = 4000;

    private function __construct(
        public readonly string $action,
        public readonly string $prompt,
    ) {
    }

    /**
     * @throws \UnexpectedValueException when the stored value is not a valid configuration
     */
    public static function parse(string $operation): self {
        if (trim($operation) === '') {
            return new self(self::ACTION_AUTO, '');
        }

        $data = json_decode($operation, true);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('The AIquila operation settings are not valid JSON');
        }

        $action = $data['action'] ?? self::ACTION_AUTO;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            throw new \UnexpectedValueException('Unknown AIquila action');
        }

        $prompt = $data['prompt'] ?? '';
        $prompt = is_string($prompt) ? trim($prompt) : '';
        if ($action === self::ACTION_ASK && $prompt === '') {
            throw new \UnexpectedValueException('The "Ask" action needs a prompt');
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            throw new \UnexpectedValueException('The prompt is longer than ' . self::MAX_PROMPT_LENGTH . ' characters');
        }

        return new self($action, $action === self::ACTION_ASK ? $prompt : '');
    }

    /** @return array{action: string, prompt: string} */
    public function toArray(): array {
        return ['action' => $this->action, 'prompt' => $this->prompt];
    }
}
