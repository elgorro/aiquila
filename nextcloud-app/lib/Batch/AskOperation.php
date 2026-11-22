<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

/**
 * Ask a question the user wrote of each document, writing the answer to
 * `<name>.<outputSuffix>.md`.
 *
 * The done-tag names the question: `aiquila:asked-<hash>`, a hash of the
 * prompt and the suffix. A source counts as done only while it carries the
 * tag of the current question, so editing the prompt reprocesses every file,
 * where an output that merely exists would leave them alone. Two coworkers
 * that ask the same thing share the tag.
 */
final class AskOperation implements BatchOperation, PromptScopedOperation {
    public const MAX_PROMPT_LENGTH = 4000;

    /**
     * Suffixes that would read as a file type of their own (`report.pdf.md`
     * is confusing at best), or that another operation writes.
     */
    private const RESERVED_SUFFIXES = [
        'summary', 'md', 'markdown', 'txt', 'text', 'json', 'csv', 'xml', 'html', 'htm', 'pdf',
        'doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp', 'jpg', 'jpeg', 'png',
    ];

    public function getId(): string {
        return 'ask';
    }

    public function getLabel(): string {
        return 'Ask a question';
    }

    public function optionNames(): array {
        return ['prompt', 'outputSuffix'];
    }

    public function validateOptions(array $options): void {
        $prompt = $options['prompt'] ?? null;
        if (!is_string($prompt) || trim($prompt) === '') {
            throw new \InvalidArgumentException('prompt is required: the question to ask of each document');
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            throw new \InvalidArgumentException('prompt must be at most ' . self::MAX_PROMPT_LENGTH . ' characters');
        }
        $suffix = $options['outputSuffix'] ?? null;
        // Letters, digits and hyphens: no dots, slashes or backslashes, so the
        // output stays one file beside its source and always ends in `.md`.
        if (!is_string($suffix) || preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $suffix) !== 1) {
            throw new \InvalidArgumentException(
                'outputSuffix is required: 1 to 32 lowercase letters, digits or hyphens, such as "risks"'
            );
        }
        if (in_array($suffix, self::RESERVED_SUFFIXES, true)) {
            throw new \InvalidArgumentException('outputSuffix "' . $suffix . '" looks like a file type or another operation\'s output; choose a word such as "risks"');
        }
    }

    public function buildPrompt(string $content, array $options): string {
        return $this->instruction('the document below', $options) . "\n\nDocument:\n$content";
    }

    public function buildDocumentPrompt(array $options): string {
        return $this->instruction('the attached document', $options);
    }

    /** @param array<string, mixed> $options */
    private function instruction(string $document, array $options): string {
        $prompt = trim((string)($options['prompt'] ?? ''));
        return "Answer the request below about $document. Respond with the answer alone in Markdown — "
            . "no preamble and no commentary about the task.\n\nRequest:\n$prompt";
    }

    public function outputSuffix(array $options): string {
        return '.' . (string)($options['outputSuffix'] ?? 'answer') . '.md';
    }

    public function doneTag(array $options = []): string {
        $key = trim((string)($options['prompt'] ?? '')) . "\0" . (string)($options['outputSuffix'] ?? '');
        return 'aiquila:asked-' . substr(hash('sha256', $key), 0, 8);
    }

    public function summaryVerb(): string {
        return 'Answered';
    }
}
