<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Service;

/**
 * Turns a requested thinking mode, budget and effort into the `thinking` and
 * `effort` values a Messages API request can actually carry for one model.
 *
 * Pure: no config, no I/O. ClaudeSDKService resolves the inputs (conversation
 * → user → admin → model default) and passes them in; everything
 * model-specific comes from ClaudeModels.
 *
 * The three modes:
 *   auto — send nothing and let the model decide. On the 5-series that means
 *          adaptive thinking; on Opus 4.x / Sonnet 4.6 it means no thinking.
 *   on   — adaptive thinking.
 *   off  — no thinking, expressed the way the model accepts it: omit the
 *          parameter, send {type: disabled} (capping effort where the API
 *          requires it), or — on models that always think — not at all.
 */
final class ThinkingPolicy {

    public const MODE_AUTO = 'auto';
    public const MODE_ON   = 'on';
    public const MODE_OFF  = 'off';

    public const MODES = [self::MODE_AUTO, self::MODE_ON, self::MODE_OFF];

    /** Effective state reported back to callers and the UI. */
    public const STATE_ADAPTIVE = 'adaptive';
    public const STATE_BUDGET   = 'budget';
    public const STATE_OFF      = 'off';

    /** "off" was asked for on a model that cannot stop thinking. */
    public const ADJUST_ALWAYS_ON = 'thinking_always_on';
    /** Effort was lowered because the model rejects it with thinking disabled. */
    public const ADJUST_EFFORT_CAPPED = 'effort_capped';

    /**
     * Normalise a stored or requested mode. Accepts the tri-state values plus
     * the boolean spellings older config used ('true'/'1' → on). Anything
     * else — including the old 'false', which never sent anything — is auto.
     */
    public static function normalizeMode(mixed $value): string {
        if ($value === true || $value === 'true' || $value === '1' || $value === self::MODE_ON) {
            return self::MODE_ON;
        }
        if ($value === self::MODE_OFF) {
            return self::MODE_OFF;
        }
        return self::MODE_AUTO;
    }

    /**
     * @param string      $mode      one of MODES
     * @param int|null    $budget    explicit thinking budget (enabled mode); ignored when $mode is off
     * @param string|null $effort    effort already resolved for this model, or null when the model has no effort
     * @param bool        $summarize ask for readable thinking summaries where the model defaults to omitting them
     *
     * @return array{
     *   thinking: array<string, mixed>|null,
     *   effort: string|null,
     *   state: string,
     *   always_on: bool,
     *   adjustments: list<string>,
     * }
     */
    public static function resolve(
        string  $model,
        bool    $supportsThinking,
        string  $mode,
        ?int    $budget,
        ?string $effort,
        bool    $summarize = false,
    ): array {
        $alwaysOn = $supportsThinking && !ClaudeModels::canDisableThinking($model);
        $result = [
            'thinking' => null,
            'effort' => $effort,
            'state' => self::STATE_OFF,
            'always_on' => $alwaysOn,
            'adjustments' => [],
        ];

        if (!$supportsThinking) {
            return $result;
        }

        if ($budget !== null && $mode !== self::MODE_OFF) {
            $result['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget];
            $result['state'] = self::STATE_BUDGET;
        } elseif ($mode === self::MODE_ON) {
            $result['thinking'] = ['type' => 'adaptive'];
            $result['state'] = self::STATE_ADAPTIVE;
        } elseif ($mode === self::MODE_OFF) {
            $result = self::resolveOff($model, $result);
        } else {
            $result['state'] = ClaudeModels::thinkingDefault($model) === ClaudeModels::THINKING_DEFAULT_ADAPTIVE
                ? self::STATE_ADAPTIVE
                : self::STATE_OFF;
        }

        if ($summarize && $result['state'] !== self::STATE_OFF && ClaudeModels::thinkingSummaryNeedsOptIn($model)) {
            // An omitted param on an adaptive-by-default model is equivalent to
            // {type: adaptive}; spell it out so `display` has somewhere to go.
            $result['thinking'] ??= ['type' => 'adaptive'];
            $result['thinking']['display'] = 'summarized';
        }

        return $result;
    }

    /**
     * @param array{thinking: array<string, mixed>|null, effort: string|null, state: string, always_on: bool, adjustments: list<string>} $result
     * @return array{thinking: array<string, mixed>|null, effort: string|null, state: string, always_on: bool, adjustments: list<string>}
     */
    private static function resolveOff(string $model, array $result): array {
        switch (ClaudeModels::thinkingOffMode($model)) {
            case ClaudeModels::THINKING_OFF_NEVER:
                // {type: disabled} is a 400 at every effort. Leave the param out
                // and say so; lowering effort is the caller's lever.
                $result['state'] = self::STATE_ADAPTIVE;
                $result['adjustments'][] = self::ADJUST_ALWAYS_ON;
                return $result;

            case ClaudeModels::THINKING_OFF_DISABLED:
                $result['thinking'] = ['type' => 'disabled'];
                $cap = ClaudeModels::maxEffortWithThinkingDisabled($model);
                if ($cap !== null && $result['effort'] !== null && self::effortRank($result['effort']) > self::effortRank($cap)) {
                    $result['effort'] = $cap;
                    $result['adjustments'][] = self::ADJUST_EFFORT_CAPPED;
                }
                return $result;

            default:
                // Omitting the parameter is "off" on these models.
                return $result;
        }
    }

    private static function effortRank(string $effort): int {
        $rank = array_search($effort, ClaudeModels::ALL_EFFORTS, true);
        return $rank === false ? -1 : $rank;
    }
}
