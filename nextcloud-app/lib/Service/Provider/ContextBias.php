<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Names and domain terms a transcription should spell the way they are given.
 *
 * Callers hand them over as a list or as one text, one term per line or
 * separated by commas, which is what someone types into the Assistant.
 * Blank entries and repeats are dropped, order is kept.
 */
final class ContextBias {
    /**
     * @return list<string>
     */
    public static function terms(mixed $input): array {
        if (is_string($input)) {
            $input = preg_split('/[\r\n,]+/', $input) ?: [];
        }
        if (!is_array($input)) {
            return [];
        }
        $terms = [];
        foreach ($input as $term) {
            if (!is_string($term)) {
                continue;
            }
            $term = trim($term);
            if ($term !== '' && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }
        return $terms;
    }
}
