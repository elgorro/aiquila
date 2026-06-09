<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\ClaudeModels;
use PHPUnit\Framework\TestCase;

class ClaudeModelsTest extends TestCase {

    public function testDefaultModelIsSonnet46(): void {
        $this->assertEquals(ClaudeModels::SONNET_4_6, ClaudeModels::DEFAULT_MODEL);
    }

    public function testGetAllModelsReturnsCurrentModelsOnly(): void {
        $models = ClaudeModels::getAllModels();
        $this->assertCount(8, $models);
        $this->assertContains(ClaudeModels::FABLE_5,    $models);
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
        $this->assertSame(ClaudeModels::FABLE_5, $models[0]);
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
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::OPUS_4_8));
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::OPUS_4_7));
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::OPUS_4_6));
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::SONNET_4_6));
        $this->assertFalse(ClaudeModels::supportsThinking(ClaudeModels::SONNET_4_5));
        $this->assertFalse(ClaudeModels::supportsThinking(ClaudeModels::HAIKU_4_5));
    }

    public function testSupportsEffortForAdaptiveModels(): void {
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::FABLE_5));
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

    public function testSupportsSamplingParams(): void {
        $this->assertFalse(ClaudeModels::supportsSamplingParams(ClaudeModels::FABLE_5));
        $this->assertFalse(ClaudeModels::supportsSamplingParams(ClaudeModels::OPUS_4_8));
        $this->assertFalse(ClaudeModels::supportsSamplingParams(ClaudeModels::OPUS_4_7));
        $this->assertTrue(ClaudeModels::supportsSamplingParams(ClaudeModels::SONNET_4_6));
        $this->assertTrue(ClaudeModels::supportsSamplingParams(ClaudeModels::HAIKU_4_5));
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

    public function testEffortLevelConstantsArePublic(): void {
        $this->assertIsArray(ClaudeModels::EFFORT_LEVEL);
        $this->assertArrayHasKey(ClaudeModels::OPUS_4_7, ClaudeModels::EFFORT_LEVEL);
        $this->assertArrayHasKey(ClaudeModels::OPUS_4_6, ClaudeModels::EFFORT_LEVEL);
        $this->assertArrayHasKey(ClaudeModels::SONNET_4_6, ClaudeModels::EFFORT_LEVEL);
    }
}
