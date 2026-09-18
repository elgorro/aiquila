<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

/**
 * Docs task: summarize every document under a folder, writing each summary to
 * `<name>.summary.md` beside the source.
 */
class DocsSummarizeFolderTaskType extends AbstractBatchTextTaskType {

    private const STYLES = ['brief', 'detailed', 'bullets'];
    private const DEFAULT_STYLE = 'brief';

    public function getId(): string {
        return 'docs:summarize';
    }

    public function getLabel(): string {
        return 'Summarize documents (batch)';
    }

    public function validateOptions(array $options): void {
        parent::validateOptions($options);

        if (isset($options['style']) && !in_array($options['style'], self::STYLES, true)) {
            throw new \InvalidArgumentException('style must be one of: ' . implode(', ', self::STYLES));
        }
    }

    protected function buildPrompt(string $content, array $options): string {
        $style = (string)($options['style'] ?? self::DEFAULT_STYLE);
        $instruction = match ($style) {
            'detailed' => 'Write a thorough summary covering every substantive point, in several paragraphs.',
            'bullets'  => 'Write the summary as a flat list of Markdown bullet points, one per key point.',
            default    => 'Write a concise summary of a few sentences.',
        };

        return "Summarize the document below. $instruction "
            . 'Respond with the summary alone in Markdown — no preamble, no title, no commentary '
            . "about the document or the task.\n\nDocument:\n$content";
    }

    protected function outputSuffix(array $options): string {
        return '.summary.md';
    }

    protected function doneTag(): string {
        return 'aiquila:summarized';
    }

    protected function summaryVerb(): string {
        return 'Summarized';
    }
}
