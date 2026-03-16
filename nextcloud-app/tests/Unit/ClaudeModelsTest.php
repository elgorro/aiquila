<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\ClaudeModels;
use PHPUnit\Framework\TestCase;

class ClaudeModelsTest extends TestCase {

    public function testDefaultModelIsSonnet46(): void {
        $this->assertEquals(ClaudeModels::SONNET_4_6, ClaudeModels::DEFAULT_MODEL);
    }

    public function testGetAllModelsReturnsAllSevenModels(): void {
        $models = ClaudeModels::getAllModels();
        $this->assertCount(7, $models);
        $this->assertContains(ClaudeModels::OPUS_4_6,   $models);
        $this->assertContains(ClaudeModels::SONNET_4_6, $models);
        $this->assertContains(ClaudeModels::SONNET_4_5, $models);
        $this->assertContains(ClaudeModels::HAIKU_4_5,  $models);
        $this->assertContains(ClaudeModels::OPUS_4_5,   $models);
        $this->assertContains(ClaudeModels::SONNET_4,   $models);
        $this->assertContains(ClaudeModels::OPUS_4,     $models);
    }

    public function testGetMaxTokenCeilingForOpus46(): void {
        $this->assertEquals(128000, ClaudeModels::getMaxTokenCeiling(ClaudeModels::OPUS_4_6));
    }

    public function testGetMaxTokenCeilingForUnknownModelReturnsDefault(): void {
        $this->assertEquals(
            ClaudeModels::DEFAULT_MAX_TOKENS,
            ClaudeModels::getMaxTokenCeiling('claude-unknown-model')
        );
    }

    public function testSupportsThinkingForAdaptiveModels(): void {
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::OPUS_4_6));
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::SONNET_4_6));
        $this->assertFalse(ClaudeModels::supportsThinking(ClaudeModels::SONNET_4_5));
        $this->assertFalse(ClaudeModels::supportsThinking(ClaudeModels::HAIKU_4_5));
    }

    public function testSupportsEffortForAdaptiveModels(): void {
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::OPUS_4_6));
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::SONNET_4_6));
        $this->assertFalse(ClaudeModels::supportsEffort(ClaudeModels::SONNET_4_5));
        $this->assertFalse(ClaudeModels::supportsEffort(ClaudeModels::HAIKU_4_5));
    }

    public function testGetModelParamsForOpus46IncludesThinkingAndEffort(): void {
        $params = ClaudeModels::getModelParams(ClaudeModels::OPUS_4_6);
        $this->assertArrayHasKey('thinking', $params);
        $this->assertArrayHasKey('effort',   $params);
    }

    public function testGetMaxTokenCeilingForSonnet46(): void {
        $this->assertEquals(64000, ClaudeModels::getMaxTokenCeiling(ClaudeModels::SONNET_4_6));
    }

    public function testGetModelParamsForSonnet46IncludesThinkingAndEffort(): void {
        $params = ClaudeModels::getModelParams(ClaudeModels::SONNET_4_6);
        $this->assertArrayHasKey('thinking', $params);
        $this->assertEquals(['type' => 'adaptive'], $params['thinking']);
        $this->assertArrayHasKey('effort', $params);
        $this->assertEquals('medium', $params['effort']);
    }

    public function testGetModelParamsEffortLevels(): void {
        $opus = ClaudeModels::getModelParams(ClaudeModels::OPUS_4_6);
        $this->assertEquals('high', $opus['effort']);

        $sonnet = ClaudeModels::getModelParams(ClaudeModels::SONNET_4_6);
        $this->assertEquals('medium', $sonnet['effort']);
    }

    public function testGetModelParamsForOtherModelsIsEmpty(): void {
        $this->assertEquals([], ClaudeModels::getModelParams(ClaudeModels::SONNET_4_5));
    }
}
