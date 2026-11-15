<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\DeepSeekModels;
use PHPUnit\Framework\TestCase;

class DeepSeekModelsTest extends TestCase {
    public function testDefaultModelIsFlash(): void {
        $this->assertSame('deepseek-flash', DeepSeekModels::DEFAULT_MODEL);
    }

    public function testGetAllModelsListsTheDocumentedLineUp(): void {
        $this->assertSame(['deepseek-flash', 'deepseek-v4-pro'], DeepSeekModels::getAllModels());
    }

    public function testDiscontinuedNamesResolveToFlash(): void {
        $this->assertSame('deepseek-flash', DeepSeekModels::resolveModel('deepseek-chat'));
        $this->assertSame('deepseek-flash', DeepSeekModels::resolveModel('deepseek-reasoner'));
        $this->assertSame('deepseek-v4-pro', DeepSeekModels::resolveModel('deepseek-v4-pro'));
    }

    public function testMaxTokenCeiling(): void {
        $this->assertSame(393216, DeepSeekModels::getMaxTokenCeiling('deepseek-flash'));
        $this->assertSame(393216, DeepSeekModels::getMaxTokenCeiling('deepseek-chat'));
        $this->assertSame(DeepSeekModels::DEFAULT_MAX_TOKENS, DeepSeekModels::getMaxTokenCeiling('bogus'));
    }

    public function testVisionIsFlashOnly(): void {
        $this->assertTrue(DeepSeekModels::supportsVision('deepseek-flash'));
        $this->assertFalse(DeepSeekModels::supportsVision('deepseek-v4-pro'));
        $this->assertFalse(DeepSeekModels::supportsVision('bogus'));
    }

    public function testThinkingModelsGetNoSamplingParams(): void {
        $this->assertTrue(DeepSeekModels::isReasoner('deepseek-flash'));
        $this->assertTrue(DeepSeekModels::isReasoner('deepseek-reasoner'));
        $this->assertFalse(DeepSeekModels::isReasoner('some-other-model'));
    }
}
