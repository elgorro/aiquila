<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\TaskProcessing\ShapeEnumValue;

/**
 * Translation as the translate task types share it: the language lists and
 * the prompt.
 *
 * Both core:text2text:translate and core:audio2audio:translate declare their
 * languages as Enum slots, so neither is usable without an enum list: core
 * validates input against it, and the Assistant reads the first origin
 * language as its default.
 */
class Translation {

    public const DETECT_LANGUAGE = 'detect_language';

    /** @var array<string, string>|null language code => display name */
    private ?array $languages = null;

    public function __construct(
        private IFactory $l10nFactory,
        private IL10N $l,
    ) {
    }

    /**
     * Enum values for `origin_language` (with detection first) and
     * `target_language`.
     *
     * @return array<string, list<ShapeEnumValue>>
     */
    public function enumValues(): array {
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

    /** @return array<string, string> */
    public function defaults(): array {
        return ['origin_language' => self::DETECT_LANGUAGE];
    }

    /**
     * The origin language as given, or '' when it is to be detected.
     */
    public static function origin(mixed $originLanguage): string {
        return is_string($originLanguage) && $originLanguage !== self::DETECT_LANGUAGE ? $originLanguage : '';
    }

    /**
     * @param string $origin a language code or name, '' to leave the source to the model
     * @throws \RuntimeException
     */
    public function translate(LLMProviderInterface $provider, string $text, string $origin, string $target, ?string $userId): string {
        $fromClause = $origin !== '' ? ' from ' . $this->languageName($origin) : '';
        $to = $this->languageName($target);
        $result = $provider->ask(
            "Translate the following text{$fromClause} to {$to}. Return only the translated text, nothing else:\n\n" . $text,
            '',
            $userId,
            LLMProviderInterface::TASK_OPTIONS,
        );

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return $result['response'] ?? '';
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
