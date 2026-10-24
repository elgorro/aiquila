<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\AudioService;
use OCP\Files\File;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;

/**
 * Audio translation TaskProcessing Provider (core:audio2audio:translate)
 *
 * A spoken recording comes back spoken in another language. Like voice chat
 * this is a chain — transcribe → translate → speak — run on one provider
 * holding both audio capabilities, so the recording and its translation do
 * not leave for two different vendors.
 *
 * The task type is Nextcloud 35+. The id is spelled out rather than read from
 * OCP\TaskProcessing\TaskTypes\AudioToAudioTranslate::ID because that class
 * does not exist on 33 or 34, which the app still declares support for.
 *
 * Input:  input (audio), origin_language, target_language (both Enum)
 * Output: audio_output (raw audio bytes)
 */
class AudioToAudioTranslateProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
        private AudioService $audio,
        private Translation $translation,
    ) {
    }

    public function getId(): string {
        return 'aiquila:audio2audio:translate';
    }

    public function getName(): string {
        return 'AIquila Audio';
    }

    public function getTaskTypeId(): string {
        return 'core:audio2audio:translate';
    }

    public function getExpectedRuntime(): int {
        return 240;
    }

    public function getOptionalInputShape(): array {
        return [
            'provider' => new ShapeDescriptor(
                'Provider',
                'Optional LLM provider id override (e.g. mistral, local)',
                EShapeType::Text
            ),
        ];
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
        $file = $input['input'] ?? null;
        if (!$file instanceof File) {
            throw new \RuntimeException('No audio file provided');
        }
        $target = $input['target_language'] ?? '';
        if (!is_string($target) || $target === '') {
            throw new \RuntimeException('No target language provided');
        }
        $origin = Translation::origin($input['origin_language'] ?? '');

        $provider = $this->providers->resolveVoiceChatCapable($userId, $this->providers->requestedId($input));
        $reportProgress(0.1);

        // The origin language, when known, doubles as a transcription hint.
        $transcript = $this->audio->transcribeRequired($provider, $file, $userId, $origin !== '' ? ['language' => $origin] : []);
        $reportProgress(0.4);

        $translated = $this->translation->translate($provider, $transcript, $origin, $target, $userId);
        if (trim($translated) === '') {
            throw new \RuntimeException('The provider returned an empty translation.');
        }
        $reportProgress(0.7);

        $spoken = $this->audio->speak($provider, $translated, $userId);

        $reportProgress(1.0);
        return ['audio_output' => $spoken];
    }
}
