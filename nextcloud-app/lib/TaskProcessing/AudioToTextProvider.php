<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\AudioService;
use OCP\Files\File;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\AudioToText;

/**
 * Transcription TaskProcessing Provider
 *
 * Registers AIquila as a core:audio2text provider, which is what the Assistant's
 * "Transcribe audio" action and Talk's voice-message transcription call.
 *
 * Input:  input (audio file)
 * Output: output (transcript)
 */
class AudioToTextProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
        private AudioService $audio,
    ) {
    }

    public function getId(): string {
        return 'aiquila:audio2text';
    }

    public function getName(): string {
        return 'AIquila Audio';
    }

    public function getTaskTypeId(): string {
        return AudioToText::ID;
    }

    public function getExpectedRuntime(): int {
        return 120;
    }

    public function getOptionalInputShape(): array {
        return [
            'provider' => new ShapeDescriptor(
                'Provider',
                'Optional LLM provider id override (e.g. mistral, local)',
                EShapeType::Text
            ),
            'language' => new ShapeDescriptor(
                'Language',
                'Optional ISO 639-1 code of the spoken language, e.g. de. Improves accuracy when known.',
                EShapeType::Text
            ),
        ];
    }

    public function getOptionalOutputShape(): array {
        return [];
    }

    public function getInputShapeEnumValues(): array {
        return [];
    }

    public function getInputShapeDefaults(): array {
        return [];
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
        // The framework resolves Audio slots to File nodes before calling us —
        // the raw bytes never travel through the task input.
        $file = $input['input'] ?? null;
        if (!$file instanceof File) {
            throw new \RuntimeException('No audio file provided');
        }

        $provider = $this->providers->resolveAudioCapable($userId, $this->providers->requestedId($input));
        $reportProgress(0.1);

        $language = $input['language'] ?? '';
        $transcript = $this->audio->transcribe(
            $provider,
            $file,
            $userId,
            is_string($language) && $language !== '' ? ['language' => $language] : [],
        );

        $reportProgress(1.0);
        return ['output' => $transcript['text']];
    }
}
