<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\AudioService;
use OCP\Files\File;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;

/**
 * Subtitles TaskProcessing Provider (core:audio2text:subtitles)
 *
 * Transcribes a recording or video with segment timestamps and writes them as
 * a SubRip or WebVTT file. The optional `format` input mirrors the one
 * Nextcloud's own Whisper provider offers, with SubRip as the default.
 *
 * The task type is Nextcloud 35+. The id is spelled out rather than read from
 * OCP\TaskProcessing\TaskTypes\AudioToTextSubtitles::ID because that class
 * does not exist on 33 or 34, which the app still declares support for.
 *
 * Input:  input (file), optional format (srt|vtt)
 * Output: output (the subtitles file, as bytes)
 */
class SubtitlesProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
        private AudioService $audio,
        private IL10N $l,
    ) {
    }

    public function getId(): string {
        return 'aiquila:audio2text:subtitles';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return 'core:audio2text:subtitles';
    }

    public function getExpectedRuntime(): int {
        return 180;
    }

    public function getOptionalInputShape(): array {
        return [
            'format' => new ShapeDescriptor(
                $this->l->t('Format'),
                $this->l->t('The format of the subtitles file'),
                EShapeType::Enum
            ),
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
        return [];
    }

    public function getInputShapeDefaults(): array {
        return [];
    }

    public function getOptionalInputShapeEnumValues(): array {
        return [
            'format' => [
                new ShapeEnumValue($this->l->t('SubRip (SRT)'), AudioService::SUBTITLES_SRT),
                new ShapeEnumValue($this->l->t('WebVTT'), AudioService::SUBTITLES_VTT),
            ],
        ];
    }

    public function getOptionalInputShapeDefaults(): array {
        return ['format' => AudioService::SUBTITLES_SRT];
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
            throw new \RuntimeException('No audio or video file provided');
        }

        $provider = $this->providers->resolveAudioCapable($userId, $this->providers->requestedId($input));
        $reportProgress(0.1);

        $segments = $this->audio->segments($provider, $file, $userId);
        $format = ($input['format'] ?? '') === AudioService::SUBTITLES_VTT ? AudioService::SUBTITLES_VTT : AudioService::SUBTITLES_SRT;

        $reportProgress(1.0);
        return ['output' => AudioService::subtitles($segments, $format)];
    }
}
