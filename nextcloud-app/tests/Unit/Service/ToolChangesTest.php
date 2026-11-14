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

    // ── By value (inline-tools-2026-09-15) ───────────────────────────────

    /** A changed tool is redefined in place: tools untouched, no reset. */
    public function testByValueARedefinedToolIsOfferedWithItsNewDefinition(): void {
        $first = ToolChanges::plan(null, [self::tool('a', 'old'), self::tool('b')], true);
        $plan = ToolChanges::plan($first['state'], [self::tool('a', 'new'), self::tool('b')], true);

        $this->assertFalse($plan['reset']);
        $this->assertSame($first['tools'], $plan['tools'], 'tools stays as first sent');
        $this->assertSame([[
            'type' => 'tool_addition',
            'tool' => ['type' => 'tool_definition', 'definition' => self::tool('a', 'new')],
        ]], $plan['blocks']);
        $this->assertSame(['a' => self::tool('a', 'new')], $plan['state']['current']);
    }

    public function testByValueAnUnchangedRedefinitionSendsNothing(): void {
        $s1 = ToolChanges::plan(null, [self::tool('a', 'old')], true)['state'];
        $s2 = ToolChanges::plan($s1, [self::tool('a', 'new')], true)['state'];
        $plan = ToolChanges::plan($s2, [self::tool('a', 'new')], true);

        $this->assertSame([], $plan['blocks']);
        $this->assertSame($s2, $plan['state']);
    }

    /** A reference names the declared tool, so a redefined one comes back by value. */
    public function testByValueARedefinedToolComesBackByValue(): void {
        $s1 = ToolChanges::plan(null, [self::tool('a', 'old'), self::tool('b')], true)['state'];
        $s2 = ToolChanges::plan($s1, [self::tool('a', 'new'), self::tool('b')], true)['state'];
        $s3 = ToolChanges::plan($s2, [self::tool('b')], true);
        $this->assertSame('tool_removal', $s3['blocks'][0]['type']);

        $plan = ToolChanges::plan($s3['state'], [self::tool('a', 'new'), self::tool('b')], true);
        $this->assertSame('tool_definition', $plan['blocks'][0]['tool']['type']);
        $this->assertSame(self::tool('a', 'new'), $plan['blocks'][0]['tool']['definition']);
    }

    public function testByValueChangingBackToTheDeclaredDefinitionClearsTheOverride(): void {
        $s1 = ToolChanges::plan(null, [self::tool('a', 'old')], true)['state'];
        $s2 = ToolChanges::plan($s1, [self::tool('a', 'new')], true)['state'];
        $plan = ToolChanges::plan($s2, [self::tool('a', 'old')], true);

        $this->assertSame(self::tool('a', 'old'), $plan['blocks'][0]['tool']['definition']);
        $this->assertArrayNotHasKey('current', $plan['state']);
    }

    /** New tools still go by reference, so they cost nothing by value. */
    public function testByValueANewToolIsStillDeferredAndReferenced(): void {
        $first = ToolChanges::plan(null, [self::tool('a')], true);
        $plan = ToolChanges::plan($first['state'], [self::tool('a'), self::tool('b')], true);

        $this->assertSame(['type' => 'tool_reference', 'name' => 'b'], $plan['blocks'][0]['tool']);
        $this->assertTrue($plan['tools'][1]['defer_loading']);
    }

    /** A history with by-value blocks cannot go to a request without the header. */
    public function testAStateWithOverridesStartsOverWithoutByValue(): void {
        $s1 = ToolChanges::plan(null, [self::tool('a', 'old')], true)['state'];
        $s2 = ToolChanges::plan($s1, [self::tool('a', 'new')], true)['state'];
        $plan = ToolChanges::plan($s2, [self::tool('a', 'new')], false);

        $this->assertTrue($plan['reset']);
        $this->assertSame([self::tool('a', 'new')], $plan['tools']);
    }

    /** Past the cap the state starts over instead of risking available_tools_limit_exceeded. */
    public function testByValueStartsOverPastTheSizeCap(): void {
        $first = ToolChanges::plan(null, [self::tool('a', 'old')], true);
        $huge = self::tool('a', str_repeat('x', ToolChanges::MAX_BY_VALUE_BYTES));
        $plan = ToolChanges::plan($first['state'], [$huge], true);

        $this->assertTrue($plan['reset']);
        $this->assertSame([$huge], $plan['tools']);
        $this->assertSame([], $plan['blocks']);
    }

    public function testOverridesRoundTripWithEmptySchemaObjects(): void {
        $empty = ['name' => 'a', 'description' => 'new', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]];
        $s1 = ToolChanges::plan(null, [self::tool('a', 'old')], true)['state'];
        $s2 = ToolChanges::plan($s1, [$empty], true)['state'];
        $decoded = ToolChanges::decodeState(ToolChanges::encodeState($s2));

        $this->assertSame(json_encode($s2), json_encode($decoded));
        $this->assertSame([], ToolChanges::plan($decoded, [$empty], true)['blocks']);
    }

    public function testByValueBlocksNeedInlineToolsAndDescribeThemselves(): void {
        $block = ['type' => 'tool_addition', 'tool' => ['type' => 'tool_definition', 'definition' => self::tool('a')]];
        $params = ['messages' => [['role' => 'user', 'content' => 'x'], ['role' => 'system', 'content' => [$block]]]];

        $this->assertTrue(ToolChanges::needsInlineTools($params));
        $this->assertTrue(ToolChanges::inRequest($params));
        $this->assertFalse(ToolChanges::needsInlineTools(['messages' => [['role' => 'system', 'content' => [
            ['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => 'a']],
        ]]]]));
        $this->assertSame('The tool a changed from here on.', ToolChanges::describe($block));
    }

    public function testStateRoundTrips(): void {
        $state = ToolChanges::plan(null, [self::tool('a')])['state'];
        $this->assertSame($state, ToolChanges::decodeState(ToolChanges::encodeState($state)));
        $this->assertNull(ToolChanges::decodeState('nope'));
    }

    /**
     * The state is stored as JSON and decoded into PHP arrays, which turns
     * each `{}` in a declared schema into `[]`. Decoded back, the declared
     * tools must resend byte-identical and still compare equal to the same
     * tools fetched again, or every turn would start over and miss the cache.
     */
    public function testEmptySchemaObjectsSurviveTheStoredState(): void {
        $tool = [
            'name' => 'browser_close',
            'description' => 'Close the page',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
        ];
        $first = ToolChanges::plan(null, [$tool]);

        $state = ToolChanges::decodeState(ToolChanges::encodeState($first['state']));
        $plan = ToolChanges::plan($state, [$tool]);

        $this->assertFalse($plan['reset']);
        $this->assertSame([], $plan['blocks']);
        $this->assertSame(json_encode($first['tools']), json_encode($plan['tools']));
        $this->assertStringContainsString('"properties":{}', (string)json_encode($plan['tools']));
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
