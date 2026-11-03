<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Service\ToolTrace;
use PHPUnit\Framework\TestCase;

class ToolTraceTest extends TestCase {
    /** What a Claude tool loop appends: thinking, a call, its result. */
    private static function loopTurns(string $result = 'a.txt, b.txt'): array {
        return [
            ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => 'List first.', 'signature' => 'sig'],
                ['type' => 'text', 'text' => 'Let me look.'],
                ['type' => 'tool_use', 'id' => 'toolu_01AbC', 'name' => 'list_files', 'input' => ['path' => '/']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_01AbC', 'content' => $result],
            ]],
        ];
    }

    public function testKeepsCallsAndResultsAndDropsThinking(): void {
        $trace = ToolTrace::decode(ToolTrace::encode(self::loopTurns()));

        $this->assertSame(['text', 'tool_use'], array_column($trace[0]['content'], 'type'));
        $this->assertSame('list_files', $trace[0]['content'][1]['name']);
        $this->assertSame(['path' => '/'], $trace[0]['content'][1]['input']);
        $this->assertSame('a.txt, b.txt', $trace[1]['content'][0]['content']);
    }

    /**
     * Mistral accepts only nine alphanumerics as a tool call id; the stored
     * id has that form, and the result still points at its call.
     */
    public function testIdsAreRenamedForEveryProviderAndStillPair(): void {
        $trace = ToolTrace::decode(ToolTrace::encode(self::loopTurns()));

        $id = $trace[0]['content'][1]['id'];
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{9}$/', $id);
        $this->assertSame($id, $trace[1]['content'][0]['tool_use_id']);
    }

    /** Stored once, replayed as stored: the same input gives the same bytes. */
    public function testEncodingIsDeterministic(): void {
        $this->assertSame(ToolTrace::encode(self::loopTurns()), ToolTrace::encode(self::loopTurns()));
    }

    public function testLongResultsAreCutWhenStored(): void {
        $trace = ToolTrace::decode(ToolTrace::encode(self::loopTurns(str_repeat('x', ToolTrace::MAX_RESULT_CHARS + 250))));

        $content = $trace[1]['content'][0]['content'];
        $this->assertStringStartsWith(str_repeat('x', ToolTrace::MAX_RESULT_CHARS), $content);
        $this->assertStringEndsWith('[250 more characters not kept]', $content);
    }

    public function testBlockResultsBecomeText(): void {
        $turns = self::loopTurns();
        $turns[1]['content'][0]['content'] = [['type' => 'text', 'text' => 'one'], ['type' => 'text', 'text' => 'two']];
        $turns[1]['content'][0]['is_error'] = true;

        $result = ToolTrace::decode(ToolTrace::encode($turns))[1]['content'][0];
        $this->assertSame("one\ntwo", $result['content']);
        $this->assertTrue($result['is_error']);
    }

    public function testNothingToKeepWithoutAToolCall(): void {
        $this->assertNull(ToolTrace::encode([]));
        $this->assertNull(ToolTrace::encode([['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Just prose']]]]));
    }

    public function testUnreadableTracesDecodeAsNone(): void {
        $this->assertSame([], ToolTrace::decode(null));
        $this->assertSame([], ToolTrace::decode('{oops'));
    }

    /** Without tools defined on the request, the calls travel as labelled text. */
    public function testFlattenKeepsWhatTheToolsSaid(): void {
        $messages = array_merge(
            [['role' => 'user', 'content' => 'What is in /?']],
            ToolTrace::decode(ToolTrace::encode(self::loopTurns())),
            [['role' => 'assistant', 'content' => 'Two files.']],
        );

        $flat = ToolTrace::flatten($messages);

        $this->assertSame('[Called tool list_files with {"path":"/"}]', $flat[1]['content'][1]['text']);
        $this->assertSame('[Tool list_files returned: a.txt, b.txt]', $flat[2]['content'][0]['text']);
        $this->assertSame('What is in /?', $flat[0]['content'], 'plain messages are untouched');
        $this->assertSame('Two files.', $flat[3]['content']);
    }
}
