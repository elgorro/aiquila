<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;

/**
 * Everything {@see BatchDocumentRunner} needs to know about one run, and
 * nothing about what started it.
 *
 * A coworker builds one from its row; a manual run from the request that
 * started it. The runner never sees a {@see \OCA\AIquila\Db\Coworker}.
 */
final class BatchContext {
    /**
     * @param string $userId whose files are read and written, and who is billed
     * @param array<string, mixed> $options the run's options: the shared ones
     *        ({@see BatchDocumentRunner::validateOptions()}) plus the operation's
     * @param \Closure(LLMProviderInterface): array<string, mixed> $requestOptions
     *        per-request options for the provider the run ends up on
     * @param string|null $providerId the provider to ask for; null is the user's default
     * @param string $outputFolder where outputs go, as a path in the user's
     *        Files; empty writes each one beside its source
     * @param array<string, mixed> $logContext added to every server log line
     */
    public function __construct(
        public readonly string $userId,
        public readonly BatchOperation $operation,
        public readonly array $options,
        public readonly \Closure $requestOptions,
        public readonly ?string $providerId = null,
        public readonly string $outputFolder = '',
        public readonly array $logContext = [],
        public readonly string $usageType = UsageStatService::TYPE_COWORKER,
    ) {
    }

    /** @return list<string> */
    public function mimeTypes(): array {
        $configured = $this->options['mimeTypes'] ?? null;
        return is_array($configured) && $configured !== []
            ? array_values(array_filter($configured, 'is_string'))
            : BatchDocumentRunner::DEFAULT_MIME_TYPES;
    }

    public function recursive(): bool {
        return (bool)($this->options['recursive'] ?? true);
    }

    public function force(): bool {
        return (bool)($this->options['force'] ?? false);
    }

    public function maxBytes(): int {
        return (int)($this->options['maxBytesPerFile'] ?? BatchDocumentRunner::DEFAULT_MAX_BYTES);
    }

    public function suffix(): string {
        return $this->operation->outputSuffix($this->options);
    }
}
