<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Progress of one transcription, as a fraction of the whole job.
 *
 * A transcription has two phases. The first is the upload. Its progress is
 * exact, because cURL reports the bytes sent against the known size. The
 * second is the answer. Its length is unknown until the provider finishes:
 * neither the streamed events nor anything Nextcloud stores about the file
 * says how long the recording is. That phase therefore moves on the
 * transcript received so far. It approaches its end but never claims it, so
 * the bar keeps moving on a long recording, and only the caller's final 1.0
 * completes it.
 *
 * Reports are throttled to whole percents. Every report is a database write
 * in TaskProcessing, and cURL calls the progress hook many times a second.
 */
final class TranscriptionProgress {
    /** Share of the job the upload stands for; the answer is the rest. */
    public const UPLOAD_SHARE = 0.5;

    /**
     * Transcript length at which the answer phase reads as half done:
     * roughly two minutes of speech.
     */
    private const HALF_ANSWER_CHARS = 2000;

    /** How close the answer phase may get to its end before the result arrives. */
    private const ANSWER_CEILING = 0.9;

    private float $last = 0.0;

    /** @param (\Closure(float): mixed)|null $report */
    private function __construct(
        private readonly ?\Closure $report,
    ) {
    }

    /**
     * The tracker for a transcribeAudio() call, from its `onProgress` option.
     *
     * @param array<string, mixed> $options
     */
    public static function from(array $options): self {
        $report = $options['onProgress'] ?? null;
        return new self(is_callable($report) ? \Closure::fromCallable($report) : null);
    }

    /**
     * Guzzle's `progress` request option, when anyone is listening.
     *
     * @return array<string, mixed>
     */
    public function requestOptions(): array {
        if ($this->report === null) {
            return [];
        }
        return [
            'progress' => function (int|float $downloadTotal, int|float $downloaded, int|float $uploadTotal, int|float $uploaded): void {
                $this->uploaded((int)$uploadTotal, (int)$uploaded);
            },
        ];
    }

    public function uploaded(int $total, int $sent): void {
        if ($total > 0) {
            $this->report(self::UPLOAD_SHARE * min(1.0, (float)$sent / (float)$total));
        }
    }

    /** @param int $characters the length of the transcript received so far */
    public function answered(int $characters): void {
        $characters = max(0, $characters);
        $share = self::ANSWER_CEILING * (float)$characters / (float)($characters + self::HALF_ANSWER_CHARS);
        $this->report(self::UPLOAD_SHARE + (1.0 - self::UPLOAD_SHARE) * $share);
    }

    private function report(float $fraction): void {
        if ($this->report === null || $fraction < $this->last + 0.01) {
            return;
        }
        $this->last = $fraction;
        ($this->report)($fraction);
    }
}
