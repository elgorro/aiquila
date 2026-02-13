<?php

namespace OCA\AIquila\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ICache;
use OCP\ICacheFactory;
use OCA\AIquila\Service\ClaudeSDKService;

class ChatController extends Controller {
    private ClaudeSDKService $claudeService;
    private ?string $userId;
    private ICache $cache;

    // Constants for validation and rate limiting
    private const MAX_CONTENT_LENGTH = 5242880; // 5MB (5 * 1024 * 1024)
    private const RATE_LIMIT_REQUESTS = 10;
    private const RATE_LIMIT_WINDOW = 60; // seconds

    public function __construct(
        string $appName,
        IRequest $request,
        ClaudeSDKService $claudeService,
        ?string $userId,
        ICacheFactory $cacheFactory
    ) {
        parent::__construct($appName, $request);
        $this->claudeService = $claudeService;
        $this->userId = $userId;
        $this->cache = $cacheFactory->createDistributed('aiquila_ratelimit');
    }

    /**
     * Check if user has exceeded rate limit
     */
    private function checkRateLimit(): bool {
        $key = 'rate_limit_' . ($this->userId ?? 'anonymous');
        $requests = (int)$this->cache->get($key) ?? 0;

        if ($requests >= self::RATE_LIMIT_REQUESTS) {
            return false;
        }

        $this->cache->set($key, $requests + 1, self::RATE_LIMIT_WINDOW);
        return true;
    }

    /**
     * Validate content length
     */
    private function validateContentLength(string $content): bool {
        return strlen($content) <= self::MAX_CONTENT_LENGTH;
    }

    /**
     * @NoAdminRequired
     */
    public function ask(): JSONResponse {
        // Check rate limit
        if (!$this->checkRateLimit()) {
            return new JSONResponse([
                'error' => 'Rate limit exceeded. Maximum ' . self::RATE_LIMIT_REQUESTS . ' requests per minute.'
            ], 429);
        }

        $prompt = $this->request->getParam('prompt', '');
        $context = $this->request->getParam('context', '');

        if (!$prompt) {
            return new JSONResponse(['error' => 'No prompt provided'], 400);
        }

        // Validate content length
        $totalLength = strlen($prompt) + strlen($context);
        if (!$this->validateContentLength($prompt . $context)) {
            return new JSONResponse([
                'error' => 'Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB'
            ], 413);
        }

        $result = $this->claudeService->ask($prompt, $context, $this->userId);
        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
     */
    public function summarize(): JSONResponse {
        // Check rate limit
        if (!$this->checkRateLimit()) {
            return new JSONResponse([
                'error' => 'Rate limit exceeded. Maximum ' . self::RATE_LIMIT_REQUESTS . ' requests per minute.'
            ], 429);
        }

        $content = $this->request->getParam('content', '');

        if (!$content) {
            return new JSONResponse(['error' => 'No content provided'], 400);
        }

        // Validate content length
        if (!$this->validateContentLength($content)) {
            return new JSONResponse([
                'error' => 'Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB'
            ], 413);
        }

        $result = $this->claudeService->summarize($content, $this->userId);
        return new JSONResponse($result);
    }
}
