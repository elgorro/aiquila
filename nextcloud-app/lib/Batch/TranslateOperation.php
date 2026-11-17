<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

/**
 * Translate each document, writing the translation to `<name>.<language>.md`.
 */
final class TranslateOperation implements BatchOperation {
    public function getId(): string {
        return 'translate';
    }

    public function getLabel(): string {
        return 'Translate';
    }

    public function optionNames(): array {
        return ['targetLanguage'];
    }

    public function validateOptions(array $options): void {
        $language = $options['targetLanguage'] ?? null;
        if (!is_string($language) || preg_match('/^[A-Za-z][A-Za-z \-]{1,31}$/', $language) !== 1) {
            throw new \InvalidArgumentException(
                'targetLanguage is required and must be a language name such as "German"'
            );
        }
    }

    public function buildPrompt(string $content, array $options): string {
        $language = (string)($options['targetLanguage'] ?? '');

        return "Translate the document below into $language. Preserve its structure, "
            . 'Markdown formatting and any code blocks exactly; translate prose only. '
            . "Respond with the translation alone — no preamble and no notes.\n\nDocument:\n$content";
    }

    public function outputSuffix(array $options): string {
        // Spaces and case would make for awkward filenames, and two runs
        // targeting "Brazilian Portuguese" and "brazilian-portuguese" should
        // land on the same output rather than quietly duplicating the work.
        $language = strtolower(str_replace(' ', '-', (string)($options['targetLanguage'] ?? 'translated')));
        return '.' . $language . '.md';
    }

    public function doneTag(): string {
        return 'aiquila:translated';
    }

    public function summaryVerb(): string {
        return 'Translated';
    }
}
