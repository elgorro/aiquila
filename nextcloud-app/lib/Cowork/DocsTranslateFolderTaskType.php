<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

/**
 * Docs task: translate every document under a folder, writing each translation
 * to `<name>.<language>.md` beside the source.
 */
class DocsTranslateFolderTaskType extends AbstractBatchTextTaskType {

    public function getId(): string {
        return 'docs:translate';
    }

    public function getLabel(): string {
        return 'Translate documents (batch)';
    }

    public function validateOptions(array $options): void {
        parent::validateOptions($options);

        $language = $options['targetLanguage'] ?? null;
        if (!is_string($language) || preg_match('/^[A-Za-z][A-Za-z \-]{1,31}$/', $language) !== 1) {
            throw new \InvalidArgumentException(
                'targetLanguage is required and must be a language name such as "German"'
            );
        }
    }

    protected function buildPrompt(string $content, array $options): string {
        $language = (string)($options['targetLanguage'] ?? '');

        return "Translate the document below into $language. Preserve its structure, "
            . 'Markdown formatting and any code blocks exactly; translate prose only. '
            . "Respond with the translation alone — no preamble and no notes.\n\nDocument:\n$content";
    }

    protected function outputSuffix(array $options): string {
        // Spaces and case would make for awkward filenames, and two coworkers
        // targeting "Brazilian Portuguese" and "brazilian-portuguese" should
        // land on the same output rather than quietly duplicating the work.
        $language = strtolower(str_replace(' ', '-', (string)($options['targetLanguage'] ?? 'translated')));
        return '.' . $language . '.md';
    }

    protected function doneTag(): string {
        return 'aiquila:translated';
    }

    protected function summaryVerb(): string {
        return 'Translated';
    }
}
