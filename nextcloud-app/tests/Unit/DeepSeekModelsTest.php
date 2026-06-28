<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\DeepSeekModels;
use PHPUnit\Framework\TestCase;

class DeepSeekModelsTest extends TestCase {
    public function testDefaultModelIsChat(): void {
        $this->assertSame('deepseek-chat', DeepSeekModels::DEFAULT_MODEL);
    }

    public function testGetAllModelsContainsBothModels(): void {
        $models = DeepSeekModels::getAllModels();
        $this->assertContains('deepseek-chat', $models);
        $this->assertContains('deepseek-reasoner', $models);
    }

    public function testMaxTokenCeilingFallsBackForUnknown(): void {
        $this->assertSame(8192, DeepSeekModels::getMaxTokenCeiling('deepseek-chat'));
        $this->assertSame(DeepSeekModels::DEFAULT_MAX_TOKENS, DeepSeekModels::getMaxTokenCeiling('bogus'));
    }

    public function testIsReasoner(): void {
        $this->assertTrue(DeepSeekModels::isReasoner('deepseek-reasoner'));
        $this->assertFalse(DeepSeekModels::isReasoner('deepseek-chat'));
    }
}
