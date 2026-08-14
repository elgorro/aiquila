<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Db\ProviderAccessRule;
use OCA\AIquila\Db\ProviderAccessRuleMapper;
use OCA\AIquila\Service\Provider\ProviderAccessService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The resolution matrix from GH #463: empty allow-list means everyone, a block
 * always wins, and a null user id (CLI, background job) is never restricted.
 */
class ProviderAccessServiceTest extends TestCase {
    private $mapper;
    private $groupManager;
    private $userManager;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ProviderAccessRuleMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $rules
     *        provider, allow|block, user|group, principal
     * @param list<string> $groupIds groups every user in the test belongs to
     */
    private function service(array $rules, array $groupIds = []): ProviderAccessService {
        $entities = [];
        foreach ($rules as [$providerId, $ruleType, $principalType, $principalId]) {
            $rule = new ProviderAccessRule();
            $rule->setProviderId($providerId);
            $rule->setRuleType($ruleType);
            $rule->setPrincipalType($principalType);
            $rule->setPrincipalId($principalId);
            $entities[] = $rule;
        }
        $this->mapper->method('findAll')->willReturn($entities);

        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groupIds);

        // A cache that never hits, so every test reads the rules it declared.
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(null);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        return new ProviderAccessService(
            $this->mapper,
            $this->groupManager,
            $this->userManager,
            $cacheFactory,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testNoRulesAllowsEveryone(): void {
        $service = $this->service([]);
        $this->assertTrue($service->isAllowed('anthropic', 'alice'));
    }

    public function testEmptyAllowListMeansEveryone(): void {
        // Rules exist for another provider entirely; anthropic stays open.
        $service = $this->service([['mistral', 'allow', 'user', 'bob']]);
        $this->assertTrue($service->isAllowed('anthropic', 'alice'));
        $this->assertFalse($service->isAllowed('mistral', 'alice'));
        $this->assertTrue($service->isAllowed('mistral', 'bob'));
    }

    public function testAllowedGroupGrantsAccess(): void {
        $service = $this->service([['hetzner', 'allow', 'group', 'marketing']], ['marketing']);
        $this->assertTrue($service->isAllowed('hetzner', 'alice'));
    }

    public function testNonMemberOfAllowedGroupIsDenied(): void {
        $service = $this->service([['hetzner', 'allow', 'group', 'marketing']], ['sales']);
        $this->assertFalse($service->isAllowed('hetzner', 'alice'));
    }

    public function testBlockedUserWinsOverAllowedUser(): void {
        $service = $this->service([
            ['anthropic', 'allow', 'user', 'alice'],
            ['anthropic', 'block', 'user', 'alice'],
        ]);
        $this->assertFalse($service->isAllowed('anthropic', 'alice'));
    }

    public function testBlockedGroupWinsOverAllowedUser(): void {
        $service = $this->service([
            ['anthropic', 'allow', 'user', 'alice'],
            ['anthropic', 'block', 'group', 'contractors'],
        ], ['contractors']);
        $this->assertFalse($service->isAllowed('anthropic', 'alice'));
    }

    public function testBlockAppliesWithNoAllowListAtAll(): void {
        $service = $this->service([['anthropic', 'block', 'group', 'contractors']], ['contractors']);
        $this->assertFalse($service->isAllowed('anthropic', 'alice'));
    }

    public function testNullUserIsUnrestricted(): void {
        $service = $this->service([['anthropic', 'block', 'user', 'alice']]);
        $this->assertTrue($service->isAllowed('anthropic', null));
        $this->assertSame(['anthropic'], $service->filterAllowed(['anthropic'], null));
    }

    public function testFilterAllowedPreservesDisplayOrder(): void {
        $service = $this->service([
            ['anthropic', 'block', 'user', 'alice'],
            ['deepseek', 'allow', 'group', 'marketing'],
        ], ['sales']);

        $this->assertSame(
            ['mistral', 'hetzner', 'local'],
            $service->filterAllowed(['anthropic', 'mistral', 'deepseek', 'hetzner', 'local'], 'alice'),
        );
    }

    public function testGetListsAlwaysReturnsAllFourKeys(): void {
        $service = $this->service([['mistral', 'allow', 'group', 'marketing']]);

        $this->assertSame([
            'allowed_users' => [],
            'allowed_groups' => ['marketing'],
            'blocked_users' => [],
            'blocked_groups' => [],
        ], $service->getLists('mistral'));

        $this->assertSame([
            'allowed_users' => [],
            'allowed_groups' => [],
            'blocked_users' => [],
            'blocked_groups' => [],
        ], $service->getLists('anthropic'));
    }

    public function testSetListsReplacesOnlyTheNamedLists(): void {
        $service = $this->service([]);

        $written = [];
        $this->mapper->method('replaceList')->willReturnCallback(
            function (string $provider, string $rule, string $principal, array $ids) use (&$written): void {
                $written[] = [$provider, $rule, $principal, $ids];
            },
        );

        $service->setLists('mistral', [
            'blocked_groups' => ['contractors'],
            'not_a_list' => ['ignored'],
        ]);

        $this->assertSame([['mistral', 'block', 'group', ['contractors']]], $written);
    }

    /**
     * QBMapper::insert() writes only the fields Entity marked as updated, and
     * Entity marks a field updated only when the value changes. A property
     * defaulted to 'allow'/'user' would therefore be omitted from the INSERT
     * for exactly the rows that use those values, and the NOT NULL column would
     * reject the row.
     */
    public function testEveryColumnIsMarkedUpdatedEvenAtItsMostCommonValue(): void {
        $rule = new ProviderAccessRule();
        $rule->setProviderId('anthropic');
        $rule->setRuleType(ProviderAccessRule::RULE_ALLOW);
        $rule->setPrincipalType(ProviderAccessRule::PRINCIPAL_USER);
        $rule->setPrincipalId('alice');

        $this->assertSame(
            ['providerId', 'ruleType', 'principalType', 'principalId'],
            array_keys($rule->getUpdatedFields()),
        );
    }
}
