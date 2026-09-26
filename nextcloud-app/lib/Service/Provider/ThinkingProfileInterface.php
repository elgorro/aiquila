<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Optional companion to LLMProviderInterface for providers whose models
 * differ in how thinking and effort behave, so the settings card and the chat
 * can say what a setting will actually do on the selected model.
 */
interface ThinkingProfileInterface {
    /** Thinking runs even when turned off: the model rejects every way of disabling it. */
    public const THINKING_ALWAYS_ON = 'always_on';
    /** Thinks adaptively unless turned off. */
    public const THINKING_ADAPTIVE_BY_DEFAULT = 'adaptive_by_default';
    /** Does not think unless turned on. */
    public const THINKING_OFF_BY_DEFAULT = 'off_by_default';
    /** No thinking support. */
    public const THINKING_NONE = 'none';

    /**
     * Static facts about one model.
     *
     * @return array{
     *   model: string,
     *   thinking: string,
     *   can_disable: bool,
     *   off_max_effort: string|null,
     *   efforts: list<string>,
     *   default_effort: string|null,
     * }
     */
    public function getThinkingProfile(string $model): array;

    /**
     * What a request with these options would actually send: the effective
     * thinking state and effort after every default and model rule applied.
     *
     * @return array{model: string, mode: string, state: string, always_on: bool, effort: string|null, budget: int|null, adjustments: list<string>}
     */
    public function describeThinking(?string $userId, array $options = []): array;
}
