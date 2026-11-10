<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Who may start coworkers by something other than their schedule.
 *
 * Off until an administrator turns it on. Once on, an empty pair of lists means
 * everyone; otherwise the account must be named in `users` or belong to a group
 * in `groups`. It applies to the account a coworker runs as, and is checked
 * both when a trigger is set up and every time one fires, so taking someone off
 * the list stops their triggers without touching their coworkers.
 */
class CoworkerTriggerPolicy {
    private const APP = 'aiquila';
    public const KEY_ENABLED = 'coworker_triggers_enabled';
    public const KEY_USERS = 'coworker_triggers_users';
    public const KEY_GROUPS = 'coworker_triggers_groups';

    /** @var array<string, bool> in-request memo of uid => allowed */
    private array $memo = [];

    public function __construct(
        private readonly AppSettings $settings,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
    ) {
    }

    public function isEnabled(): bool {
        return $this->settings->getAppValue(self::APP, self::KEY_ENABLED, 'no') === 'yes';
    }

    public function isAllowed(string $userId): bool {
        if (!$this->isEnabled() || $userId === '') {
            return false;
        }
        if (isset($this->memo[$userId])) {
            return $this->memo[$userId];
        }
        $users = $this->list(self::KEY_USERS);
        $groups = $this->list(self::KEY_GROUPS);
        $allowed = true;
        if ($users !== [] || $groups !== []) {
            $allowed = in_array($userId, $users, true);
            if (!$allowed && $groups !== []) {
                $user = $this->userManager->get($userId);
                $memberOf = $user === null ? [] : $this->groupManager->getUserGroupIds($user);
                $allowed = array_intersect($memberOf, $groups) !== [];
            }
        }
        return $this->memo[$userId] = $allowed;
    }

    /** @return array{enabled: bool, users: list<string>, groups: list<string>} */
    public function get(): array {
        return [
            'enabled' => $this->isEnabled(),
            'users' => $this->list(self::KEY_USERS),
            'groups' => $this->list(self::KEY_GROUPS),
        ];
    }

    /**
     * Save whichever of the three settings are given; the others are kept.
     *
     * @param list<string>|null $users
     * @param list<string>|null $groups
     */
    public function set(?bool $enabled, ?array $users, ?array $groups): void {
        if ($enabled !== null) {
            $this->settings->setAppValue(self::APP, self::KEY_ENABLED, $enabled ? 'yes' : 'no');
        }
        foreach ([self::KEY_USERS => $users, self::KEY_GROUPS => $groups] as $key => $ids) {
            if ($ids === null) {
                continue;
            }
            $clean = array_values(array_unique(array_filter(
                array_map(static fn ($id): string => trim((string)$id), $ids),
                static fn (string $id): bool => $id !== '',
            )));
            $this->settings->setAppValue(self::APP, $key, json_encode($clean) ?: '[]');
        }
        $this->memo = [];
    }

    /** @return list<string> */
    private function list(string $key): array {
        $decoded = json_decode($this->settings->getAppValue(self::APP, $key, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, static fn ($id): bool => is_string($id) && $id !== ''));
    }
}
