<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\AudioService;
use OCA\AIquila\Service\Provider\ContextBias;
use OCP\Files\File;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\AudioToText;

/**
 * Transcription TaskProcessing Provider
 *
 * Registers AIquila as a core:audio2text provider, which is what the Assistant's
 * "Transcribe audio" action and Talk's voice-message transcription call.
 *
 * Input:  input (audio file); optionally language, timestamps, diarize, context_bias
 * Output: output (transcript); segments (JSON) when timestamps or diarize is on
 *
 * `segments` is a JSON array of {start, end, text, speaker?} — start and end
 * in seconds, speaker an opaque per-recording label, present only when the
 * backend could tell speakers apart. JSON in a Text slot rather than a
 * ListOfTexts, because a segment is a record and a list of texts would have
 * to encode its timings into each string anyway.
 */
class AudioToTextProvider implements ISynchronousProvider {

    private const YES = 'yes';
    private const NO = 'no';

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
            'timestamps' => new ShapeDescriptor(
                'Timestamps',
                'Also return the transcript as timed segments',
                EShapeType::Enum
            ),
            'diarize' => new ShapeDescriptor(
                'Speakers',
                'Label each segment with who spoke it. Implies timestamps.',
                EShapeType::Enum
            ),
            'context_bias' => new ShapeDescriptor(
                'Vocabulary',
                'Names and terms to spell as given, one per line or comma-separated',
                EShapeType::Text
            ),
        ];
    }

    public function getOptionalOutputShape(): array {
        return [
            'segments' => new ShapeDescriptor(
                'Segments',
                'Timed segments as JSON: [{start, end, text, speaker?}], seconds',
                EShapeType::Text
            ),
        ];
    }

    public function getInputShapeEnumValues(): array {
        return [];
    }

    public function getInputShapeDefaults(): array {
        return [];
    }

    public function getOptionalInputShapeEnumValues(): array {
        $toggle = [new ShapeEnumValue('No', self::NO), new ShapeEnumValue('Yes', self::YES)];
        return ['timestamps' => $toggle, 'diarize' => $toggle];
    }

    public function getOptionalInputShapeDefaults(): array {
        return ['timestamps' => self::NO, 'diarize' => self::NO];
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
            throw TaskFailure::userFacing('No audio file provided');
        }

        $provider = $this->providers->resolveAudioCapable($userId, $this->providers->requestedId($input));
        $reportProgress(0.1);

        $language = $input['language'] ?? '';
        $options = ['onProgress' => AudioService::progressBetween($reportProgress, 0.1, 1.0)];
        if (is_string($language) && $language !== '') {
            $options['language'] = $language;
        }
        $diarize = ($input['diarize'] ?? '') === self::YES;
        $timestamps = $diarize || ($input['timestamps'] ?? '') === self::YES;
        if ($timestamps) {
            $options['timestamps'] = true;
        }
        if ($diarize) {
            $options['diarize'] = true;
        }
        $bias = ContextBias::terms($input['context_bias'] ?? null);
        if ($bias !== []) {
            $options['contextBias'] = $bias;
        }
        $transcript = $this->audio->transcribe($provider, $file, $userId, $options);

        $reportProgress(1.0);
        $output = ['output' => $transcript['text']];
        if ($timestamps) {
            $output['segments'] = self::segmentsJson($transcript['segments']);
        }
        return $output;
    }

    /**
     * @param list<array{start: float, end: float, text: string, speaker?: string}> $segments
     */
    private static function segmentsJson(array $segments): string {
        $out = [];
        foreach ($segments as $segment) {
            // The leading space is a tokenizer artefact, not part of what was said.
            $segment['text'] = trim($segment['text']);
            $out[] = $segment;
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
