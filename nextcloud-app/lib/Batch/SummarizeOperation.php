<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

/**
 * Summarize each document, writing the summary to `<file>.summary.md`.
 */
final class SummarizeOperation implements BatchOperation {
    public const STYLES = ['brief', 'detailed', 'bullets'];
    private const DEFAULT_STYLE = 'brief';

    public function getId(): string {
        return 'summarize';
    }

    public function getLabel(): string {
        return 'Summarize';
    }

    public function optionNames(): array {
        return ['style'];
    }

    public function validateOptions(array $options): void {
        if (isset($options['style']) && !in_array($options['style'], self::STYLES, true)) {
            throw new \InvalidArgumentException('style must be one of: ' . implode(', ', self::STYLES));
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
        $style = (string)($options['style'] ?? self::DEFAULT_STYLE);
        $instruction = match ($style) {
            'detailed' => 'Write a thorough summary covering every substantive point, in several paragraphs.',
            'bullets'  => 'Write the summary as a flat list of Markdown bullet points, one per key point.',
            default    => 'Write a concise summary of a few sentences.',
        };

        return "Summarize $document. $instruction "
            . 'Respond with the summary alone in Markdown — no preamble, no title, no commentary '
            . 'about the document or the task.';
    }

    public function outputSuffix(array $options): string {
        return '.summary.md';
    }

    public function doneTag(array $options = []): string {
        return 'aiquila:summarized';
    }

    public function summaryVerb(): string {
        return 'Summarized';
    }
}
