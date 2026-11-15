<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Db\Sequence;
use OCA\AIquila\Service\SequenceCatalog;
use OCA\AIquila\Service\SequenceValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Against the committed catalog, which mcp-server generates from its tools.
 */
class SequenceValidatorTest extends TestCase {
    private function validator(): SequenceValidator {
        return new SequenceValidator(new SequenceCatalog());
    }

    public function testTheCatalogShipsWithTheApp(): void {
        $catalog = new SequenceCatalog();
        $this->assertSame('aiquila-sequence-catalog', $catalog->data()['format']);
        $this->assertNotNull($catalog->tool('list_events'));
        $this->assertNull($catalog->tool('run_sequence'), 'sequence tools are never steps');
        $this->assertContains('daily_briefing', $catalog->builtinSlugs());
    }

    public function testEveryBuiltinPassesValidation(): void {
        foreach ((new SequenceCatalog())->builtins() as $builtin) {
            $this->validator()->validateDefinition(['inputs' => $builtin['inputs'], 'steps' => $builtin['steps']], 20);
        }
        $this->addToAssertionCount(1);
    }

    public function testNormalizesAValidDefinition(): void {
        $out = $this->validator()->validateDefinition([
            'inputs' => [['name' => 'cal', 'type' => 'string', 'default' => 'personal']],
            'steps' => [
                ['tool' => 'list_events', 'arguments' => ['calendarName' => '{{inputs.cal}}', 'from' => '{{today}}', 'to' => '{{today+7d}}']],
                ['id' => 'n', 'tool' => 'list_notifications', 'onFailure' => 'continue'],
                ['tool' => 'get_note', 'arguments' => ['id' => '{{steps.n.result.items.0.id}}']],
            ],
        ], 20);

        $this->assertSame(['name' => 'cal', 'type' => 'string', 'required' => false, 'description' => '', 'default' => 'personal'], $out['inputs'][0]);
        $this->assertSame(['step1', 'n', 'step3'], array_column($out['steps'], 'id'));
        $this->assertSame('stop', $out['steps'][0]['onFailure']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array {
        $ok = ['tool' => 'list_notifications'];
        return [
            'no steps' => [['steps' => []], 'at least one step'],
            'unknown tool' => [['steps' => [['tool' => 'nope']]], 'unknown tool'],
            'nested sequence' => [['steps' => [['tool' => 'run_sequence']]], 'unknown tool'],
            'other server' => [['steps' => [['tool' => 'list_notifications', 'server' => 'x']]], 'other MCP servers'],
            'bad on failure' => [['steps' => [['tool' => 'list_notifications', 'onFailure' => 'retry']]], 'on failure'],
            'unknown argument' => [['steps' => [['tool' => 'list_notifications', 'arguments' => ['x' => 1]]]], 'has no argument "x"'],
            'missing required' => [['steps' => [['tool' => 'list_events', 'arguments' => []]]], 'needs the argument "calendarName"'],
            'wrong literal type' => [['steps' => [['tool' => 'list_events', 'arguments' => ['calendarName' => 'p', 'limit' => 'many']]]], '"limit" must be of type number'],
            'enum' => [['steps' => [['tool' => 'list_tasks', 'arguments' => ['status' => 'DONE']]]], 'must be one of'],
            'undeclared input' => [['steps' => [['tool' => 'list_events', 'arguments' => ['calendarName' => '{{inputs.cal}}']]]], 'not a declared input'],
            'later step' => [['steps' => [['tool' => 'list_events', 'arguments' => ['calendarName' => '{{steps.b.text}}']], ['id' => 'b'] + $ok]], 'earlier step'],
            'bad step field' => [['steps' => [['id' => 'a'] + $ok, ['tool' => 'list_events', 'arguments' => ['calendarName' => '{{steps.a.foo}}']]]], 'steps.a.text'],
            'today in hours' => [['steps' => [['tool' => 'list_events', 'arguments' => ['calendarName' => 'p', 'from' => '{{today+2h}}']]]], 'days (d) or weeks (w)'],
            'offset on input' => [['inputs' => [['name' => 'c', 'type' => 'string']], 'steps' => [['tool' => 'list_events', 'arguments' => ['calendarName' => '{{inputs.c+1d}}']]]], 'only today and now'],
            'duplicate step id' => [['steps' => [['id' => 'a'] + $ok, ['id' => 'a'] + $ok]], 'Two steps'],
            'reserved input' => [['inputs' => [['name' => 'dryRun', 'type' => 'boolean']], 'steps' => [$ok]], 'reserved'],
            'duplicate input' => [['inputs' => [['name' => 'a', 'type' => 'string'], ['name' => 'a', 'type' => 'string']], 'steps' => [$ok]], 'declared twice'],
            'bad input type' => [['inputs' => [['name' => 'a', 'type' => 'date']], 'steps' => [$ok]], 'unknown type'],
            'default of wrong type' => [['inputs' => [['name' => 'a', 'type' => 'integer', 'default' => 'x']], 'steps' => [$ok]], 'default is not of type integer'],
        ];
    }

    #[DataProvider('invalid')]
    public function testRejects(array $definition, string $message): void {
        try {
            $this->validator()->validateDefinition($definition, 20);
            $this->fail('accepted an invalid definition');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testThePolicyCapsSteps(): void {
        $this->expectExceptionMessageIsOrContains('at most 1 steps');
        $this->validator()->validateDefinition(['steps' => [['tool' => 'list_notifications'], ['tool' => 'list_notifications']]], 1);
    }

    public function testBracesThatNameNoVariableAreText(): void {
        $this->validator()->validateDefinition(['steps' => [['tool' => 'create_note', 'arguments' => ['title' => 'T', 'content' => 'Hi {{ name }}']]]], 20);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function slugs(): array {
        return [
            'plain' => ['standup', Sequence::SCOPE_USER, true],
            'dash and underscore' => ['my-weekly_review', Sequence::SCOPE_USER, true],
            'uppercase' => ['Standup', Sequence::SCOPE_USER, false],
            'too long' => [str_repeat('a', 49), Sequence::SCOPE_USER, false],
            'leading dash' => ['-x', Sequence::SCOPE_USER, false],
            'built-in name for the organization' => ['daily_briefing', Sequence::SCOPE_INSTANCE, false],
            'built-in name for a user' => ['daily_briefing', Sequence::SCOPE_USER, true],
            'organization squatting seq_my_' => ['my_report', Sequence::SCOPE_INSTANCE, false],
        ];
    }

    #[DataProvider('slugs')]
    public function testSlugs(string $slug, string $scope, bool $valid): void {
        try {
            $this->validator()->validateSlug($slug, $scope);
            $this->assertTrue($valid, 'accepted ' . $slug);
        } catch (\InvalidArgumentException) {
            $this->assertFalse($valid, 'rejected ' . $slug);
        }
    }
}
