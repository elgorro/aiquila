<?php

namespace OCA\AIquila\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
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
     * Send a single-turn prompt to Claude
     *
     * @param string $prompt  The user's question or instruction
     * @param string $context Optional context to provide alongside the prompt
     *
     * 200: Claude response with model info and token usage
     * 400: No prompt was provided
     * 413: Prompt or context exceeds the 5 MB content limit
     * 429: Rate limit exceeded (10 requests per minute)
     *
     * @return JSONResponse<Http::STATUS_OK, array{response: string, model: string, usage: array{input_tokens: int, output_tokens: int}}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_TOO_MANY_REQUESTS, array{error: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function ask(string $prompt = '', string $context = ''): JSONResponse {
        if (!$this->checkRateLimit()) {
            return new JSONResponse([
                'error' => 'Rate limit exceeded. Maximum ' . self::RATE_LIMIT_REQUESTS . ' requests per minute.'
            ], 429);
        }

        if (!$prompt) {
            return new JSONResponse(['error' => 'No prompt provided'], 400);
        }

        if (!$this->validateContentLength($prompt . $context)) {
            return new JSONResponse([
                'error' => 'Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB'
            ], 413);
        }

        $result = $this->claudeService->ask($prompt, $context, $this->userId);
        return new JSONResponse($result);
    }

    /**
     * Send a multi-turn conversation to Claude
     *
     * @param list<array{role: string, content: string}> $messages Conversation messages
     * @param string|null $system Optional system prompt
     * @param array<string, mixed> $options Optional model parameters (temperature, top_p, top_k, stop_sequences)
     *
     * 200: Claude response with model info and token usage
     * 400: Messages array is missing or empty
     * 413: Combined message content exceeds the 5 MB content limit
     * 429: Rate limit exceeded (10 requests per minute)
     *
     * @return JSONResponse<Http::STATUS_OK, array{response: string, model: string, usage: array{input_tokens: int, output_tokens: int}}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_TOO_MANY_REQUESTS, array{error: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function chat(array $messages = [], ?string $system = null, array $options = []): JSONResponse {
        if (!$this->checkRateLimit()) {
            return new JSONResponse([
                'error' => 'Rate limit exceeded. Maximum ' . self::RATE_LIMIT_REQUESTS . ' requests per minute.'
            ], 429);
        }

        if (empty($messages)) {
            return new JSONResponse(['error' => 'No messages provided'], 400);
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

        $result = $this->claudeService->chat($messages, $system, $this->userId, $options);
        return new JSONResponse($result);
    }

    /**
     * Summarize text content with Claude
     *
     * @param string $content The text content to summarize
     *
     * 200: Claude summary with model info and token usage
     * 400: No content was provided
     * 413: Content exceeds the 5 MB content limit
     * 429: Rate limit exceeded (10 requests per minute)
     *
     * @return JSONResponse<Http::STATUS_OK, array{response: string, model: string, usage: array{input_tokens: int, output_tokens: int}}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_TOO_MANY_REQUESTS, array{error: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function summarize(string $content = ''): JSONResponse {
        if (!$this->checkRateLimit()) {
            return new JSONResponse([
                'error' => 'Rate limit exceeded. Maximum ' . self::RATE_LIMIT_REQUESTS . ' requests per minute.'
            ], 429);
        }

        if (!$content) {
            return new JSONResponse(['error' => 'No content provided'], 400);
        }

        if (!$this->validateContentLength($content)) {
            return new JSONResponse([
                'error' => 'Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB'
            ], 413);
        }

        $result = $this->claudeService->summarize($content, $this->userId);
        return new JSONResponse($result);
    }

    /**
     * Analyze a Nextcloud file with Claude (vision for images, document for PDFs, text for others)
     *
     * @param string $filePath Path to the file within the user's Nextcloud storage
     * @param string $prompt   Instruction or question about the file
     *
     * 200: Claude analysis with model info and token usage
     * 400: No filePath provided or prompt is invalid
     * 404: File not found at the given path
     * 413: Prompt exceeds the 5 MB content limit
     * 429: Rate limit exceeded (10 requests per minute)
     *
     * @return JSONResponse<Http::STATUS_OK, array{response: string, model: string, usage: array{input_tokens: int, output_tokens: int}}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_TOO_MANY_REQUESTS, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function analyzeFile(string $filePath = '', string $prompt = 'Analyze and describe this file.'): JSONResponse {
        if (!$this->checkRateLimit()) {
            return new JSONResponse([
                'error' => 'Rate limit exceeded. Maximum ' . self::RATE_LIMIT_REQUESTS . ' requests per minute.'
            ], 429);
        }

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
