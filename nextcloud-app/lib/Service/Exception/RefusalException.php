<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Exception;

/**
 * The model's safety classifiers declined the request. The API reports this
 * as a successful response with `stop_reason: "refusal"`, not as an HTTP
 * error, so it is raised from the response and mapped back to an error
 * result. Any partial output is incomplete and must be discarded.
 *
 * `$category` (`cyber`, `bio`, `frontier_llm`, `reasoning_extraction`,
 * `general_harms`) and `$explanation` may both be null: that is a normal,
 * permanent value for a refusal outside the named categories.
 *
 * @see https://platform.claude.com/docs/en/build-with-claude/refusals-and-fallback#refusal-response
 */
class RefusalException extends \RuntimeException {
    public const DEFAULT_MESSAGE = 'The request was declined by the model\'s safety classifiers.';

    /**
     * @param array<string, mixed> $usage tokens the declined attempt reported; some refusals are billed
     */
    public function __construct(
        public readonly ?string $category,
        public readonly ?string $explanation,
        public readonly array $usage = [],
    ) {
        parent::__construct($explanation ?? self::DEFAULT_MESSAGE);
    }

    /**
     * The error result every ClaudeSDKService entry point returns for a
     * refusal. `error_type` matches the Batch API path.
     *
     * @return array{error: string, error_type: 'refused', refusal_category: string|null, usage: array<string, mixed>}
     */
    public function toResult(): array {
        return [
            'error' => $this->getMessage(),
            'error_type' => 'refused',
            'refusal_category' => $this->category,
            'usage' => $this->usage,
        ];
    }
}
