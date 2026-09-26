<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ThinkingPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThinkingPolicyTest extends TestCase {

    /**
     * model, mode, budget, effort → thinking param, effort, state, adjustments
     *
     * @return array<string, array{string, string, ?int, ?string, ?array, ?string, string, list<string>}>
     */
    public static function matrix(): array {
        $adaptive = ['type' => 'adaptive'];
        $disabled = ['type' => 'disabled'];
        return [
            // auto: send nothing, report what the model does on its own
            'Opus 5 auto'     => [ClaudeModels::OPUS_5, 'auto', null, 'xhigh', null, 'xhigh', 'adaptive', []],
            'Opus 5.5 auto'   => [ClaudeModels::OPUS_5_5, 'auto', null, 'xhigh', null, 'xhigh', 'adaptive', []],
            'Fable 5.1 auto'  => [ClaudeModels::FABLE_5_1, 'auto', null, 'xhigh', null, 'xhigh', 'adaptive', []],
            'Sonnet 5 auto'   => [ClaudeModels::SONNET_5, 'auto', null, 'medium', null, 'medium', 'adaptive', []],
            'Opus 4.8 auto'   => [ClaudeModels::OPUS_4_8, 'auto', null, 'xhigh', null, 'xhigh', 'off', []],
            // on
            'Opus 4.8 on'     => [ClaudeModels::OPUS_4_8, 'on', null, 'xhigh', $adaptive, 'xhigh', 'adaptive', []],
            'Sonnet 5 on'     => [ClaudeModels::SONNET_5, 'on', null, 'medium', $adaptive, 'medium', 'adaptive', []],
            // off
            'Opus 5 off xhigh' => [ClaudeModels::OPUS_5, 'off', null, 'xhigh', $disabled, 'high', 'off', ['effort_capped']],
            'Opus 5 off max'   => [ClaudeModels::OPUS_5, 'off', null, 'max', $disabled, 'high', 'off', ['effort_capped']],
            'Opus 5 off high'  => [ClaudeModels::OPUS_5, 'off', null, 'high', $disabled, 'high', 'off', []],
            'Opus 5 off low'   => [ClaudeModels::OPUS_5, 'off', null, 'low', $disabled, 'low', 'off', []],
            'Sonnet 5 off max' => [ClaudeModels::SONNET_5, 'off', null, 'max', $disabled, 'max', 'off', []],
            'Opus 5.5 off'     => [ClaudeModels::OPUS_5_5, 'off', null, 'xhigh', null, 'xhigh', 'adaptive', ['thinking_always_on']],
            'Fable 5.1 off'    => [ClaudeModels::FABLE_5_1, 'off', null, 'max', null, 'max', 'adaptive', ['thinking_always_on']],
            'Fable 5 off'      => [ClaudeModels::FABLE_5, 'off', null, 'max', null, 'max', 'adaptive', ['thinking_always_on']],
            'Opus 4.8 off'     => [ClaudeModels::OPUS_4_8, 'off', null, 'xhigh', null, 'xhigh', 'off', []],
            // budget
            'budget on 4.6'    => [ClaudeModels::OPUS_4_6, 'auto', 2048, 'high', ['type' => 'enabled', 'budget_tokens' => 2048], 'high', 'budget', []],
            'budget but off'   => [ClaudeModels::OPUS_4_6, 'off', 2048, 'high', null, 'high', 'off', []],
        ];
    }

    #[DataProvider('matrix')]
    public function testResolve(string $model, string $mode, ?int $budget, ?string $effort, ?array $thinking, ?string $expectedEffort, string $state, array $adjustments): void {
        $result = ThinkingPolicy::resolve($model, true, $mode, $budget, $effort);
        $this->assertSame($thinking, $result['thinking']);
        $this->assertSame($expectedEffort, $result['effort']);
        $this->assertSame($state, $result['state']);
        $this->assertSame($adjustments, $result['adjustments']);
    }

    public function testNoThinkingSupportSendsNothing(): void {
        $result = ThinkingPolicy::resolve(ClaudeModels::HAIKU_4_5, false, 'on', 2048, null);
        $this->assertNull($result['thinking']);
        $this->assertSame('off', $result['state']);
        $this->assertFalse($result['always_on']);
    }

    public function testAlwaysOnFlag(): void {
        $this->assertTrue(ThinkingPolicy::resolve(ClaudeModels::OPUS_5_5, true, 'auto', null, 'medium')['always_on']);
        $this->assertFalse(ThinkingPolicy::resolve(ClaudeModels::OPUS_5, true, 'auto', null, 'medium')['always_on']);
    }

    public function testSummaryOptIn(): void {
        // Adaptive by default: the implicit param is spelled out to carry display.
        $this->assertSame(
            ['type' => 'adaptive', 'display' => 'summarized'],
            ThinkingPolicy::resolve(ClaudeModels::SONNET_5, true, 'auto', null, 'medium', true)['thinking']
        );
        // Off stays off.
        $this->assertSame(
            ['type' => 'disabled'],
            ThinkingPolicy::resolve(ClaudeModels::SONNET_5, true, 'off', null, 'medium', true)['thinking']
        );
        // Opus 4.x auto is no thinking, so nothing to summarise.
        $this->assertNull(ThinkingPolicy::resolve(ClaudeModels::OPUS_4_8, true, 'auto', null, 'high', true)['thinking']);
        // Opus 4.6 summarises by default; no display field.
        $this->assertSame(
            ['type' => 'adaptive'],
            ThinkingPolicy::resolve(ClaudeModels::OPUS_4_6, true, 'on', null, 'high', true)['thinking']
        );
    }

    /** @return array<string, array{mixed, string}> */
    public static function modes(): array {
        return [
            'on'          => ['on', 'on'],
            'off'         => ['off', 'off'],
            'auto'        => ['auto', 'auto'],
            'legacy true' => ['true', 'on'],
            'legacy 1'    => ['1', 'on'],
            'bool true'   => [true, 'on'],
            'legacy false'=> ['false', 'auto'],
            'legacy 0'    => ['0', 'auto'],
            'blank'       => ['', 'auto'],
            'garbage'     => ['maybe', 'auto'],
        ];
    }

    #[DataProvider('modes')]
    public function testNormalizeMode(mixed $raw, string $expected): void {
        $this->assertSame($expected, ThinkingPolicy::normalizeMode($raw));
    }
}
