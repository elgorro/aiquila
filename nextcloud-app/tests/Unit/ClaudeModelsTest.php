<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\ClaudeModels;
use PHPUnit\Framework\TestCase;

class ClaudeModelsTest extends TestCase {

    public function testDefaultModelIsSonnet5(): void {
        $this->assertEquals(ClaudeModels::SONNET_5, ClaudeModels::DEFAULT_MODEL);
    }

    public function testGetAllModelsReturnsCurrentModelsOnly(): void {
        $models = ClaudeModels::getAllModels();
        $this->assertCount(12, $models);
        $this->assertContains(ClaudeModels::FABLE_5_1,  $models);
        $this->assertContains(ClaudeModels::FABLE_5,    $models);
        $this->assertContains(ClaudeModels::OPUS_5_5,   $models);
        $this->assertContains(ClaudeModels::OPUS_5,     $models);
        $this->assertContains(ClaudeModels::SONNET_5,   $models);
        $this->assertContains(ClaudeModels::OPUS_4_8,   $models);
        $this->assertContains(ClaudeModels::OPUS_4_7,   $models);
        $this->assertContains(ClaudeModels::OPUS_4_6,   $models);
        $this->assertContains(ClaudeModels::SONNET_4_6, $models);
        $this->assertContains(ClaudeModels::SONNET_4_5, $models);
        $this->assertContains(ClaudeModels::HAIKU_4_5,  $models);
        $this->assertContains(ClaudeModels::OPUS_4_5,   $models);
        // Sonnet 4 and Opus 4 are deprecated upstream (SDK 0.15.0 dropped
        // them from the typed Model enum); the constants remain for
        // backward compat but we no longer advertise them in the UI.
        $this->assertNotContains(ClaudeModels::SONNET_4, $models);
        $this->assertNotContains(ClaudeModels::OPUS_4,   $models);
        // Most capable first
        $this->assertSame(ClaudeModels::FABLE_5_1, $models[0]);
    }

    public function testFable51AndOpus55Registry(): void {
        foreach ([ClaudeModels::FABLE_5_1, ClaudeModels::OPUS_5_5] as $model) {
            $this->assertSame(128000, ClaudeModels::getMaxTokenCeiling($model));
            $this->assertSame(1000000, ClaudeModels::getContextWindow($model));
            $this->assertTrue(ClaudeModels::supportsThinking($model));
            $this->assertTrue(ClaudeModels::supportsEffort($model));
            $this->assertSame(ClaudeModels::ALL_EFFORTS, ClaudeModels::getAllowedEfforts($model));
            // Set explicitly: Opus 5.5's API default is `medium`.
            $this->assertSame('xhigh', ClaudeModels::getEffortLevel($model));
            $this->assertFalse(ClaudeModels::supportsExtendedOutput($model));
        }
        $this->assertTrue(ClaudeModels::supportsFastMode(ClaudeModels::OPUS_5_5));
        $this->assertFalse(ClaudeModels::supportsFastMode(ClaudeModels::FABLE_5_1));
    }

    /**
     * @return array<string, array{string, string, string, ?string}>
     */
    public static function thinkingPolicyProvider(): array {
        return [
            'Fable 5.1 always thinks' => [ClaudeModels::FABLE_5_1, 'adaptive', 'never', null],
            'Fable 5 always thinks'   => [ClaudeModels::FABLE_5, 'adaptive', 'never', null],
            'Opus 5.5 always thinks'  => [ClaudeModels::OPUS_5_5, 'adaptive', 'never', null],
            'Opus 5 disables up to high' => [ClaudeModels::OPUS_5, 'adaptive', 'disabled', 'high'],
            'Sonnet 5 disables'       => [ClaudeModels::SONNET_5, 'adaptive', 'disabled', null],
            'Opus 4.8 omit is off'    => [ClaudeModels::OPUS_4_8, 'off', 'omit', null],
            'Sonnet 4.6 omit is off'  => [ClaudeModels::SONNET_4_6, 'off', 'omit', null],
            'Haiku 4.5 no policy'     => [ClaudeModels::HAIKU_4_5, 'off', 'omit', null],
            'unknown model'           => ['claude-unknown-model', 'off', 'omit', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('thinkingPolicyProvider')]
    public function testThinkingPolicy(string $model, string $default, string $off, ?string $maxEffort): void {
        $this->assertSame($default, ClaudeModels::thinkingDefault($model));
        $this->assertSame($off, ClaudeModels::thinkingOffMode($model));
        $this->assertSame($off !== 'never', ClaudeModels::canDisableThinking($model));
        $this->assertSame($maxEffort, ClaudeModels::maxEffortWithThinkingDisabled($model));
    }

    public function testEveryThinkingModelHasAPolicy(): void {
        foreach (ClaudeModels::getAllModels() as $model) {
            if (!ClaudeModels::supportsThinking($model)) {
                continue;
            }
            $maxEffort = ClaudeModels::maxEffortWithThinkingDisabled($model);
            if ($maxEffort !== null) {
                $this->assertTrue(ClaudeModels::isAllowedEffort($model, $maxEffort), $model);
            }
            // A thinking model that falls through to the "unknown" default
            // would be treated as omit-is-off, which is wrong for any 5-series.
            $this->assertTrue(
                ClaudeModels::thinkingDefault($model) === 'adaptive'
                || in_array($model, [ClaudeModels::OPUS_4_8, ClaudeModels::OPUS_4_7, ClaudeModels::OPUS_4_6, ClaudeModels::SONNET_4_6], true),
                $model
            );
        }
    }

    public function testGetMaxTokenCeilingFor5Series(): void {
        $this->assertEquals(128000, ClaudeModels::getMaxTokenCeiling(ClaudeModels::OPUS_5));
        $this->assertEquals(128000, ClaudeModels::getMaxTokenCeiling(ClaudeModels::SONNET_5));
    }

    public function testGetMaxTokenCeilingForOpus47(): void {
        $this->assertEquals(128000, ClaudeModels::getMaxTokenCeiling(ClaudeModels::OPUS_4_7));
    }

    public function testGetMaxTokenCeilingForOpus46(): void {
        $this->assertEquals(128000, ClaudeModels::getMaxTokenCeiling(ClaudeModels::OPUS_4_6));
    }

    public function testGetMaxTokenCeilingForSonnet46(): void {
        $this->assertEquals(64000, ClaudeModels::getMaxTokenCeiling(ClaudeModels::SONNET_4_6));
    }

    public function testGetMaxTokenCeilingForUnknownModelReturnsDefault(): void {
        $this->assertEquals(
            ClaudeModels::DEFAULT_MAX_TOKENS,
            ClaudeModels::getMaxTokenCeiling('claude-unknown-model')
        );
    }

    public function testGetContextWindowFor1MModels(): void {
        $this->assertEquals(1000000, ClaudeModels::getContextWindow(ClaudeModels::FABLE_5));
        $this->assertEquals(1000000, ClaudeModels::getContextWindow(ClaudeModels::OPUS_5));
        $this->assertEquals(1000000, ClaudeModels::getContextWindow(ClaudeModels::SONNET_5));
        $this->assertEquals(1000000, ClaudeModels::getContextWindow(ClaudeModels::OPUS_4_8));
        $this->assertEquals(1000000, ClaudeModels::getContextWindow(ClaudeModels::OPUS_4_7));
        $this->assertEquals(1000000, ClaudeModels::getContextWindow(ClaudeModels::OPUS_4_6));
        $this->assertEquals(1000000, ClaudeModels::getContextWindow(ClaudeModels::SONNET_4_6));
    }

    public function testGetContextWindowFor200KModels(): void {
        $this->assertEquals(200000, ClaudeModels::getContextWindow(ClaudeModels::HAIKU_4_5));
        $this->assertEquals(200000, ClaudeModels::getContextWindow(ClaudeModels::SONNET_4_5));
        $this->assertEquals(200000, ClaudeModels::getContextWindow(ClaudeModels::OPUS_4_5));
    }

    public function testGetContextWindowForUnknownModelReturnsDefault(): void {
        $this->assertEquals(
            ClaudeModels::DEFAULT_CONTEXT_WINDOW,
            ClaudeModels::getContextWindow('claude-unknown-model')
        );
    }

    public function testSupportsThinkingForAdaptiveModels(): void {
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::FABLE_5));
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::OPUS_5));
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::SONNET_5));
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::OPUS_4_8));
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::OPUS_4_7));
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::OPUS_4_6));
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::SONNET_4_6));
        $this->assertFalse(ClaudeModels::supportsThinking(ClaudeModels::SONNET_4_5));
        $this->assertFalse(ClaudeModels::supportsThinking(ClaudeModels::HAIKU_4_5));
    }

    public function testSupportsEffortForAdaptiveModels(): void {
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::FABLE_5));
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::OPUS_5));
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::SONNET_5));
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::OPUS_4_8));
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::OPUS_4_7));
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::OPUS_4_6));
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::SONNET_4_6));
        $this->assertFalse(ClaudeModels::supportsEffort(ClaudeModels::SONNET_4_5));
        $this->assertFalse(ClaudeModels::supportsEffort(ClaudeModels::HAIKU_4_5));
    }

    public function testGetEffortLevelForOpus47(): void {
        $this->assertEquals('xhigh', ClaudeModels::getEffortLevel(ClaudeModels::OPUS_4_7));
    }

    public function testGetEffortLevelForFable5AndOpus48(): void {
        $this->assertEquals('xhigh', ClaudeModels::getEffortLevel(ClaudeModels::FABLE_5));
        $this->assertEquals('xhigh', ClaudeModels::getEffortLevel(ClaudeModels::OPUS_4_8));
    }

    public function testGetEffortLevelFor5Series(): void {
        $this->assertEquals('xhigh', ClaudeModels::getEffortLevel(ClaudeModels::OPUS_5));
        $this->assertEquals('medium', ClaudeModels::getEffortLevel(ClaudeModels::SONNET_5));
    }

    public function testGetEffortLevelForOpus46(): void {
        $this->assertEquals('high', ClaudeModels::getEffortLevel(ClaudeModels::OPUS_4_6));
    }

    public function testGetEffortLevelForSonnet46(): void {
        $this->assertEquals('medium', ClaudeModels::getEffortLevel(ClaudeModels::SONNET_4_6));
    }

    public function testGetEffortLevelForUnknownModelReturnsMedium(): void {
        $this->assertEquals('medium', ClaudeModels::getEffortLevel('claude-unknown-model'));
    }

    public function testAllowedEffortsIncludeXhighOnlyOnOpus47Plus(): void {
        $xhighCapable = [
            ClaudeModels::FABLE_5,
            ClaudeModels::OPUS_5,
            ClaudeModels::SONNET_5,
            ClaudeModels::OPUS_4_8,
            ClaudeModels::OPUS_4_7,
        ];
        foreach ($xhighCapable as $model) {
            $this->assertEquals(['low', 'medium', 'high', 'xhigh', 'max'], ClaudeModels::getAllowedEfforts($model));
        }
        foreach ([ClaudeModels::OPUS_4_6, ClaudeModels::SONNET_4_6] as $model) {
            $this->assertEquals(['low', 'medium', 'high', 'max'], ClaudeModels::getAllowedEfforts($model));
        }
    }

    public function testAllowedEffortsEmptyOnUnsupportedModels(): void {
        $this->assertEquals([], ClaudeModels::getAllowedEfforts(ClaudeModels::HAIKU_4_5));
        $this->assertEquals([], ClaudeModels::getAllowedEfforts(ClaudeModels::SONNET_4_5));
        $this->assertEquals([], ClaudeModels::getAllowedEfforts('claude-unknown-model'));
    }

    public function testIsAllowedEffort(): void {
        $this->assertTrue(ClaudeModels::isAllowedEffort(ClaudeModels::FABLE_5, 'xhigh'));
        $this->assertFalse(ClaudeModels::isAllowedEffort(ClaudeModels::SONNET_4_6, 'xhigh'));
        $this->assertTrue(ClaudeModels::isAllowedEffort(ClaudeModels::SONNET_4_6, 'max'));
        $this->assertFalse(ClaudeModels::isAllowedEffort(ClaudeModels::HAIKU_4_5, 'medium'));
        $this->assertFalse(ClaudeModels::isAllowedEffort(ClaudeModels::FABLE_5, 'bogus'));
    }

    public function testDefaultEffortLevelsAreAllowedForTheirModel(): void {
        foreach (ClaudeModels::EFFORT_LEVEL as $model => $effort) {
            $this->assertTrue(
                ClaudeModels::isAllowedEffort($model, $effort),
                "Default effort '$effort' must be allowed on $model"
            );
        }
    }

    public function testEffortLevelConstantsArePublic(): void {
        $this->assertIsArray(ClaudeModels::EFFORT_LEVEL);
        $this->assertArrayHasKey(ClaudeModels::OPUS_5, ClaudeModels::EFFORT_LEVEL);
        $this->assertArrayHasKey(ClaudeModels::SONNET_5, ClaudeModels::EFFORT_LEVEL);
        $this->assertArrayHasKey(ClaudeModels::OPUS_4_7, ClaudeModels::EFFORT_LEVEL);
        $this->assertArrayHasKey(ClaudeModels::OPUS_4_6, ClaudeModels::EFFORT_LEVEL);
        $this->assertArrayHasKey(ClaudeModels::SONNET_4_6, ClaudeModels::EFFORT_LEVEL);
    }

    public function testSupportsExtendedOutput(): void {
        $this->assertTrue(ClaudeModels::supportsExtendedOutput(ClaudeModels::OPUS_5));
        $this->assertTrue(ClaudeModels::supportsExtendedOutput(ClaudeModels::OPUS_4_8));
        $this->assertTrue(ClaudeModels::supportsExtendedOutput(ClaudeModels::SONNET_5));
        $this->assertTrue(ClaudeModels::supportsExtendedOutput(ClaudeModels::SONNET_4_6));
        // Deliberately excluded — the beta is not documented for these.
        $this->assertFalse(ClaudeModels::supportsExtendedOutput(ClaudeModels::FABLE_5));
        $this->assertFalse(ClaudeModels::supportsExtendedOutput(ClaudeModels::HAIKU_4_5));
        $this->assertFalse(ClaudeModels::supportsExtendedOutput('claude-unknown-model'));
    }

    public function testGetExtendedMaxTokenCeiling(): void {
        $this->assertSame(
            ClaudeModels::EXTENDED_MAX_TOKENS,
            ClaudeModels::getExtendedMaxTokenCeiling(ClaudeModels::OPUS_5)
        );
        // Unsupported models fall back to the ordinary ceiling, so callers
        // can use the extended accessor unconditionally.
        $this->assertSame(
            ClaudeModels::getMaxTokenCeiling(ClaudeModels::FABLE_5),
            ClaudeModels::getExtendedMaxTokenCeiling(ClaudeModels::FABLE_5)
        );
        $this->assertSame(
            ClaudeModels::getMaxTokenCeiling(ClaudeModels::HAIKU_4_5),
            ClaudeModels::getExtendedMaxTokenCeiling(ClaudeModels::HAIKU_4_5)
        );
    }

    public function testCanonicalIdMapsCloudSpellingsToFirstPartyIds(): void {
        $cases = [
            'anthropic.claude-opus-5-5' => ClaudeModels::OPUS_5_5,
            'global.anthropic.claude-sonnet-5' => ClaudeModels::SONNET_5,
            'us-gov.anthropic.claude-opus-4-6-v1' => ClaudeModels::OPUS_4_6,
            'anthropic.claude-haiku-4-5-20251001-v1:0' => ClaudeModels::HAIKU_4_5,
            'arn:aws:bedrock:us-east-1:123456789012:inference-profile/eu.anthropic.claude-sonnet-5' => ClaudeModels::SONNET_5,
            'claude-haiku-4-5@20251001' => ClaudeModels::HAIKU_4_5,
            'claude-haiku-4-5' => ClaudeModels::HAIKU_4_5,
            ClaudeModels::OPUS_4_6 => ClaudeModels::OPUS_4_6,
            'my-deployment' => 'my-deployment',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, ClaudeModels::canonicalId($in), $in);
        }
    }

    public function testLookupsUnderstandCloudIds(): void {
        $this->assertSame(ClaudeModels::getMaxTokenCeiling(ClaudeModels::SONNET_4_6), ClaudeModels::getMaxTokenCeiling('eu.anthropic.claude-sonnet-4-6'));
        $this->assertFalse(ClaudeModels::canDisableThinking('anthropic.claude-opus-5-5'));
        $this->assertSame(ClaudeModels::getAllowedEfforts(ClaudeModels::OPUS_4_6), ClaudeModels::getAllowedEfforts('global.anthropic.claude-opus-4-6-v1'));
    }
}
