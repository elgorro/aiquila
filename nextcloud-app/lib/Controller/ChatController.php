<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

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
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\McpClientService;
use OCA\AIquila\Service\NativeMcpService;

class ChatController extends Controller {
    private ClaudeSDKService $claudeService;
    private FileService $fileService;
    private ImageOptimizer $imageOptimizer;
    private McpClientService $mcpClient;
    private NativeMcpService $nativeMcp;
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
        ImageOptimizer $imageOptimizer,
        McpClientService $mcpClient,
        NativeMcpService $nativeMcp,
        ?string $userId,
        ICacheFactory $cacheFactory
    ) {
        parent::__construct($appName, $request);
        $this->claudeService = $claudeService;
        $this->fileService = $fileService;
        $this->imageOptimizer = $imageOptimizer;
        $this->mcpClient = $mcpClient;
        $this->nativeMcp = $nativeMcp;
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
     * Send a single-turn prompt to Claude, optionally with attached files
     *
     * @param string $prompt  The user's question or instruction
     * @param string $context Optional context to provide alongside the prompt
     * @param list<string> $files Optional list of Nextcloud file paths to include as context
     *
     * 200: Claude response with model info and token usage
     * 400: No prompt was provided
     * 404: One of the attached files was not found
     * 413: Prompt or context exceeds the 5 MB content limit
     * 429: Rate limit exceeded (10 requests per minute)
     *
     * @return JSONResponse<Http::STATUS_OK, array{response: string, model: string, usage: array{input_tokens: int, output_tokens: int}}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_TOO_MANY_REQUESTS, array{error: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function ask(string $prompt = '', string $context = '', array $files = []): JSONResponse {
        if (!$this->checkRateLimit()) {
            return new JSONResponse([
                'error' => 'Rate limit exceeded. Maximum ' . self::RATE_LIMIT_REQUESTS . ' requests per minute.'
            ], 429);
        }

        if (!$prompt) {
            return new JSONResponse(['error' => 'No prompt provided'], 400);
        }

        // When files are attached, load their content and delegate appropriately
        if (!empty($files)) {
            return $this->askWithFiles($prompt, $files);
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
     * Handle ask() with attached files — reads content and delegates to the appropriate Claude method
     */
    private function askWithFiles(string $prompt, array $files): JSONResponse {
        $fileDataList = [];
        foreach ($files as $path) {
            try {
                $fileDataList[] = $this->fileService->getContent($path, $this->userId);
            } catch (\OCP\Files\NotFoundException $e) {
                return new JSONResponse(['error' => 'File not found: ' . $path], 404);
            } catch (\InvalidArgumentException $e) {
                return new JSONResponse(['error' => $e->getMessage()], 400);
            } catch (\RuntimeException $e) {
                return new JSONResponse(['error' => $e->getMessage()], 413);
            } catch (\Exception $e) {
                return new JSONResponse(['error' => 'Could not read file: ' . $e->getMessage()], 500);
            }
        }

        // Partition files by type
        $images = [];
        $documents = [];
        $textParts = [];

        foreach ($fileDataList as $f) {
            if (str_starts_with($f['mimeType'], 'image/') && $this->imageOptimizer->isSupported($f['mimeType'])) {
                $optimized = $this->imageOptimizer->optimize(
                    base64_decode($f['content']),
                    $f['mimeType']
                );
                $images[] = ['base64' => $optimized['data'], 'mimeType' => $optimized['mimeType']];
            } elseif ($f['mimeType'] === 'application/pdf') {
                $documents[] = $f;
            } else {
                $textParts[] = "--- File: {$f['name']} ({$f['mimeType']}, {$f['size']} bytes) ---\n{$f['content']}";
            }
        }

        if (count($images) > ImageOptimizer::MAX_IMAGES) {
            return new JSONResponse([
                'error' => 'Too many images. Maximum ' . ImageOptimizer::MAX_IMAGES . ' images per request.'
            ], 400);
        }

        // Images only (no other file types)
        if (!empty($images) && empty($documents) && empty($textParts)) {
            if (count($images) === 1) {
                $result = $this->claudeService->askWithImage(
                    $prompt,
                    $images[0]['base64'],
                    $images[0]['mimeType'],
                    $this->userId,
                );
            } else {
                $result = $this->claudeService->askWithImages($prompt, $images, $this->userId);
            }
            return new JSONResponse($result);
        }

        // Single PDF only
        if (empty($images) && count($documents) === 1 && empty($textParts)) {
            $f = $documents[0];
            $rawBytes = base64_decode($f['content']);
            $result = $this->claudeService->askWithDocument(
                $prompt,
                $rawBytes,
                'application/pdf',
                $f['name'],
                $this->userId,
            );
            return new JSONResponse($result);
        }

        // Mixed content: build structured content blocks for a single user message
        if (!empty($images) || !empty($documents)) {
            $content = [];

            // Images first (Claude best practice: images before text)
            foreach ($images as $img) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $img['mimeType'],
                        'data' => $img['base64'],
                    ],
                ];
            }

            // PDFs as document blocks
            foreach ($documents as $doc) {
                $content[] = [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $doc['content'],
                    ],
                    'title' => $doc['name'],
                ];
            }

            // Text files as context in the text block
            $promptWithContext = $prompt;
            if (!empty($textParts)) {
                $promptWithContext = implode("\n\n", $textParts) . "\n\n" . $prompt;
            }

            $content[] = ['type' => 'text', 'text' => $promptWithContext];

            $messages = [['role' => 'user', 'content' => $content]];
            $result = $this->claudeService->chat($messages, null, $this->userId);
            return new JSONResponse($result);
        }

        // Text-only fallback (no images, no PDFs)
        $context = implode("\n\n", $textParts);

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

        // Native MCP connector path: hand the conversation to Anthropic and
        // let it call MCP servers directly. Only takes effect when the flag
        // is on AND we can produce at least one HTTPS-public-reachable server
        // descriptor. Otherwise fall through to the local agentic loop below.
        if ($this->nativeMcp->isEnabledForUser($this->userId)) {
            $mcpServers = $this->nativeMcp->buildServerDefinitions();
            if (!empty($mcpServers)) {
                $result = $this->claudeService->chatWithNativeMcpCollect(
                    $messages,
                    $mcpServers,
                    $system,
                    $this->userId,
                    $options,
                );
                return new JSONResponse($result);
            }
        }

        // Check for enabled MCP servers and use agentic tool loop if available
        try {
            $allTools = $this->mcpClient->getAllTools();
        } catch (\Throwable $e) {
            $allTools = ['tools' => [], 'mapping' => []];
        }

        if (!empty($allTools['tools'])) {
            $mapping = $allTools['mapping'];
            $mcpClient = $this->mcpClient;
            $result = $this->claudeService->chatWithTools(
                $messages,
                $allTools['tools'],
                function (string $name, array $input) use ($mcpClient, $mapping): array {
                    return $mcpClient->executeTool($name, $input, $mapping);
                },
                $system,
                $this->userId,
                $options,
            );
        } else {
            $result = $this->claudeService->chat($messages, $system, $this->userId, $options);
        }

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

        if (str_starts_with($mimeType, 'image/') && $this->imageOptimizer->isSupported($mimeType)) {
            $optimized = $this->imageOptimizer->optimize(
                base64_decode($fileData['content']),
                $mimeType
            );
            $result = $this->claudeService->askWithImage(
                $prompt,
                $optimized['data'],
                $optimized['mimeType'],
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
