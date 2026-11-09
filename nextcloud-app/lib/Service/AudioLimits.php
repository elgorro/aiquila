<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * What an audio recording has to satisfy before it is worth uploading.
 *
 * Transcription endpoints reject oversized or unrecognised containers with a
 * bare 4xx that says nothing useful to the person who picked the file, and the
 * whole recording has been pushed over the wire by the time that arrives. Checking here turns both into an immediate, specific
 * message — the audio analogue of ImageOptimizer's MAX_IMAGES guard.
 *
 * There is no optimizing counterpart: transcoding audio would need ffmpeg,
 * which Nextcloud does not require, so unsupported containers are refused
 * rather than converted.
 */
final class AudioLimits {
    /**
     * Ceiling on a single recording.
     *
     * Memory is not what sets it: the recording is streamed from storage to
     * the provider, never loaded whole. What sets it is the far end. Mistral
     * names three hours as the longest recording it transcribes, but no size
     * for an inline upload, so this is sized from that duration. Three hours
     * is about 430 MB as 320 kbit/s MP3 and about 350 MB as 16 kHz mono WAV,
     * the usual rate for speech. A file above this is either longer than any
     * provider in use will take or uncompressed studio audio. Refusing it
     * here saves an upload of several minutes that ends in a rejection.
     */
    public const MAX_BYTES = 512 * 1024 * 1024;

    /** Containers the transcription endpoints in use accept. */
    private const SUPPORTED_MIMES = [
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
        'audio/m4a',
        'audio/x-m4a',
        'audio/wav',
        'audio/x-wav',
        'audio/webm',
        'audio/ogg',
        'audio/flac',
        'audio/x-flac',
        'video/mp4',
        'video/webm',
    ];

    /**
     * File extensions by MIME type, for naming the upload part.
     *
     * Transcription endpoints routinely sniff the container from the filename
     * rather than the declared type, so a recording arriving from Nextcloud
     * with a name the far end cannot read gets one it can.
     */
    private const EXTENSIONS = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/m4a' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/flac' => 'flac',
        'audio/x-flac' => 'flac',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    public static function isSupportedMime(string $mimeType): bool {
        return in_array(strtolower($mimeType), self::SUPPORTED_MIMES, true);
    }

    /**
     * A filename the far end can read the container format from: the original
     * when it already carries a usable extension, otherwise one derived from
     * the MIME type.
     */
    public static function filenameFor(string $name, string $mimeType): string {
        $extension = self::EXTENSIONS[strtolower($mimeType)] ?? 'mp3';
        $current = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($current !== '' && in_array($current, self::EXTENSIONS, true)) {
            return $name;
        }
        $base = pathinfo($name, PATHINFO_FILENAME);
        return ($base !== '' ? $base : 'audio') . '.' . $extension;
    }

    /**
     * @throws \RuntimeException when the recording cannot be sent as it is
     */
    public static function assertAcceptable(int $size, string $mimeType): void {
        if ($size <= 0) {
            throw new \RuntimeException('The audio file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('The audio file is too large. Maximum is ' . intdiv(self::MAX_BYTES, 1024 * 1024) . ' MB.');
        }
        if (!self::isSupportedMime($mimeType)) {
            throw new \RuntimeException('Unsupported audio format: ' . $mimeType . '. Use MP3, M4A, WAV, WebM, OGG or FLAC.');
        }
    }
}
