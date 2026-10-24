<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;

/**
 * Translate TaskProcessing Provider (core:text2text:translate)
 *
 * The language lists and the prompt are shared with the audio translation
 * provider through Translation.
 */
class TranslateProvider implements ISynchronousProvider {

    public const DETECT_LANGUAGE = Translation::DETECT_LANGUAGE;

    public function __construct(
        private ProviderResolver $providers,
        private Translation $translation,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text:translate';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return TextToTextTranslate::ID;
    }

    public function getExpectedRuntime(): int {
        return 30;
    }

    public function getOptionalInputShape(): array {
        return [];
    }

    public function getOptionalOutputShape(): array {
        return [];
    }

    public function getInputShapeEnumValues(): array {
        return $this->translation->enumValues();
    }

    public function getInputShapeDefaults(): array {
        return $this->translation->defaults();
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
        $text = $input['input'] ?? '';
        $targetLanguage = $input['target_language'] ?? '';

        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('No input text provided');
        }
        if (!is_string($targetLanguage) || $targetLanguage === '') {
            throw new \RuntimeException('No target language provided');
        }

        $reportProgress(0.1);

        return ['output' => $this->translation->translate(
            $this->providers->resolve($userId),
            $text,
            Translation::origin($input['origin_language'] ?? ''),
            $targetLanguage,
            $userId,
        )];
    }
}
