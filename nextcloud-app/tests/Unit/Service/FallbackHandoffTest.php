<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use Anthropic\Core\Conversion;
use Anthropic\Messages\Message;
use OCA\AIquila\Service\FallbackHandoff;
use PHPUnit\Framework\TestCase;

/**
 * What survives a server-side fallback handoff when the turn is echoed back.
 * Rules from https://platform.claude.com/docs/en/build-with-claude/refusals-and-fallback#continuing-the-conversation
 */
class FallbackHandoffTest extends TestCase {
    private const HANDOFF = [
        'type' => 'fallback',
        'from' => ['model' => 'claude-opus-5'],
        'to' => ['model' => 'claude-opus-4-8'],
        'trigger' => ['type' => 'refusal', 'category' => 'cyber'],
    ];

    /**
     * Parse $content the way the GA SDK parses a real response. The GA
     * content union has no `fallback` variant, so this is where the block
     * turns into another class with `type` still `fallback`.
     *
     * @param list<array<string, mixed>> $content
     * @param list<array<string, mixed>>|null $iterations
     */
    private static function message(array $content, ?array $iterations = null): Message {
        $usage = ['input_tokens' => 10, 'output_tokens' => 5];
        if ($iterations !== null) {
            $usage['iterations'] = $iterations;
        }
        /** @var Message */
        return Conversion::coerce(Message::class, [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-4-8',
            'content' => $content, 'stop_reason' => 'end_turn', 'stop_details' => null, 'stop_sequence' => null,
            'usage' => $usage,
        ]);
    }

    public function testDropsThinkingAndToolCallsOfTheDeclinedModelButKeepsText(): void {
        $blocks = [
            ['type' => 'thinking', 'thinking' => 'a', 'signature' => 's'],
            ['type' => 'text', 'text' => 'Partial'],
            ['type' => 'tool_use', 'id' => 'tu_declined', 'name' => 'x', 'input' => []],
            ['type' => 'redacted_thinking', 'data' => 'r'],
            self::HANDOFF,
            ['type' => 'thinking', 'thinking' => 'b', 'signature' => 't'],
            ['type' => 'text', 'text' => ' continued'],
            ['type' => 'tool_use', 'id' => 'tu_kept', 'name' => 'y', 'input' => []],
        ];

        $kept = FallbackHandoff::afterHandoff($blocks);

        $this->assertSame(['text', 'fallback', 'thinking', 'text', 'tool_use'], array_map(FallbackHandoff::type(...), $kept));
        $this->assertSame('tu_kept', $kept[4]['id']);
    }

    /** Only the last handoff counts: everything before it was declined. */
    public function testUsesTheFinalHandoff(): void {
        $blocks = [
            self::HANDOFF,
            ['type' => 'tool_use', 'id' => 'tu_second_model', 'name' => 'x', 'input' => []],
            self::HANDOFF,
            ['type' => 'text', 'text' => 'done'],
        ];

        $this->assertSame(['fallback', 'fallback', 'text'], array_map(FallbackHandoff::type(...), FallbackHandoff::afterHandoff($blocks)));
    }

    public function testWithoutAHandoffNothingChanges(): void {
        $blocks = [
            ['type' => 'thinking', 'thinking' => 'a', 'signature' => 's'],
            ['type' => 'tool_use', 'id' => 'tu', 'name' => 'x', 'input' => []],
        ];

        $this->assertSame($blocks, FallbackHandoff::afterHandoff($blocks));
    }

    public function testRebuildsTheParamFromTheSdkParsedBlock(): void {
        $message = self::message([self::HANDOFF, ['type' => 'text', 'text' => 'Hi']]);

        $this->assertNull(FallbackHandoff::toParam($message->content[1]));
        $this->assertSame(self::HANDOFF, FallbackHandoff::toParam($message->content[0]));
        $this->assertSame('claude-opus-4-8', FallbackHandoff::servedBy($message->content[0]));
    }

    /** Sticky routing serves a turn from the fallback model without a block. */
    public function testServedByFallbackReadsUsageIterations(): void {
        $sticky = self::message([['type' => 'text', 'text' => 'Hi']], [
            ['type' => 'fallback_message', 'model' => 'claude-opus-4-8', 'input_tokens' => 10, 'output_tokens' => 5],
        ]);
        $this->assertTrue(FallbackHandoff::servedByFallback($sticky));

        $handoff = self::message([self::HANDOFF, ['type' => 'text', 'text' => 'Hi']]);
        $this->assertTrue(FallbackHandoff::servedByFallback($handoff));

        $plain = self::message([['type' => 'text', 'text' => 'Hi']], [
            ['type' => 'message', 'model' => 'claude-opus-5', 'input_tokens' => 10, 'output_tokens' => 5],
        ]);
        $this->assertFalse(FallbackHandoff::servedByFallback($plain));
    }
}
