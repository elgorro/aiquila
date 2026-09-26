<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Service\Provider\LLMProviderInterface;

/**
 * Request options for one coworker run: marks the request as background work
 * and carries the coworker's own model, effort and thinking.
 *
 * - The model is only pinned when the run actually goes to the coworker's
 *   pinned provider. A pin the owner may no longer use degrades to their
 *   current provider, and a model id from the old one would be meaningless.
 * - `effort` and `thinking` live in the coworker's options JSON. Values the
 *   model does not accept fall through to the task defaults at request time.
 */
final class CoworkerRequestOptions {
    public const THINKING_VALUES = ['on', 'off', 'auto'];

    /**
     * @param array<string, mixed> $options the coworker's decoded options
     * @return array<string, mixed>
     */
    public static function build(Coworker $coworker, LLMProviderInterface $provider, array $options): array {
        $request = LLMProviderInterface::TASK_OPTIONS;

        $model = $coworker->getModel();
        if ($model !== null && $model !== '' && $coworker->getProvider() === $provider->getId()) {
            $request['model'] = $model;
        }

        $effort = $options['effort'] ?? null;
        if (is_string($effort) && $effort !== '') {
            $request['effort'] = $effort;
        }

        $thinking = $options['thinking'] ?? null;
        if (is_string($thinking) && in_array($thinking, self::THINKING_VALUES, true)) {
            $request['thinking'] = $thinking;
        }

        return $request;
    }
}
