<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;

/**
 * Translate TaskProcessing Provider (core:text2text:translate)
 *
 * Both language inputs are Enum slots, so the task type is unusable without
 * an enum list: core validates input against it, and the Assistant reads the
 * first origin language as its default.
 */
class TranslateProvider implements ISynchronousProvider {

    public const DETECT_LANGUAGE = 'detect_language';

    /** @var array<string, string>|null language code => display name */
    private ?array $languages = null;

    public function __construct(
        private ProviderResolver $providers,
        private IFactory $l10nFactory,
        private IL10N $l,
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
        $languages = [];
        foreach ($this->languages() as $code => $name) {
            $languages[] = new ShapeEnumValue($name, $code);
        }
        return [
            'origin_language' => [
                new ShapeEnumValue($this->l->t('Detect language'), self::DETECT_LANGUAGE),
                ...$languages,
            ],
            'target_language' => $languages,
        ];
    }

    public function getInputShapeDefaults(): array {
        return ['origin_language' => self::DETECT_LANGUAGE];
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
        $originLanguage = $input['origin_language'] ?? '';
        $targetLanguage = $input['target_language'] ?? '';

        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('No input text provided');
        }
        if (!is_string($targetLanguage) || $targetLanguage === '') {
            throw new \RuntimeException('No target language provided');
        }
        if (!is_string($originLanguage) || $originLanguage === self::DETECT_LANGUAGE) {
            $originLanguage = '';
        }

        $reportProgress(0.1);

        $fromClause = $originLanguage !== '' ? ' from ' . $this->languageName($originLanguage) : '';
        $to = $this->languageName($targetLanguage);
        $result = $this->providers->resolve($userId)->ask(
            "Translate the following text{$fromClause} to {$to}. Return only the translated text, nothing else:\n\n" . $text,
            '',
            $userId,
            LLMProviderInterface::TASK_OPTIONS,
        );

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }

    /**
     * The server's languages, as the language picker in personal settings
     * lists them: native names keyed by code.
     *
     * @return array<string, string>
     */
    private function languages(): array {
        if ($this->languages === null) {
            $this->languages = [];
            $all = $this->l10nFactory->getLanguages();
            foreach (['commonLanguages', 'otherLanguages'] as $group) {
                $list = $all[$group] ?? [];
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $language) {
                    if (is_array($language) && is_string($language['code'] ?? null) && is_string($language['name'] ?? null)) {
                        $this->languages[$language['code']] ??= $language['name'];
                    }
                }
            }
        }
        return $this->languages;
    }

    /**
     * A code from the enum becomes its name for the prompt; anything else (a
     * caller passing "German" through the OCS API) is used as given.
     */
    private function languageName(string $language): string {
        return $this->languages()[$language] ?? $language;
    }
}
