<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\ChatController;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\FileService;
use OCA\AIquila\Service\FilesService;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\McpClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class ChatControllerTest extends TestCase {
    private $cache;
    private $cacheFactory;
    private $claude;
    private $fileService;
    private $filesService;
    private $imageOptimizer;
    private $mcpClient;
    private $request;
    private $logger;
    private ChatController $ctrl;

    protected function setUp(): void {
        $this->cache        = $this->createMock(ICache::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);
        $this->claude      = $this->createMock(ClaudeSDKService::class);
        $this->factory     = $this->createMock(LLMProviderFactory::class);
        // Access control is exercised in LLMProviderFactoryTest; here every
        // provider is permitted so the endpoints reach their own logic.
        $this->factory->method('hasPermittedProvider')->willReturn(true);
        $this->factory->method('getProvider')->willReturn($this->claude);
        $this->fileService = $this->createMock(FileService::class);
        $this->filesService = $this->createMock(FilesService::class);
        // By default no Files API dedup — tests get null and the existing base64 path
        $this->filesService->method('getOrUploadFileId')->willReturn(null);
        $this->imageOptimizer = $this->createMock(ImageOptimizer::class);
        // By default, optimizer passes through images unchanged
        $this->imageOptimizer->method('isSupported')->willReturn(true);
        $this->imageOptimizer->method('optimize')->willReturnCallback(
            fn(string $raw, string $mime) => ['data' => base64_encode($raw), 'mimeType' => $mime, 'resized' => false]
        );
        $this->mcpClient   = $this->createMock(McpClientService::class);
        $this->nativeMcp   = $this->createMock(\OCA\AIquila\Service\NativeMcpService::class);
        $this->nativeMcp->method('isEnabledForUser')->willReturn(false);
        $this->request     = $this->createMock(IRequest::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->ctrl        = new ChatController(
            'aiquila',
            $this->request,
            $this->factory,
            $this->fileService,
            $this->filesService,
            $this->imageOptimizer,
            $this->mcpClient,
            $this->nativeMcp,
            'testuser',
            $this->logger
        );
    }

    // ── ask() tests ────────────────────────────────────────────────────────

    public function testAskReturnsErrorWhenNoPrompt(): void {
        $this->cache->method('get')->willReturn(null);

        $response = $this->ctrl->ask('');
        $this->assertEquals(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    public function testAskReturnsErrorWhenContentTooLarge(): void {
        $this->cache->method('get')->willReturn(null);

        $bigPrompt = str_repeat('x', 5 * 1024 * 1024 + 1); // > 5 MB
        $response = $this->ctrl->ask($bigPrompt);
        $this->assertEquals(413, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    /**
     * Rate limiting moved from a hand-rolled, userId-keyed counter to Nextcloud's
     * RateLimitingMiddleware. The middleware never runs in a unit test, so assert
     * the attributes are present instead — and that both are, since UserRateLimit
     * alone leaves anonymous callers sharing a single bucket.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rateLimitedEndpoints')]
    public function testEndpointCarriesBothRateLimitAttributes(string $method): void {
        $reflection = new \ReflectionMethod(ChatController::class, $method);

        $attributes = array_map(
            static fn(\ReflectionAttribute $a): string => $a->getName(),
            $reflection->getAttributes(),
        );

        $this->assertContains(
            \OCP\AppFramework\Http\Attribute\UserRateLimit::class,
            $attributes,
            $method . ' must be rate limited per user',
        );
        $this->assertContains(
            \OCP\AppFramework\Http\Attribute\AnonRateLimit::class,
            $attributes,
            $method . ' must also be rate limited per IP',
        );
    }

    /** @return list<array{string}> */
    public static function rateLimitedEndpoints(): array {
        return [['ask'], ['chat'], ['summarize'], ['analyzeFile']];
    }

    public function testControllerNoLongerCarriesItsOwnLimiter(): void {
        // The old counter silently did nothing when no distributed cache was
        // configured, so its removal is worth pinning down.
        $this->assertFalse(
            method_exists(ChatController::class, 'checkRateLimit'),
            'the hand-rolled rate limiter should be gone',
        );
    }

    public function testAskDelegatesToClaudeService(): void {
        $this->cache->method('get')->willReturn(null);

        $this->claude->expects($this->once())
            ->method('ask')
            ->with('Hello Claude', 'some context', 'testuser')
            ->willReturn(['response' => 'Hi there!']);

        $response = $this->ctrl->ask('Hello Claude', 'some context');
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['response' => 'Hi there!'], $response->getData());
    }

    // ── ask() with files tests ──────────────────────────────────────────

    public function testAskWithFilesSendsContext(): void {
        $this->cache->method('get')->willReturn(null);

        $this->fileService->expects($this->once())
            ->method('getContent')
            ->with('/test.txt', 'testuser')
            ->willReturn([
                'name' => 'test.txt',
                'mimeType' => 'text/plain',
                'size' => 11,
                'content' => 'hello world',
            ]);

        $this->claude->expects($this->once())
            ->method('ask')
            ->with(
                'Summarize this',
                $this->stringContains('--- File: test.txt (text/plain, 11 bytes) ---'),
                'testuser',
            )
            ->willReturn(['response' => 'It says hello.']);

        $response = $this->ctrl->ask('Summarize this', '', ['/test.txt']);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['response' => 'It says hello.'], $response->getData());
    }

    public function testAskWithNonexistentFileReturns404(): void {
        $this->cache->method('get')->willReturn(null);

        $this->fileService->expects($this->once())
            ->method('getContent')
            ->with('/missing.txt', 'testuser')
            ->willThrowException(new \OCP\Files\NotFoundException('not found'));

        $response = $this->ctrl->ask('Read this', '', ['/missing.txt']);
        $this->assertEquals(404, $response->getStatus());
        $this->assertStringContainsString('missing.txt', $response->getData()['error']);
    }

    public function testAskWithImageFileDelegatesToVision(): void {
        $this->cache->method('get')->willReturn(null);

        $this->fileService->expects($this->once())
            ->method('getContent')
            ->with('/photo.png', 'testuser')
            ->willReturn([
                'name' => 'photo.png',
                'mimeType' => 'image/png',
                'size' => 1024,
                'content' => base64_encode('fakepng'),
            ]);

        $this->claude->expects($this->once())
            ->method('askWithImage')
            ->willReturn(['response' => 'I see a photo.']);

        $response = $this->ctrl->ask('Describe this', '', ['/photo.png']);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['response' => 'I see a photo.'], $response->getData());
    }

    public function testAskWithMultipleImagesDelegatesToAskWithImages(): void {
        $this->cache->method('get')->willReturn(null);

        $this->fileService->expects($this->exactly(2))
            ->method('getContent')
            ->willReturnOnConsecutiveCalls(
                [
                    'name' => 'a.jpg',
                    'mimeType' => 'image/jpeg',
                    'size' => 500,
                    'content' => base64_encode('fakejpg1'),
                ],
                [
                    'name' => 'b.jpg',
                    'mimeType' => 'image/jpeg',
                    'size' => 600,
                    'content' => base64_encode('fakejpg2'),
                ]
            );

        $this->claude->expects($this->once())
            ->method('askWithImages')
            ->willReturn(['response' => 'Two images compared.']);

        $response = $this->ctrl->ask('Compare these', '', ['/a.jpg', '/b.jpg']);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['response' => 'Two images compared.'], $response->getData());
    }

    public function testAskWithMixedFilesUsesStructuredContent(): void {
        $this->cache->method('get')->willReturn(null);

        $this->fileService->expects($this->exactly(2))
            ->method('getContent')
            ->willReturnOnConsecutiveCalls(
                [
                    'name' => 'photo.png',
                    'mimeType' => 'image/png',
                    'size' => 1024,
                    'content' => base64_encode('fakepng'),
                ],
                [
                    'name' => 'notes.txt',
                    'mimeType' => 'text/plain',
                    'size' => 11,
                    'content' => 'hello world',
                ]
            );

        // Mixed content goes through chat() with structured messages
        $this->claude->expects($this->once())
            ->method('chat')
            ->willReturn(['response' => 'Mixed content analyzed.']);

        $response = $this->ctrl->ask('Analyze all', '', ['/photo.png', '/notes.txt']);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['response' => 'Mixed content analyzed.'], $response->getData());
    }

    // ── summarize() tests ─────────────────────────────────────────────────

    public function testSummarizeReturnsErrorWhenNoContent(): void {
        $this->cache->method('get')->willReturn(null);

        $response = $this->ctrl->summarize('');
        $this->assertEquals(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    public function testSummarizeReturnsErrorWhenContentTooLarge(): void {
        $this->cache->method('get')->willReturn(null);

        $bigContent = str_repeat('y', 5 * 1024 * 1024 + 1);
        $response = $this->ctrl->summarize($bigContent);
        $this->assertEquals(413, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    public function testSummarizeDelegatesToClaudeService(): void {
        $this->cache->method('get')->willReturn(null);

        $this->claude->expects($this->once())
            ->method('summarize')
            ->with('Long text to summarize.', 'testuser')
            ->willReturn(['response' => 'Summary here.']);

        $response = $this->ctrl->summarize('Long text to summarize.');
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['response' => 'Summary here.'], $response->getData());
    }
}
