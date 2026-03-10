<?php

namespace OCA\AIquila\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ICache;
use OCP\ICacheFactory;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\FileService;

class ChatController extends Controller {
    private ClaudeSDKService $claudeService;
    private FileService $fileService;
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
        FileService $fileService,
        ?string $userId,
        ICacheFactory $cacheFactory
    ) {
        parent::__construct($appName, $request);
        $this->claudeService = $claudeService;
        $this->fileService = $fileService;
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
     * POST /api/chat
     * Body: {messages: [...], system?: string, options?: {temperature?, top_p?, top_k?, stop_sequences?}}
     */
    public function chat(): JSONResponse {
        if (!$this->checkRateLimit()) {
            return new JSONResponse([
                'error' => 'Rate limit exceeded. Maximum ' . self::RATE_LIMIT_REQUESTS . ' requests per minute.'
            ], 429);
        }

        $messagesRaw = $this->request->getParam('messages');
        if (empty($messagesRaw)) {
            return new JSONResponse(['error' => 'No messages provided'], 400);
        }

        // Accept JSON-encoded string or already-decoded array
        if (is_string($messagesRaw)) {
            $messages = json_decode($messagesRaw, true);
            if (!is_array($messages)) {
                return new JSONResponse(['error' => 'Invalid messages format'], 400);
            }
        } else {
            $messages = $messagesRaw;
        }

        if (empty($messages)) {
            return new JSONResponse(['error' => 'Messages array is empty'], 400);
        }

        // Validate total content size
        $totalLength = 0;
        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            $totalLength += is_string($content) ? strlen($content) : strlen(json_encode($content));
        }
        if ($totalLength > self::MAX_CONTENT_LENGTH) {
            return new JSONResponse([
                'error' => 'Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB'
            ], 413);
        }

        $system  = $this->request->getParam('system', null) ?: null;
        $options = $this->request->getParam('options', []) ?: [];
        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }

        $result = $this->claudeService->chat($messages, $system, $this->userId, $options);
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

    /**
     * @NoAdminRequired
     * POST /api/analyze-file
     * Body: {filePath: string, prompt?: string}
     *
     * Analyze a file stored in Nextcloud with Claude.
     * Images are sent as vision content; PDFs and text as document context.
     */
    public function analyzeFile(): JSONResponse {
        if (!$this->checkRateLimit()) {
            return new JSONResponse([
                'error' => 'Rate limit exceeded. Maximum ' . self::RATE_LIMIT_REQUESTS . ' requests per minute.'
            ], 429);
        }

        $filePath = $this->request->getParam('filePath', '');
        $prompt = $this->request->getParam('prompt', 'Analyze and describe this file.');

        if (empty($filePath)) {
            return new JSONResponse(['error' => 'No filePath provided'], 400);
        }

        if (!$this->validateContentLength($prompt)) {
            return new JSONResponse([
                'error' => 'Prompt too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB'
            ], 413);
        }

        try {
            $fileData = $this->fileService->getContent($filePath, $this->userId);
        } catch (\OCP\Files\NotFoundException $e) {
            return new JSONResponse(['error' => 'File not found: ' . $filePath], 404);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 413);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Could not read file: ' . $e->getMessage()], 500);
        }

        $mimeType = $fileData['mimeType'];

        if (str_starts_with($mimeType, 'image/')) {
            $result = $this->claudeService->askWithImage(
                $prompt,
                $fileData['content'], // already base64
                $mimeType,
                $this->userId
            );
        } elseif ($mimeType === 'application/pdf') {
            $rawBytes = base64_decode($fileData['content']);
            $result = $this->claudeService->askWithDocument(
                $prompt,
                $rawBytes,
                'application/pdf',
                $fileData['name'] ?? basename($filePath),
                $this->userId
            );
        } else {
            $context = "File: {$fileData['name']} ({$mimeType}, {$fileData['size']} bytes)\n\n"
                     . $fileData['content'];
            $result = $this->claudeService->ask($prompt, $context, $this->userId);
        }

        return new JSONResponse($result);
    }
}
