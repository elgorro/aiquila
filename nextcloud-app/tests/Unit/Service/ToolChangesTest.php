<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Service\Provider\SystemMessages;
use OCA\AIquila\Service\ToolChanges;
use PHPUnit\Framework\TestCase;

class ToolChangesTest extends TestCase {
    private static function tool(string $name, string $description = 'd'): array {
        return ['name' => $name, 'description' => $description, 'input_schema' => ['type' => 'object']];
    }

    private static function names(array $tools): array {
        return array_column($tools, 'name');
    }

    public function testTheFirstTurnSendsTheToolsAsTheyAre(): void {
        $plan = ToolChanges::plan(null, [self::tool('a'), self::tool('b')]);

        $this->assertSame([self::tool('a'), self::tool('b')], $plan['tools']);
        $this->assertSame([], $plan['blocks']);
        $this->assertFalse($plan['reset']);
    }

    /** A new tool goes to the end, deferred, and is offered by reference. */
    public function testANewToolIsDeferredAndAdded(): void {
        $first = ToolChanges::plan(null, [self::tool('a')]);
        $plan = ToolChanges::plan($first['state'], [self::tool('a'), self::tool('b')]);

        $this->assertSame(self::tool('a'), $plan['tools'][0], 'the start of tools is unchanged');
        $this->assertSame(self::tool('b') + ['defer_loading' => true], $plan['tools'][1]);
        $this->assertSame([['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => 'b']]], $plan['blocks']);
    }

    /** A removed tool stays declared, so its reference resolves, and is withdrawn. */
    public function testARemovedToolStaysDeclaredAndIsWithdrawn(): void {
        $first = ToolChanges::plan(null, [self::tool('a'), self::tool('b')]);
        $plan = ToolChanges::plan($first['state'], [self::tool('a')]);

        $this->assertSame(['a', 'b'], self::names($plan['tools']));
        $this->assertSame([['type' => 'tool_removal', 'tool' => ['type' => 'tool_reference', 'name' => 'b']]], $plan['blocks']);
        $this->assertSame(['a'], $plan['state']['available']);
    }

    public function testAToolThatComesBackIsOfferedAgainNotRedeclared(): void {
        $s1 = ToolChanges::plan(null, [self::tool('a'), self::tool('b')])['state'];
        $s2 = ToolChanges::plan($s1, [self::tool('a')])['state'];
        $plan = ToolChanges::plan($s2, [self::tool('a'), self::tool('b')]);

        $this->assertSame(['a', 'b'], self::names($plan['tools']));
        $this->assertSame('tool_addition', $plan['blocks'][0]['type']);
    }

    /** The tools the server lists in another order change nothing. */
    public function testOrderOfTheCurrentToolsDoesNotMatter(): void {
        $first = ToolChanges::plan(null, [self::tool('a'), self::tool('b')]);
        $plan = ToolChanges::plan($first['state'], [self::tool('b'), self::tool('a')]);

        $this->assertSame($first['tools'], $plan['tools']);
        $this->assertSame([], $plan['blocks']);
    }

    /** A reference cannot redefine a tool: a changed definition starts over. */
    public function testARedefinedToolStartsOver(): void {
        $first = ToolChanges::plan(null, [self::tool('a', 'old')]);
        $plan = ToolChanges::plan($first['state'], [self::tool('a', 'new')]);

        $this->assertTrue($plan['reset']);
        $this->assertSame([self::tool('a', 'new')], $plan['tools']);
        $this->assertSame([], $plan['blocks']);
    }

    public function testStateRoundTrips(): void {
        $state = ToolChanges::plan(null, [self::tool('a')])['state'];
        $this->assertSame($state, ToolChanges::decodeState(ToolChanges::encodeState($state)));
        $this->assertNull(ToolChanges::decodeState('nope'));
    }

    public function testRequestsUsingItAreRecognised(): void {
        $this->assertFalse(ToolChanges::inRequest(['tools' => [self::tool('a')], 'messages' => []]));
        $this->assertTrue(ToolChanges::inRequest(['tools' => [self::tool('a') + ['defer_loading' => true]], 'messages' => []]));
        $this->assertTrue(ToolChanges::inRequest(['messages' => [['role' => 'system', 'content' => [['type' => 'tool_removal', 'tool' => ['type' => 'tool_reference', 'name' => 'a']]]]]]));
    }

    public function testDescribeAllTurnsChangesIntoText(): void {
        $messages = ToolChanges::describeAll([['role' => 'system', 'content' => [
            ['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => 'b']],
            ['type' => 'tool_removal', 'tool' => ['type' => 'tool_reference', 'name' => 'c']],
        ]]]);

        $this->assertSame('The tool b is available from here on.', $messages[0]['content'][0]['text']);
        $this->assertSame('The tool c is no longer available.', $messages[0]['content'][1]['text']);
    }

    // ── How SystemMessages carries them ───────────────────────────────────

    private const ADD_B = ['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => 'b']];

    /** Placeable on a model with the role: the blocks go out as blocks, beside the date note. */
    public function testPlacedSystemMessagesKeepTheirBlocks(): void {
        $out = SystemMessages::prepare([
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'system', 'content' => 'Today is Monday.'],
            ['role' => 'system', 'content' => [self::ADD_B]],
        ], true);

        $this->assertSame(['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Today is Monday.'], self::ADD_B]], $out[1]);
    }

    /** Text-only system messages go out as they always did, as a string. */
    public function testPlainSystemTextIsUnchanged(): void {
        $out = SystemMessages::prepare([['role' => 'user', 'content' => 'Hi'], ['role' => 'system', 'content' => 'Today is Monday.']], true);
        $this->assertSame(['role' => 'system', 'content' => 'Today is Monday.'], $out[1]);
    }

    public function testFoldedChangesAreDescribed(): void {
        $out = SystemMessages::prepare([['role' => 'user', 'content' => 'Hi'], ['role' => 'system', 'content' => [self::ADD_B]]], false);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('The tool b is available from here on.', json_encode($out[0]['content']));
    }
}
