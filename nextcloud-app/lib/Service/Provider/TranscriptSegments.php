<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * The timed segments of a transcription response.
 *
 * Mistral and the OpenAI-shaped local servers (Speaches, whisper.cpp) answer
 * with the same `segments[]` of `{start, end, text}` in seconds, so both read
 * them here. Mistral declares start and end nullable; a segment without both
 * cannot be placed on a timeline and is dropped.
 *
 * A diarized answer labels each segment with its speaker. Mistral calls the
 * field `speaker_id` (`"speaker_1"`), LocalAI `speaker` (`"0"`, `"1"`, … in
 * order of first appearance). Either is kept as an opaque string under
 * `speaker`; a segment without one has no `speaker` key.
 */
final class TranscriptSegments {
    /**
     * @return list<array{start: float, end: float, text: string, speaker?: string}>
     */
    public static function parse(mixed $segments): array {
        if (!is_array($segments)) {
            return [];
        }
        $parsed = [];
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $start = $segment['start'] ?? null;
            $end = $segment['end'] ?? null;
            $text = $segment['text'] ?? null;
            if (!is_numeric($start) || !is_numeric($end) || !is_string($text)) {
                continue;
            }
            $entry = ['start' => (float)$start, 'end' => (float)$end, 'text' => $text];
            $speaker = $segment['speaker_id'] ?? $segment['speaker'] ?? null;
            if ((is_string($speaker) || is_int($speaker)) && (string)$speaker !== '') {
                $entry['speaker'] = (string)$speaker;
            }
            $parsed[] = $entry;
        }
        return $parsed;
    }
}
