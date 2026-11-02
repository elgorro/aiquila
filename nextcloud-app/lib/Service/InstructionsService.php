<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\Project;
use OCA\AIquila\Db\ProjectPath;
use OCP\IUserManager;

/**
 * Assembles the system prompt from its layers, most stable first:
 *
 *   1. Instance instructions (admin), where the admin enabled them for the
 *      surface asking — chat, the Talk bot, coworkers or TaskProcessing chat
 *   2. The user's default instructions (chat only)
 *   3. The project's system prompt (chat only)
 *   4. The project's path context (chat only)
 *
 * Stable text first keeps the cached prompt prefix reusable: layer 1 is the
 * same for every user. Empty layers are skipped; a user can leave layer 2
 * empty but cannot remove layer 1.
 *
 * `{user}` (display name) and `{locale}` are replaced in every layer. Date and
 * time zone are not variables: RuntimeContextService already sends them.
 */
class InstructionsService {
    /** Cap on any stored prompt text: instructions, library prompts, templates. */
    public const MAX_LENGTH = 20000;
    public const MAX_TITLE_LENGTH = 255;
    public const MAX_DESCRIPTION_LENGTH = 1000;

    public const SCOPE_CHAT = 'chat';
    public const SCOPE_TALK = 'talk';
    public const SCOPE_COWORKERS = 'coworkers';
    public const SCOPE_TASKS = 'tasks';

    /**
     * Where instance instructions apply unless the admin changes it. Coworkers
     * and Assistant tasks expect a fixed output shape, so they start off.
     */
    public const SCOPE_DEFAULTS = [
        self::SCOPE_CHAT => true,
        self::SCOPE_TALK => true,
        self::SCOPE_COWORKERS => false,
        self::SCOPE_TASKS => false,
    ];

    private const APP = 'aiquila';
    private const KEY_INSTANCE = 'instance_instructions';
    private const KEY_USER = 'default_system_prompt';

    public function __construct(
        private AppSettings $config,
        private IUserManager $userManager,
    ) {
    }

    public function getInstanceInstructions(): string {
        return $this->config->getAppValue(self::APP, self::KEY_INSTANCE, '');
    }

    public function setInstanceInstructions(string $text): void {
        $text = trim($text);
        if ($text === '') {
            $this->config->deleteAppValue(self::APP, self::KEY_INSTANCE);
        } else {
            $this->config->setAppValue(self::APP, self::KEY_INSTANCE, $text);
        }
    }

    /**
     * @return array{chat: bool, talk: bool, coworkers: bool, tasks: bool}
     */
    public function getScopes(): array {
        $scopes = self::SCOPE_DEFAULTS;
        foreach ($scopes as $scope => $default) {
            $value = $this->config->getAppValue(self::APP, self::scopeKey($scope), $default ? '1' : '0');
            $scopes[$scope] = $value === '1';
        }
        return $scopes;
    }

    /**
     * @param array<string, bool> $scopes unknown keys are ignored
     */
    public function setScopes(array $scopes): void {
        foreach (array_keys(self::SCOPE_DEFAULTS) as $scope) {
            if (array_key_exists($scope, $scopes)) {
                $this->config->setAppValue(self::APP, self::scopeKey($scope), $scopes[$scope] ? '1' : '0');
            }
        }
    }

    public function getUserDefault(string $userId): string {
        return $this->config->getUserValue($userId, self::APP, self::KEY_USER, '');
    }

    /**
     * The system prompt for an AIquila chat turn.
     *
     * @param list<ProjectPath> $paths the project's paths
     */
    public function forChat(string $userId, ?Project $project = null, array $paths = []): ?string {
        $pathContext = null;
        if ($project !== null && $paths !== []) {
            $lines = ['Project: ' . $project->getTitle()];
            foreach ($paths as $path) {
                $lines[] = '- [' . $path->getPathType() . '] ' . $path->getPath();
            }
            $pathContext = implode("\n", $lines);
        }

        return $this->compose($userId, [
            $this->instanceLayer(self::SCOPE_CHAT),
            $this->getUserDefault($userId),
            $project?->getSystemPrompt(),
            $pathContext,
        ]);
    }

    /**
     * The instance layer for $scope placed before $base, the surface's own
     * prompt. $base comes from the caller (Talk, the Assistant, a coworker),
     * so only the instance layer has its variables resolved. Returns $base
     * unchanged when the admin has not enabled instructions for $scope.
     */
    public function withInstance(string $scope, ?string $userId, ?string $base): ?string {
        $instance = $this->instanceLayer($scope);
        if ($instance === null) {
            return $base;
        }
        $instance = $this->resolveVariables(trim($instance), $userId);
        return $base !== null && trim($base) !== '' ? $instance . "\n\n" . $base : $instance;
    }

    /**
     * Instance instructions if they apply to $scope, unresolved.
     */
    public function instanceLayer(string $scope): ?string {
        if (!($this->getScopes()[$scope] ?? false)) {
            return null;
        }
        $text = $this->getInstanceInstructions();
        return $text !== '' ? $text : null;
    }

    /**
     * Join the non-empty layers and resolve the variables.
     *
     * @param list<?string> $layers
     */
    public function compose(?string $userId, array $layers): ?string {
        $parts = [];
        foreach ($layers as $layer) {
            if ($layer !== null && trim($layer) !== '') {
                $parts[] = trim($layer);
            }
        }
        if ($parts === []) {
            return null;
        }
        return $this->resolveVariables(implode("\n\n", $parts), $userId);
    }

    /**
     * Replace `{user}` and `{locale}`. Without a user (a Talk guest) both
     * resolve to an empty string.
     */
    public function resolveVariables(string $text, ?string $userId): string {
        if (!str_contains($text, '{')) {
            return $text;
        }
        $user = '';
        $locale = '';
        if ($userId !== null && $userId !== '') {
            $user = $this->userManager->getDisplayName($userId) ?? $userId;
            $locale = $this->config->getUserValue($userId, 'core', 'locale', '');
            if ($locale === '') {
                $locale = $this->config->getUserValue($userId, 'core', 'lang', '');
            }
        }
        return strtr($text, ['{user}' => $user, '{locale}' => $locale]);
    }

    /**
     * Length check shared by every endpoint that stores prompt text.
     *
     * @return string|null an error message, or null when $text fits
     */
    public static function tooLong(?string $text, int $max = self::MAX_LENGTH, string $field = 'Text'): ?string {
        if ($text !== null && mb_strlen($text) > $max) {
            return sprintf('%s is longer than %d characters', $field, $max);
        }
        return null;
    }

    private static function scopeKey(string $scope): string {
        return 'instance_instructions_' . $scope;
    }
}
