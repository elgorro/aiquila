<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\ChatController;
use OCA\AIquila\Service\ClaudeSDKService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class ChatControllerTest extends TestCase {
    private $cache;
    private $cacheFactory;
    private $claude;
    private $request;
    private ChatController $ctrl;

    protected function setUp(): void {
        $this->cache        = $this->createMock(ICache::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);
        $this->claude  = $this->createMock(ClaudeSDKService::class);
        $this->request = $this->createMock(IRequest::class);
        $this->ctrl    = new ChatController(
            'aiquila',
            $this->request,
            $this->claude,
            'testuser',
            $this->cacheFactory
        );
    }

    // ── ask() tests ────────────────────────────────────────────────────────

    public function testAskReturnsErrorWhenNoPrompt(): void {
        // Not at rate limit
        $this->cache->method('get')->willReturn(null);

        $this->request->method('getParam')
            ->willReturnMap([
                ['prompt',  '', ''],
                ['context', '', ''],
            ]);

        $response = $this->ctrl->ask();
        $this->assertEquals(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    public function testAskReturnsErrorWhenContentTooLarge(): void {
        $this->cache->method('get')->willReturn(null);

        $bigPrompt = str_repeat('x', 5 * 1024 * 1024 + 1); // > 5 MB
        $this->request->method('getParam')
            ->willReturnMap([
                ['prompt',  '', $bigPrompt],
                ['context', '', ''],
            ]);

        $response = $this->ctrl->ask();
        $this->assertEquals(413, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    public function testAskReturnsRateLimitErrorWhenLimitExceeded(): void {
        // RATE_LIMIT_REQUESTS = 10
        $this->cache->method('get')->willReturn(10);

        $response = $this->ctrl->ask();
        $this->assertEquals(429, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    public function testAskDelegatesToClaudeService(): void {
        $this->cache->method('get')->willReturn(null);

        $this->request->method('getParam')
            ->willReturnMap([
                ['prompt',  '', 'Hello Claude'],
                ['context', '', 'some context'],
            ]);

        $this->claude->expects($this->once())
            ->method('ask')
            ->with('Hello Claude', 'some context', 'testuser')
            ->willReturn(['response' => 'Hi there!']);

        $response = $this->ctrl->ask();
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['response' => 'Hi there!'], $response->getData());
    }

    // ── summarize() tests ─────────────────────────────────────────────────

    public function testSummarizeReturnsErrorWhenNoContent(): void {
        $this->cache->method('get')->willReturn(null);

        $this->request->method('getParam')
            ->willReturnMap([
                ['content', '', ''],
            ]);

        $response = $this->ctrl->summarize();
        $this->assertEquals(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    public function testSummarizeReturnsErrorWhenContentTooLarge(): void {
        $this->cache->method('get')->willReturn(null);

        $bigContent = str_repeat('y', 5 * 1024 * 1024 + 1);
        $this->request->method('getParam')
            ->willReturnMap([
                ['content', '', $bigContent],
            ]);

        $response = $this->ctrl->summarize();
        $this->assertEquals(413, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    public function testSummarizeDelegatesToClaudeService(): void {
        $this->cache->method('get')->willReturn(null);

        $this->request->method('getParam')
            ->willReturnMap([
                ['content', '', 'Long text to summarize.'],
            ]);

        $this->claude->expects($this->once())
            ->method('summarize')
            ->with('Long text to summarize.', 'testuser')
            ->willReturn(['response' => 'Summary here.']);

        $response = $this->ctrl->summarize();
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['response' => 'Summary here.'], $response->getData());
    }
}
