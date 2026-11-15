<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Db\Sequence;
use OCA\AIquila\Db\SequenceMapper;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\Exception\SequencePolicyException;
use OCA\AIquila\Service\SequenceCatalog;
use OCA\AIquila\Service\SequencePolicy;
use OCA\AIquila\Service\SequenceService;
use OCA\AIquila\Service\SequenceValidator;
use PHPUnit\Framework\TestCase;

class SequenceServiceTest extends TestCase {
    /** @var array<string, string> */
    private array $app = [];
    /** @var array<string, string> */
    private array $user = [];

    private function settings(): AppSettings {
        $s = $this->createStub(AppSettings::class);
        $s->method('getAppValue')->willReturnCallback(fn (string $a, string $k, string $d = ''): string => $this->app[$k] ?? $d);
        $s->method('setAppValue')->willReturnCallback(function (string $a, string $k, string $v): void {
            $this->app[$k] = $v;
        });
        $s->method('getUserValue')->willReturnCallback(fn (string $u, string $a, string $k, string $d = ''): string => $this->user[$u . '/' . $k] ?? $d);
        $s->method('setUserValue')->willReturnCallback(function (string $u, string $a, string $k, string $v): void {
            $this->user[$u . '/' . $k] = $v;
        });
        return $s;
    }

    private function service(SequenceMapper $mapper): SequenceService {
        $catalog = new SequenceCatalog();
        $policy = new SequencePolicy($this->settings(), $catalog);
        return new SequenceService($mapper, new SequenceValidator($catalog), $policy, $catalog);
    }

    private function sequence(string $scope, string $slug, bool $enabled = true): Sequence {
        $s = new Sequence();
        $s->setScope($scope);
        $s->setSlug($slug);
        $s->setTitle($slug);
        $s->setDefinitionArray(['inputs' => [], 'steps' => [['id' => 'a', 'tool' => 'list_notifications', 'arguments' => [], 'onFailure' => 'stop']]]);
        $s->setEnabled($enabled ? 1 : 0);
        return $s;
    }

    public function testEffectiveHoldsEnabledRowsAndTheUnionOfDisabledBuiltins(): void {
        $mapper = $this->createStub(SequenceMapper::class);
        $mapper->method('findAllInstance')->willReturn([$this->sequence('instance', 'org'), $this->sequence('instance', 'off', false)]);
        $mapper->method('findAllByUser')->willReturn([$this->sequence('user', 'mine')]);
        $this->app['sequences_disabled_builtins'] = '["back_at_work"]';
        $this->user['alice/sequences_disabled_builtins'] = '["inbox_triage","nonsense"]';

        $body = $this->service($mapper)->effective('alice');

        $this->assertSame(['org', 'mine'], array_column($body['sequences'], 'slug'));
        $this->assertSame(['back_at_work', 'inbox_triage'], $body['disabledBuiltins']);
        $this->assertSame(['allowPersonal' => true, 'maxPerUser' => 50, 'maxSteps' => 20, 'maxPinned' => 10], $body['policy']);
        // Empty arguments go out as an object, which the MCP server's schema needs.
        $this->assertSame('{}', json_encode($body['sequences'][0]['definition']['steps'][0]['arguments']));
    }

    public function testEffectiveLeavesOutPersonalSequencesWhenSwitchedOff(): void {
        $mapper = $this->createStub(SequenceMapper::class);
        $mapper->method('findAllInstance')->willReturn([]);
        $mapper->method('findAllByUser')->willReturn([$this->sequence('user', 'mine')]);
        $this->app['sequences_allow_personal'] = '0';

        $this->assertSame([], $this->service($mapper)->effective('alice')['sequences']);
    }

    public function testCreatingAPersonalSequenceFollowsThePolicy(): void {
        $mapper = $this->createStub(SequenceMapper::class);
        $mapper->method('findAllByUser')->willReturn([$this->sequence('user', 'a')]);
        $mapper->method('insert')->willReturnArgument(0);
        $fields = ['slug' => 'b', 'title' => 'B', 'definition' => ['steps' => [['tool' => 'list_notifications']]]];

        $this->app['sequences_max_per_user'] = '1';
        try {
            $this->service($mapper)->create('user', 'alice', $fields);
            $this->fail('created past the maximum');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('maximum', $e->getMessage());
        }

        $this->app['sequences_allow_personal'] = '0';
        $this->expectException(SequencePolicyException::class);
        $this->service($mapper)->create('user', 'alice', $fields);
    }

    public function testAnOrganizationSequenceHasNoOwnerButAnAuthor(): void {
        $mapper = $this->createStub(SequenceMapper::class);
        $mapper->method('insert')->willReturnArgument(0);
        $this->app['sequences_allow_personal'] = '0';

        $s = $this->service($mapper)->create('instance', 'admin', ['slug' => 'org', 'title' => 'Org', 'definition' => ['steps' => [['tool' => 'list_notifications']]]]);

        $this->assertSame('', $s->getUserId());
        $this->assertSame('admin', $s->getAuthorId());
    }

    public function testPinningIsCapped(): void {
        $pinned = $this->sequence('user', 'a');
        $pinned->setPinned(1);
        $pinned->setId(1);
        $mapper = $this->createStub(SequenceMapper::class);
        $mapper->method('findAllByUser')->willReturn([$pinned]);
        $this->app['sequences_max_pinned'] = '1';
        $other = $this->sequence('user', 'b');
        $other->setId(2);
        $other->setUserId('alice');

        $this->expectExceptionMessageIsOrContains('own tool');
        $this->service($mapper)->update($other, ['pinned' => true]);
    }

    public function testEtagsAndIfNoneMatch(): void {
        $etag = SequenceService::etag(['a' => 1]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $etag);
        $this->assertSame($etag, SequenceService::etag(['a' => 1]));
        $this->assertNotSame($etag, SequenceService::etag(['a' => 2]));

        // RFC 9110 §13.1.2: weak comparison, a list of tags, or *.
        $this->assertTrue(SequenceService::noneMatch('"' . $etag . '"', $etag));
        $this->assertTrue(SequenceService::noneMatch('W/"' . $etag . '"', $etag));
        $this->assertTrue(SequenceService::noneMatch('"x", "' . $etag . '"', $etag));
        $this->assertTrue(SequenceService::noneMatch('*', $etag));
        $this->assertFalse(SequenceService::noneMatch('', $etag));
        $this->assertFalse(SequenceService::noneMatch('"x"', $etag));
    }

    public function testBuiltinsCanBeSwitchedOffByTheAdminAndByAUser(): void {
        $catalog = new SequenceCatalog();
        $policy = new SequencePolicy($this->settings(), $catalog);
        $policy->setBuiltinEnabled('back_at_work', false);
        $policy->setUserBuiltinEnabled('alice', 'inbox_triage', false);
        $service = new SequenceService($this->createStub(SequenceMapper::class), new SequenceValidator($catalog), $policy, $catalog);

        $state = array_column($service->builtinsFor('alice'), null, 'slug');
        $this->assertFalse($state['back_at_work']['adminEnabled']);
        $this->assertFalse($state['back_at_work']['enabled']);
        $this->assertTrue($state['inbox_triage']['adminEnabled']);
        $this->assertFalse($state['inbox_triage']['enabled']);
        $this->assertTrue($state['daily_briefing']['enabled']);

        $this->expectException(\InvalidArgumentException::class);
        $policy->setUserBuiltinEnabled('alice', 'not_a_builtin', false);
    }

    public function testPolicyRangesAreChecked(): void {
        $policy = new SequencePolicy($this->settings(), new SequenceCatalog());
        $policy->set(false, 5, 3, 2);
        $this->assertSame(['allowPersonal' => false, 'maxPerUser' => 5, 'maxSteps' => 3, 'maxPinned' => 2], $policy->get());
        $this->expectExceptionMessageIsOrContains('maxSteps must be between 1 and 20');
        $policy->set(null, null, 21, null);
    }
}
