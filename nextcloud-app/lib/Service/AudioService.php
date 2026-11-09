<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use GuzzleHttp\Psr7\Utils;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\TaskProcessing\TaskFailure;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Transcription and speech for the audio TaskProcessing providers.
 *
 * Every audio task type is some chain of the same two calls — transcribe a
 * recording, speak a text — and each needs the same guard in front
 * (AudioLimits) and the same handling behind: log the provider's error and
 * turn it into an exception the Assistant shows, refuse an empty result
 * rather than handing back nothing. That lives here once.
 *
 * Resolution does not: callers pass a provider ProviderResolver has already
 * checked for the capability, so a refusal still names the alternatives.
 */
class AudioService {

    public const SUBTITLES_SRT = 'srt';
    public const SUBTITLES_VTT = 'vtt';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Transcribe a recording without loading it into memory: the provider is
     * handed a stream of the file, which cURL reads from while uploading.
     *
     * @param array{language?: string, timestamps?: bool, onProgress?: callable(float): mixed} $options
     * @return array{text: string, segments: list<array{start: float, end: float, text: string}>}
     * @throws \RuntimeException when the recording is refused or the provider fails
     */
    public function transcribe(LLMProviderInterface $provider, File $file, ?string $userId, array $options = []): array {
        $size = (int)$file->getSize();
        $mimeType = $file->getMimetype();
        try {
            AudioLimits::assertAcceptable($size, $mimeType);
        } catch (\RuntimeException $e) {
            throw TaskFailure::userFacing($e->getMessage(), $e);
        }

        try {
            $handle = $file->fopen('r');
        } catch (\Throwable $e) {
            $this->logger->error('AIquila audio: could not open the recording', ['error' => $e->getMessage(), 'fileId' => $file->getId()]);
            throw TaskFailure::userFacing('The audio file could not be read.', $e);
        }
        if (!is_resource($handle)) {
            throw TaskFailure::userFacing('The audio file could not be read.');
        }

        try {
            $result = $provider->transcribeAudio(
                self::uploadBody($handle, $size),
                $mimeType,
                AudioLimits::filenameFor($file->getName(), $mimeType),
                $userId,
                $options,
            );
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        if (isset($result['error'])) {
            $this->logger->error('AIquila audio: transcription failed', ['error' => $result['error'], 'provider' => $provider->getId()]);
            throw TaskFailure::fromProviderError($result['error']);
        }

        return [
            'text' => $result['response'] ?? '',
            'segments' => $result['segments'] ?? [],
        ];
    }

    /**
     * An `onProgress` callback that maps the transcription's 0–1 onto the
     * $from–$to stretch of a task's own progress.
     *
     * @param callable(float): mixed $reportProgress TaskProcessing's reporter
     * @return \Closure(float): void
     */
    public static function progressBetween(callable $reportProgress, float $from, float $to): \Closure {
        return static function (float $fraction) use ($reportProgress, $from, $to): void {
            $reportProgress($from + ($to - $from) * max(0.0, min(1.0, $fraction)));
        };
    }

    /**
     * The file stream as an upload part, carrying the size Nextcloud has on
     * record.
     *
     * Guzzle would otherwise take the length from fstat(), and Nextcloud's
     * storage wrappers do not all report the plain size there: server-side
     * encryption stats the encrypted file, which is larger, so the request
     * would announce more bytes than it sends. Guzzle ships with Nextcloud
     * rather than with this app, so without it the raw stream is passed and
     * fstat() decides.
     *
     * @param resource $handle
     * @return resource|\Psr\Http\Message\StreamInterface
     */
    private static function uploadBody($handle, int $size): mixed {
        if (class_exists(Utils::class)) {
            return Utils::streamFor($handle, ['size' => $size]);
        }
        return $handle;
    }

    /**
     * A transcript that has to say something, for chains that go on to act on
     * it — answering or translating silence would only produce a confused
     * reply to nothing.
     *
     * @param array{language?: string, onProgress?: callable(float): mixed} $options
     * @throws \RuntimeException
     */
    public function transcribeRequired(LLMProviderInterface $provider, File $file, ?string $userId, array $options = []): string {
        $text = $this->transcribe($provider, $file, $userId, $options)['text'];
        if (trim($text) === '') {
            throw TaskFailure::userFacing('Nothing could be transcribed from this recording.');
        }
        return $text;
    }

    /**
     * The recording's timed segments, for subtitles.
     *
     * @param array{onProgress?: callable(float): mixed} $options
     * @return list<array{start: float, end: float, text: string}>
     * @throws \RuntimeException when the provider fails or returns no timings
     */
    public function segments(LLMProviderInterface $provider, File $file, ?string $userId, array $options = []): array {
        $segments = $this->transcribe($provider, $file, $userId, ['timestamps' => true] + $options)['segments'];
        if ($segments === []) {
            throw TaskFailure::userFacing($provider->getLabel() . ' returned no timestamps for this recording, so no subtitles can be made from it.');
        }
        return $segments;
    }

    /**
     * Raw audio bytes — TaskProcessing stores Audio outputs as bytes.
     *
     * @param array{voice?: string} $options
     * @throws \RuntimeException
     */
    public function speak(LLMProviderInterface $provider, string $text, ?string $userId, array $options = []): string {
        $result = $provider->synthesizeSpeech($text, $userId, $options);
        if (isset($result['error'])) {
            $this->logger->error('AIquila audio: speech synthesis failed', ['error' => $result['error'], 'provider' => $provider->getId()]);
            throw TaskFailure::fromProviderError($result['error']);
        }
        $audio = $result['audio'] ?? '';
        if ($audio === '') {
            throw TaskFailure::userFacing($provider->getLabel() . ' returned no audio.');
        }
        return $audio;
    }

    /**
     * Segments as a SubRip or WebVTT file — the two formats Nextcloud's own
     * subtitles provider offers, SubRip being its default.
     *
     * @param list<array{start: float, end: float, text: string}> $segments
     */
    public static function subtitles(array $segments, string $format = self::SUBTITLES_SRT): string {
        $vtt = $format === self::SUBTITLES_VTT;
        $cues = [];
        foreach ($segments as $segment) {
            $text = trim($segment['text']);
            if ($text === '') {
                continue;
            }
            $timing = self::timestamp($segment['start'], $vtt) . ' --> ' . self::timestamp($segment['end'], $vtt);
            $cues[] = ($vtt ? '' : (count($cues) + 1) . "\n") . $timing . "\n" . $text . "\n";
        }
        return ($vtt ? "WEBVTT\n\n" : '') . implode("\n", $cues);
    }

    /** HH:MM:SS,mmm for SubRip, HH:MM:SS.mmm for WebVTT; hours are not capped at 24. */
    private static function timestamp(float $seconds, bool $vtt): string {
        $ms = (int)round(max(0.0, $seconds) * 1000.0);
        return sprintf(
            '%02d:%02d:%02d%s%03d',
            intdiv($ms, 3_600_000),
            intdiv($ms, 60_000) % 60,
            intdiv($ms, 1000) % 60,
            $vtt ? '.' : ',',
            $ms % 1000,
        );
    }
}
