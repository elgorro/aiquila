<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\ClaudeModels;
use PHPUnit\Framework\TestCase;

class ClaudeModelsTest extends TestCase {

    public function testDefaultModelIsSonnet45(): void {
        $this->assertEquals(ClaudeModels::SONNET_4_5, ClaudeModels::DEFAULT_MODEL);
    }

    public function testGetAllModelsReturnsAllSixModels(): void {
        $models = ClaudeModels::getAllModels();
        $this->assertCount(6, $models);
        $this->assertContains(ClaudeModels::OPUS_4_6,   $models);
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

    public function testSupportsThinkingOnlyForOpus46(): void {
        $this->assertTrue(ClaudeModels::supportsThinking(ClaudeModels::OPUS_4_6));
        $this->assertFalse(ClaudeModels::supportsThinking(ClaudeModels::SONNET_4_5));
        $this->assertFalse(ClaudeModels::supportsThinking(ClaudeModels::HAIKU_4_5));
    }

    public function testSupportsEffortOnlyForOpus46(): void {
        $this->assertTrue(ClaudeModels::supportsEffort(ClaudeModels::OPUS_4_6));
        $this->assertFalse(ClaudeModels::supportsEffort(ClaudeModels::SONNET_4_5));
        $this->assertFalse(ClaudeModels::supportsEffort(ClaudeModels::HAIKU_4_5));
    }

    public function testGetModelParamsForOpus46IncludesThinkingAndEffort(): void {
        $params = ClaudeModels::getModelParams(ClaudeModels::OPUS_4_6);
        $this->assertArrayHasKey('thinking', $params);
        $this->assertArrayHasKey('effort',   $params);
    }

    public function testGetModelParamsForOtherModelsIsEmpty(): void {
        $this->assertEquals([], ClaudeModels::getModelParams(ClaudeModels::SONNET_4_5));
    }
}
