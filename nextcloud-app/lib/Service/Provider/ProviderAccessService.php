<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

use OCA\AIquila\Db\ProviderAccessRule;
use OCA\AIquila\Db\ProviderAccessRuleMapper;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Decides which providers a given user may use.
 *
 * An admin gives each provider four lists — allowed users, allowed groups,
 * blocked users, blocked groups — and this service is the only place that
 * interprets them:
 *
 *  1. A null user id is a system context (CLI, background job, admin settings
 *     page) and is never restricted.
 *  2. A block wins over any allow. Being named in `blocked_users`, or in a group
 *     named in `blocked_groups`, is final.
 *  3. Empty allow-lists mean everyone.
 *  4. Otherwise the user must be named in `allowed_users` or be in a group named
 *     in `allowed_groups`.
 *
 * This is an authorization boundary, not a display filter: LLMProviderFactory
 * routes every provider resolution through it, so filtering a provider out of a
 * settings page is a consequence of the check rather than the check itself.
 */
class ProviderAccessService {
    /**
     * The rule set is consulted on nearly every request that touches a provider,
     * so the whole (small) table is cached under one key and dropped wholesale on
     * write — the same approach ProviderSettingsService takes for model lists.
     */
    private const CACHE_KEY = 'rules';
    private const CACHE_TTL = 3600;

    /** @var array<string, array<string, list<string>>>|null in-request memo */
    private ?array $memo = null;

    /** @var array<string, list<string>> in-request memo of uid => group ids */
    private array $groupMemo = [];

    public function __construct(
        private readonly ProviderAccessRuleMapper $mapper,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Whether $userId may use $providerId. A null user id is unrestricted.
     */
    public function isAllowed(string $providerId, ?string $userId): bool {
        if ($userId === null || $userId === '') {
            return true;
        }

        $lists = $this->getLists($providerId);
        $groupIds = $this->groupIds($userId);

        if (in_array($userId, $lists['blocked_users'], true)) {
            return false;
        }
        if (array_intersect($groupIds, $lists['blocked_groups']) !== []) {
            return false;
        }

        if ($lists['allowed_users'] === [] && $lists['allowed_groups'] === []) {
            return true;
        }

        return in_array($userId, $lists['allowed_users'], true)
            || array_intersect($groupIds, $lists['allowed_groups']) !== [];
    }

    /**
     * Keep only the providers $userId may use, preserving the given order.
     *
     * @param list<string> $providerIds
     * @return list<string>
     */
    public function filterAllowed(array $providerIds, ?string $userId): array {
        if ($userId === null || $userId === '') {
            return $providerIds;
        }
        return array_values(array_filter(
            $providerIds,
            fn (string $id): bool => $this->isAllowed($id, $userId),
        ));
    }

    /**
     * The four lists of one provider, always with all four keys present.
     *
     * @return array{allowed_users: list<string>, allowed_groups: list<string>, blocked_users: list<string>, blocked_groups: list<string>}
     */
    public function getLists(string $providerId): array {
        $all = $this->allLists();
        /** @var array{allowed_users: list<string>, allowed_groups: list<string>, blocked_users: list<string>, blocked_groups: list<string>} $lists */
        $lists = $all[$providerId] ?? self::emptyLists();
        return $lists;
    }

    /**
     * Replace whichever of the four lists are present in $lists. Keys that are
     * absent are left alone, so a partial settings save cannot silently clear a
     * list the form did not render.
     *
     * @param array<string, list<string>> $lists
     */
    public function setLists(string $providerId, array $lists): void {
        foreach ($lists as $listId => $principalIds) {
            $spec = self::listSpec($listId);
            if ($spec === null) {
                continue;
            }
            $this->mapper->replaceList($providerId, $spec[0], $spec[1], array_values($principalIds));
            $this->logger->info('AIquila: provider access list updated', [
                'provider' => $providerId,
                'list' => $listId,
                'count' => count($principalIds),
            ]);
        }
        $this->forget();
    }

    /** Drop the cached rule set. */
    public function forget(): void {
        $this->memo = null;
        $this->cacheFactory->createDistributed('aiquila-provider-access')->remove(self::CACHE_KEY);
    }

    /** The list ids an admin can edit, in display order. */
    public static function listIds(): array {
        return ['allowed_users', 'allowed_groups', 'blocked_users', 'blocked_groups'];
    }

    /** True when the id names one of the four access lists. */
    public static function isListId(string $id): bool {
        return self::listSpec($id) !== null;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * The user's group ids, memoised for the request.
     *
     * A uid with no backing account resolves to no groups rather than an error:
     * the caller is asking an authorization question, and "unknown principal" has
     * to answer like a user in no groups, not like a user in every group.
     *
     * @return list<string>
     */
    private function groupIds(string $userId): array {
        if (isset($this->groupMemo[$userId])) {
            return $this->groupMemo[$userId];
        }
        $user = $this->userManager->get($userId);
        $ids = $user === null ? [] : $this->groupManager->getUserGroupIds($user);
        $this->groupMemo[$userId] = $ids;
        return $ids;
    }

    /**
     * @return array{0: string, 1: string}|null rule type and principal type
     */
    private static function listSpec(string $listId): ?array {
        return match ($listId) {
            'allowed_users' => [ProviderAccessRule::RULE_ALLOW, ProviderAccessRule::PRINCIPAL_USER],
            'allowed_groups' => [ProviderAccessRule::RULE_ALLOW, ProviderAccessRule::PRINCIPAL_GROUP],
            'blocked_users' => [ProviderAccessRule::RULE_BLOCK, ProviderAccessRule::PRINCIPAL_USER],
            'blocked_groups' => [ProviderAccessRule::RULE_BLOCK, ProviderAccessRule::PRINCIPAL_GROUP],
            default => null,
        };
    }

    /** @return array<string, list<string>> */
    private static function emptyLists(): array {
        return [
            'allowed_users' => [],
            'allowed_groups' => [],
            'blocked_users' => [],
            'blocked_groups' => [],
        ];
    }

    /**
     * Every provider's lists, keyed by provider id.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function allLists(): array {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cache = $this->cacheFactory->createDistributed('aiquila-provider-access');
        $cached = $cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            /** @var array<string, array<string, list<string>>> $cached */
            $this->memo = $cached;
            return $cached;
        }

        $byProvider = [];
        foreach ($this->mapper->findAll() as $rule) {
            $listId = $rule->getRuleType() === ProviderAccessRule::RULE_BLOCK
                ? ($rule->getPrincipalType() === ProviderAccessRule::PRINCIPAL_GROUP ? 'blocked_groups' : 'blocked_users')
                : ($rule->getPrincipalType() === ProviderAccessRule::PRINCIPAL_GROUP ? 'allowed_groups' : 'allowed_users');

            $providerId = $rule->getProviderId();
            if (!isset($byProvider[$providerId])) {
                $byProvider[$providerId] = self::emptyLists();
            }
            $byProvider[$providerId][$listId][] = $rule->getPrincipalId();
        }

        $cache->set(self::CACHE_KEY, $byProvider, self::CACHE_TTL);
        $this->memo = $byProvider;
        return $byProvider;
    }
}
