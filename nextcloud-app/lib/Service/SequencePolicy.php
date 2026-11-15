<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * The admin's rules for sequences, and which built-ins are switched off.
 *
 * Built-ins can be switched off for the whole instance by an admin, and for
 * themselves by any user; the MCP server gets the union.
 *
 * @psalm-import-type AIquilaSequencePolicy from \OCA\AIquila\ResponseDefinitions
 */
class SequencePolicy {
    private const APP = 'aiquila';
    private const KEY_ALLOW_PERSONAL = 'sequences_allow_personal';
    private const KEY_MAX_PER_USER = 'sequences_max_per_user';
    private const KEY_MAX_STEPS = 'sequences_max_steps';
    private const KEY_MAX_PINNED = 'sequences_max_pinned';
    private const KEY_DISABLED_BUILTINS = 'sequences_disabled_builtins';

    public const DEFAULT_MAX_PER_USER = 50;
    public const DEFAULT_MAX_PINNED = 10;
    /** Upper bound for the per-user counts an admin may set. */
    public const MAX_COUNT = 1000;

    public function __construct(
        private AppSettings $settings,
        private SequenceCatalog $catalog,
    ) {
    }

    /**
     * @return AIquilaSequencePolicy
     */
    public function get(): array {
        $stepCap = $this->catalog->limit('steps', 20);
        return [
            'allowPersonal' => $this->settings->getAppValue(self::APP, self::KEY_ALLOW_PERSONAL, '1') === '1',
            'maxPerUser' => $this->intValue(self::KEY_MAX_PER_USER, self::DEFAULT_MAX_PER_USER, 0, self::MAX_COUNT),
            'maxSteps' => $this->intValue(self::KEY_MAX_STEPS, $stepCap, 1, $stepCap),
            'maxPinned' => $this->intValue(self::KEY_MAX_PINNED, self::DEFAULT_MAX_PINNED, 0, self::MAX_COUNT),
        ];
    }

    /**
     * Sets the fields given; null keeps a field.
     *
     * @throws \InvalidArgumentException when a number is out of range
     */
    public function set(?bool $allowPersonal, ?int $maxPerUser, ?int $maxSteps, ?int $maxPinned): void {
        $this->validate($maxPerUser, $maxSteps, $maxPinned);
        if ($allowPersonal !== null) {
            $this->settings->setAppValue(self::APP, self::KEY_ALLOW_PERSONAL, $allowPersonal ? '1' : '0');
        }
        if ($maxPerUser !== null) {
            $this->settings->setAppValue(self::APP, self::KEY_MAX_PER_USER, (string)$maxPerUser);
        }
        if ($maxSteps !== null) {
            $this->settings->setAppValue(self::APP, self::KEY_MAX_STEPS, (string)$maxSteps);
        }
        if ($maxPinned !== null) {
            $this->settings->setAppValue(self::APP, self::KEY_MAX_PINNED, (string)$maxPinned);
        }
    }

    /**
     * @throws \InvalidArgumentException when a number is out of range
     */
    public function validate(?int $maxPerUser, ?int $maxSteps, ?int $maxPinned): void {
        $stepCap = $this->catalog->limit('steps', 20);
        foreach ([
            'maxPerUser' => [$maxPerUser, 0, self::MAX_COUNT],
            'maxSteps' => [$maxSteps, 1, $stepCap],
            'maxPinned' => [$maxPinned, 0, self::MAX_COUNT],
        ] as $field => [$value, $min, $max]) {
            if ($value !== null && ($value < $min || $value > $max)) {
                throw new \InvalidArgumentException(sprintf('%s must be between %d and %d', $field, $min, $max));
            }
        }
    }

    /**
     * Built-ins an admin switched off for everyone.
     *
     * @return list<string>
     */
    public function disabledBuiltins(): array {
        return $this->slugList($this->settings->getAppValue(self::APP, self::KEY_DISABLED_BUILTINS, '[]'));
    }

    /**
     * Built-ins a user switched off for themselves.
     *
     * @return list<string>
     */
    public function userDisabledBuiltins(string $userId): array {
        return $this->slugList($this->settings->getUserValue($userId, self::APP, self::KEY_DISABLED_BUILTINS, '[]'));
    }

    /**
     * @throws \InvalidArgumentException for a slug that is not a built-in
     */
    public function setBuiltinEnabled(string $slug, bool $enabled): void {
        $this->settings->setAppValue(self::APP, self::KEY_DISABLED_BUILTINS, $this->toggle($this->disabledBuiltins(), $slug, $enabled));
    }

    /**
     * Replaces the built-ins switched off for everyone; unknown slugs are ignored.
     *
     * @param list<mixed> $slugs
     */
    public function setDisabledBuiltins(array $slugs): void {
        $known = $this->catalog->builtinSlugs();
        $set = array_values(array_unique(array_filter($slugs, static fn ($s): bool => is_string($s) && in_array($s, $known, true))));
        sort($set);
        $this->settings->setAppValue(self::APP, self::KEY_DISABLED_BUILTINS, json_encode($set, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws \InvalidArgumentException for a slug that is not a built-in
     */
    public function setUserBuiltinEnabled(string $userId, string $slug, bool $enabled): void {
        $this->settings->setUserValue($userId, self::APP, self::KEY_DISABLED_BUILTINS, $this->toggle($this->userDisabledBuiltins($userId), $slug, $enabled));
    }

    /**
     * @param list<string> $current
     */
    private function toggle(array $current, string $slug, bool $enabled): string {
        if (!in_array($slug, $this->catalog->builtinSlugs(), true)) {
            throw new \InvalidArgumentException('Unknown built-in sequence');
        }
        $set = array_values(array_diff($current, [$slug]));
        if (!$enabled) {
            $set[] = $slug;
        }
        sort($set);
        return json_encode($set, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function slugList(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $known = $this->catalog->builtinSlugs();
        return array_values(array_filter($decoded, static fn ($s): bool => is_string($s) && in_array($s, $known, true)));
    }

    private function intValue(string $key, int $default, int $min, int $max): int {
        $raw = $this->settings->getAppValue(self::APP, $key, '');
        if ($raw === '' || !ctype_digit($raw)) {
            return min($max, max($min, $default));
        }
        return min($max, max($min, (int)$raw));
    }
}
