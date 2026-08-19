<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\Message as MessageEntity;
use OCA\AIquila\Db\MessageFile;
use OCA\AIquila\Db\MessageFileMapper;
use OCA\AIquila\Db\MessageMapper;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Http\SSEResponse;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\FileService;
use OCA\AIquila\Service\FilesService;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\McpClientService;
use OCA\AIquila\Service\NativeMcpService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCA\AIquila\BackgroundJob\IndexConversationJob;
use OCA\AIquila\Service\ContextChatService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ConversationController extends Controller {
    use RequiresUserIdTrait;
    use ErrorResponseTrait;

    private ConversationMapper $conversationMapper;
    private MessageMapper $messageMapper;
    private MessageFileMapper $messageFileMapper;
    private ProjectMapper $projectMapper;
    private ProjectPathMapper $projectPathMapper;
    private LLMProviderFactory $providerFactory;
    private FileService $fileService;
    private FilesService $filesService;
    private ImageOptimizer $imageOptimizer;
    private McpClientService $mcpClient;
    private NativeMcpService $nativeMcp;
    private IJobList $jobList;
    private ContextChatService $contextChat;
    private ?string $userId;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        MessageFileMapper $messageFileMapper,
        ProjectMapper $projectMapper,
        ProjectPathMapper $projectPathMapper,
        LLMProviderFactory $providerFactory,
        FileService $fileService,
        FilesService $filesService,
        ImageOptimizer $imageOptimizer,
        McpClientService $mcpClient,
        NativeMcpService $nativeMcp,
        IJobList $jobList,
        ContextChatService $contextChat,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->logger = $logger;
        $this->conversationMapper = $conversationMapper;
        $this->messageMapper = $messageMapper;
        $this->messageFileMapper = $messageFileMapper;
        $this->projectMapper = $projectMapper;
        $this->projectPathMapper = $projectPathMapper;
        $this->providerFactory = $providerFactory;
        $this->fileService = $fileService;
        $this->filesService = $filesService;
        $this->imageOptimizer = $imageOptimizer;
        $this->mcpClient = $mcpClient;
        $this->nativeMcp = $nativeMcp;
        $this->jobList = $jobList;
        $this->contextChat = $contextChat;
        $this->userId = $userId;
    }

    /**
     * Queue a best-effort Context Chat re-index for a conversation, off the
     * request path. No-op downstream when Context Chat is not installed.
     */
    private function queueContextChatIndex(int $conversationId): void {
        $this->jobList->add(IndexConversationJob::class, ['id' => $conversationId]);
    }

    /**
     * List all conversations for the current user
     *
     * 200: List of conversations
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool}>, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function index(): JSONResponse {
        $conversations = $this->conversationMapper->findAllByUser($this->requireUserId());
        return new JSONResponse(array_map(
            fn(Conversation $c) => $c->jsonSerialize(),
            $conversations
        ));
    }

    /**
     * Create a new conversation
     *
     * Provider and model are snapshotted so the conversation keeps answering
     * from where it started even after the user changes their default. Omit
     * both to follow the user's setting (`provider` stays null).
     *
     * @param string|null $provider Provider to pin (null/'' follows the user's setting)
     * @param string|null $model Model to pin (null/'' uses the provider's default)
     *
     * 200: The created conversation
     * 400: Unknown provider
     * 403: The provider is not permitted for this user
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function create(?string $provider = null, ?string $model = null): JSONResponse {
        // Validate before anything else: getProviderById() falls back to
        // Anthropic for an unknown id, which is right for a stale config value
        // but would let an arbitrary request string reach persistence unnoticed.
        if ($provider !== null && $provider !== '' && !$this->providerFactory->isKnownProviderId($provider)) {
            return $this->clientError(400, 'Unknown provider: ' . $provider);
        }
        // Pinning is a provider-selecting path like any other, so it is subject
        // to the same access rules as the settings pages.
        if ($provider !== null && $provider !== '' && !$this->providerFactory->isAllowedForUser($provider, $this->userId)) {
            return $this->forbiddenProvider($provider);
        }

        $now = time();
        $pinned = ($provider !== null && $provider !== '') ? $provider : null;
        try {
            $service = $pinned !== null
                ? $this->providerFactory->getProviderById($pinned)
                : $this->providerFactory->getProvider($this->userId);
        } catch (NoPermittedProviderException $e) {
            return $this->noProviderAvailable();
        }

        $conversation = new Conversation();
        $conversation->setUserId($this->requireUserId());
        $conversation->setProvider($pinned);
        $conversation->setModel(($model !== null && $model !== '') ? $model : $service->getModel($this->userId));
        $conversation->setCreatedAt($now);
        $conversation->setUpdatedAt($now);

        $conversation = $this->conversationMapper->insert($conversation);
        return new JSONResponse($conversation->jsonSerialize());
    }

    /**
     * Pin a provider and/or model on an existing conversation
     *
     * Passing an empty provider unpins it, so the conversation follows the
     * user's setting again. Changing the provider re-snapshots the model to
     * that provider's default unless one is given explicitly — otherwise the
     * conversation would carry a model id the new provider does not serve.
     *
     * @param int $id Conversation ID
     * @param string|null $provider Provider to pin ('' unpins, null keeps unchanged)
     * @param string|null $model Model to pin (null keeps unchanged)
     *
     * 200: Updated conversation
     * 400: Unknown provider
     * 403: The provider is not permitted for this user
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function setModel(int $id, ?string $provider = null, ?string $model = null): JSONResponse {
        if (!$this->providerFactory->hasPermittedProvider($this->userId)) {
            return $this->noProviderAvailable();
        }
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        if ($provider !== null) {
            if ($provider !== '' && !$this->providerFactory->isKnownProviderId($provider)) {
                return $this->clientError(400, 'Unknown provider: ' . $provider);
            }
            if ($provider !== '' && !$this->providerFactory->isAllowedForUser($provider, $this->userId)) {
                return $this->forbiddenProvider($provider);
            }
            $conversation->setProvider($provider === '' ? null : $provider);

            if ($model === null || $model === '') {
                $model = $this->resolveProvider($conversation)->getModel($this->userId);
            }

            // The pinned effort belongs to the old provider's vocabulary.
            if (!$this->resolveProvider($conversation)->getCapabilities()['effort']) {
                $conversation->setEffort(null);
            }
        }

        if ($model !== null && $model !== '') {
            $conversation->setModel($model);
        }

        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);

        return new JSONResponse($conversation->jsonSerialize());
    }

    /**
     * Get a conversation with its messages and files
     *
     * @param int $id Conversation ID
     *
     * 200: Conversation with messages
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool, messages: list<array{id: int, conversationId: int, role: string, content: string, inputTokens: ?int, outputTokens: ?int, cacheCreationTokens: ?int, cacheReadTokens: ?int, latencyMs: ?int, citations: ?array<string, mixed>, documents: ?array<string, mixed>, createdAt: int, files: list<array{id: int, messageId: int, filePath: string, fileName: string, mimeType: ?string, createdAt: int}>}>}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function show(int $id): JSONResponse {
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        $messages = $this->messageMapper->findByConversation($id);
        $messagesData = [];
        foreach ($messages as $msg) {
            $msgData = $msg->jsonSerialize();
            $msgData['files'] = array_map(
                fn(MessageFile $f) => $f->jsonSerialize(),
                $this->messageFileMapper->findByMessage($msg->getId())
            );
            $messagesData[] = $msgData;
        }

        $data = $conversation->jsonSerialize();
        $data['messages'] = $messagesData;
        return new JSONResponse($data);
    }

    /**
     * Update a conversation (title, project link, effort and/or thinking override)
     *
     * @param int $id Conversation ID
     * @param string $title New title
     * @param int|null $projectId Project ID to link (null to clear)
     * @param string|null $effort Effort override (low…max; empty string clears)
     * @param string|null $thinking Adaptive-thinking override ('on'/'off'; empty string clears)
     *
     * 200: Updated conversation
     * 400: Invalid effort or thinking value
     * 403: No provider is permitted for this user
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, allowed: list<string>}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function update(int $id, string $title = '', ?int $projectId = null, ?string $effort = null, ?string $thinking = null): JSONResponse {
        if (!$this->providerFactory->hasPermittedProvider($this->userId)) {
            return $this->noProviderAvailable();
        }
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        if ($title !== '') {
            $conversation->setTitle($title);
        }

        // Allow explicitly setting or clearing project association
        $requestParams = $this->request->getParams();
        if (array_key_exists('projectId', $requestParams)) {
            $conversation->setProjectId($projectId);
        }

        if ($effort !== null && $effort !== '') {
            // Effort is an Anthropic concept; validating a Hetzner or Ollama
            // conversation against Anthropic's table produced nonsense advice.
            $provider = $this->resolveProvider($conversation);
            if (!$provider->getCapabilities()['effort']) {
                return new JSONResponse([
                    'error' => $provider->getLabel() . ' does not support effort levels',
                    'errorId' => '',
                    'allowed' => [],
                ], 400);
            }

            $model = ClaudeModels::resolveModel($conversation->getModel());
            if (!ClaudeModels::isAllowedEffort($model, $effort)) {
                $allowed = ClaudeModels::getAllowedEfforts($model);
                return new JSONResponse([
                    'error' => $allowed === []
                        ? 'Model ' . $model . ' does not support effort'
                        : 'Invalid effort for ' . $model . '. Allowed: ' . implode(', ', $allowed),
                    'errorId' => '',
                    'allowed' => $allowed,
                ], 400);
            }
            $conversation->setEffort($effort);
        } elseif ($effort === '') {
            $conversation->setEffort(null);
        }

        if ($thinking !== null) {
            if (!in_array($thinking, ['on', 'off', ''], true)) {
                return $this->clientError(400, 'Thinking must be "on", "off" or empty');
            }
            $conversation->setThinking($thinking === '' ? null : $thinking === 'on');
        }

        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);

        return new JSONResponse($conversation->jsonSerialize());
    }

    /**
     * Delete a conversation and all its messages and files
     *
     * @param int $id Conversation ID
     *
     * 200: Deletion confirmed
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function destroy(int $id): JSONResponse {
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        // Delete files for each message
        $messages = $this->messageMapper->findByConversation($id);
        foreach ($messages as $msg) {
            $this->messageFileMapper->deleteByMessage($msg->getId());
        }
        $this->messageMapper->deleteByConversation($id);
        $this->conversationMapper->delete($conversation);

        // Drop the conversation from Context Chat (no-op when not installed).
        $this->contextChat->removeConversation($id);

        return new JSONResponse(['deleted' => true]);
    }

    /**
     * Send a message in a conversation and get Claude's response
     *
     * @param int $id Conversation ID
     * @param string $prompt The user's message
     * @param list<string> $files Optional file paths to attach
     *
     * 200: User message and assistant response
     * 400: No prompt provided
     * 403: No provider is permitted for this user
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{userMessage: array<string, mixed>, assistantMessage: array<string, mixed>, conversation: array<string, mixed>}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    // Each call bills an LLM request. These are the endpoints the chat UI
    // actually uses — ChatController's limits never covered them.
    #[UserRateLimit(limit: 30, period: 60)]
    #[AnonRateLimit(limit: 30, period: 60)]
    #[OpenAPI]
    public function message(int $id, string $prompt = '', array $files = []): JSONResponse {
        if (!$this->providerFactory->hasPermittedProvider($this->userId)) {
            return $this->noProviderAvailable();
        }
        if (!$prompt && empty($files)) {
            return $this->clientError(400, 'No prompt provided');
        }

        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        $now = time();

        // 1. Create and persist user message
        $userMsg = new MessageEntity();
        $userMsg->setConversationId($id);
        $userMsg->setRole('user');
        $userMsg->setContent($prompt);
        $userMsg->setCreatedAt($now);
        $userMsg = $this->messageMapper->insert($userMsg);

        // 2. Persist attached files
        $fileEntities = [];
        foreach ($files as $filePath) {
            try {
                $info = $this->fileService->getInfo($filePath, $this->requireUserId());
            } catch (\Exception $e) {
                // Store with basic info if lookup fails
                $info = ['name' => basename($filePath), 'mimeType' => 'application/octet-stream'];
            }
            $mf = new MessageFile();
            $mf->setMessageId($userMsg->getId());
            $mf->setFilePath($filePath);
            $mf->setFileName($info['name'] ?? basename($filePath));
            $mf->setMimeType($info['mimeType'] ?? null);
            $mf->setCreatedAt($now);
            $mf = $this->messageFileMapper->insert($mf);
            $fileEntities[] = $mf;
        }

        // 3. Build messages array from full conversation history
        $allMessages = $this->messageMapper->findByConversation($id);
        $claudeMessages = [];
        foreach ($allMessages as $msg) {
            $claudeMessages[] = [
                'role' => $msg->getRole(),
                'content' => $msg->getContent(),
            ];
        }

        // 4. If files are attached to THIS message, build structured content blocks
        //    so images go through Claude Vision and PDFs through document understanding
        $documentsIndex = [];
        if (!empty($files)) {
            $built = $this->buildFileContentBlocks($files);
            $contentBlocks = $built['blocks'];
            $documentsIndex = $built['documents'];
            if (!empty($contentBlocks) && $claudeMessages !== []) {
                $lastIdx = count($claudeMessages) - 1;
                $userText = $claudeMessages[$lastIdx]['content'];
                // Convert plain string content to structured array with file blocks + text
                $claudeMessages[$lastIdx]['content'] = array_merge(
                    $contentBlocks,
                    [['type' => 'text', 'text' => $userText]]
                );
            }
        }

        // 5. Load project system prompt if conversation has a project
        $systemPrompt = null;
        $projectId = $conversation->getProjectId();
        if ($projectId !== null) {
            try {
                $project = $this->projectMapper->findByIdAndUser($projectId, $this->requireUserId());
                $systemPrompt = $project->getSystemPrompt();

                // Build project context from paths
                $paths = $this->projectPathMapper->findByProject($project->getId());
                if (!empty($paths)) {
                    $contextLines = ['Project: ' . $project->getTitle()];
                    foreach ($paths as $projectPath) {
                        $contextLines[] = '- [' . $projectPath->getPathType() . '] ' . $projectPath->getPath();
                    }
                    $pathContext = implode("\n", $contextLines);
                    $systemPrompt = ($systemPrompt ? $systemPrompt . "\n\n" : '') . $pathContext;
                }
            } catch (DoesNotExistException $e) {
                // Project was deleted, ignore
            }
        }

        // 6. Call Claude (with MCP tools if available). If the call fails because
        //    a cached Anthropic file_id was evicted server-side, drop the row,
        //    rebuild content blocks (re-uploading), and retry once.
        $startMs = (int)(microtime(true) * 1000.0);
        $options = $this->conversationOptions($conversation);
        $result = $this->callClaude($claudeMessages, $systemPrompt, $options, $conversation);
        if (
            !empty($files)
            && isset($result['error'])
            && ($staleId = $this->filesService->extractStaleFileIdFromError(new \RuntimeException((string)$result['error']))) !== null
            && $this->filesService->evictByFileId($staleId)
        ) {
            $rebuilt = $this->buildFileContentBlocks($files);
            if (!empty($rebuilt['blocks']) && $claudeMessages !== []) {
                $documentsIndex = $rebuilt['documents'];
                $lastIdx = count($claudeMessages) - 1;
                $userText = $claudeMessages[$lastIdx]['content'];
                if (is_array($userText)) {
                    $textBlock = end($userText) ?: ['type' => 'text', 'text' => ''];
                    $userText = $textBlock['text'] ?? '';
                }
                $claudeMessages[$lastIdx]['content'] = array_merge(
                    $rebuilt['blocks'],
                    [['type' => 'text', 'text' => $userText]]
                );
            }
            $result = $this->callClaude($claudeMessages, $systemPrompt, $options, $conversation);
        }
        $latencyMs = (int)(microtime(true) * 1000.0) - $startMs;

        if (isset($result['error'])) {
            // Persist error as assistant message so user sees it in history
            $assistantMsg = new MessageEntity();
            $assistantMsg->setConversationId($id);
            $assistantMsg->setRole('assistant');
            $assistantMsg->setContent('Error: ' . $result['error']);
            $assistantMsg->setLatencyMs($latencyMs);
            $assistantMsg->setCreatedAt(time());
            $assistantMsg = $this->messageMapper->insert($assistantMsg);

            $conversation->setUpdatedAt(time());
            $this->conversationMapper->update($conversation);

            return new JSONResponse([
                'userMessage' => $this->serializeMessage($userMsg, $fileEntities),
                'assistantMessage' => $assistantMsg->jsonSerialize(),
                'conversation' => $conversation->jsonSerialize(),
            ]);
        }

        // 7. Persist assistant response
        $assistantMsg = new MessageEntity();
        $assistantMsg->setConversationId($id);
        $assistantMsg->setRole('assistant');
        $assistantMsg->setContent($result['response']);
        $assistantMsg->setInputTokens($result['usage']['input_tokens'] ?? null);
        $assistantMsg->setOutputTokens($result['usage']['output_tokens'] ?? null);
        $assistantMsg->setCacheCreationTokens($result['usage']['cache_creation_tokens'] ?? null);
        $assistantMsg->setCacheReadTokens($result['usage']['cache_read_tokens'] ?? null);
        $assistantMsg->setLatencyMs($latencyMs);
        if (!empty($result['citations'])) {
            $assistantMsg->setCitations(json_encode($result['citations']) ?: null);
            if (!empty($documentsIndex)) {
                $assistantMsg->setDocuments(json_encode($documentsIndex) ?: null);
            }
        }
        $assistantMsg->setCreatedAt(time());
        $assistantMsg = $this->messageMapper->insert($assistantMsg);

        // 7. Auto-title: generate from first user message if no title yet
        if ($conversation->getTitle() === null || $conversation->getTitle() === '') {
            $title = mb_substr($prompt, 0, 50);
            if (mb_strlen($prompt) > 50) {
                $title .= '…';
            }
            $conversation->setTitle($title);
        }

        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);

        $this->queueContextChatIndex($id);

        return new JSONResponse([
            'userMessage' => $this->serializeMessage($userMsg, $fileEntities),
            'assistantMessage' => $assistantMsg->jsonSerialize(),
            'conversation' => $conversation->jsonSerialize(),
        ]);
    }

    /**
     * Streaming variant of message(): sends the same conversation turn but
     * returns an SSE stream so the assistant text reaches the UI as it is
     * generated. Event types yielded over the stream:
     *
     *   user_message   — once at the start: persisted user message + files
     *   text_delta     — assistant text chunk
     *   tool_use       — finalized tool invocation (name + input)
     *   tool_result    — locally-executed tool output
     *   done           — terminal: usage totals + accumulated citations
     *   persisted      — final: persisted assistant message + conversation
     *   error          — terminal on failure (still followed by persisted
     *                    so the user sees what was streamed before the
     *                    error in their conversation history)
     *
     * Same precondition rules and error handling as message().
     *
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    // Each call bills an LLM request. These are the endpoints the chat UI
    // actually uses — ChatController's limits never covered them.
    #[UserRateLimit(limit: 30, period: 60)]
    #[AnonRateLimit(limit: 30, period: 60)]
    #[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
    public function messageStream(int $id, string $prompt = '', array $files = []): Response {
        if (!$prompt && empty($files)) {
            return $this->clientError(400, 'No prompt provided');
        }
        if (!$this->providerFactory->hasPermittedProvider($this->userId)) {
            return $this->noProviderAvailable();
        }
        try {
            $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }
        return new SSEResponse($this->streamConversationReply($id, $prompt, $files));
    }

    /**
     * The generator that drives messageStream(). Persists the user message
     * up front, runs chatWithToolsStream(), accumulates text/citations as
     * events flow through, and persists the assistant message on stream
     * completion (or on error, with whatever text streamed before failure).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamConversationReply(int $id, string $prompt, array $files): \Generator {
        $now = time();

        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            yield ['type' => 'error', 'error' => 'Conversation not found'];
            return;
        }

        // 1. Persist user message + files (mirrors message()).
        $userMsg = new MessageEntity();
        $userMsg->setConversationId($id);
        $userMsg->setRole('user');
        $userMsg->setContent($prompt);
        $userMsg->setCreatedAt($now);
        $userMsg = $this->messageMapper->insert($userMsg);

        $fileEntities = [];
        foreach ($files as $filePath) {
            try {
                $info = $this->fileService->getInfo($filePath, $this->requireUserId());
            } catch (\Exception $e) {
                $info = ['name' => basename($filePath), 'mimeType' => 'application/octet-stream'];
            }
            $mf = new MessageFile();
            $mf->setMessageId($userMsg->getId());
            $mf->setFilePath($filePath);
            $mf->setFileName($info['name'] ?? basename($filePath));
            $mf->setMimeType($info['mimeType'] ?? null);
            $mf->setCreatedAt($now);
            $mf = $this->messageFileMapper->insert($mf);
            $fileEntities[] = $mf;
        }

        yield ['type' => 'user_message', 'userMessage' => $this->serializeMessage($userMsg, $fileEntities)];

        // 2. Build messages + system prompt (mirrors message()).
        $allMessages = $this->messageMapper->findByConversation($id);
        $claudeMessages = [];
        foreach ($allMessages as $msg) {
            $claudeMessages[] = ['role' => $msg->getRole(), 'content' => $msg->getContent()];
        }
        $documentsIndex = [];
        if (!empty($files)) {
            $built = $this->buildFileContentBlocks($files);
            $contentBlocks = $built['blocks'];
            $documentsIndex = $built['documents'];
            if (!empty($contentBlocks) && $claudeMessages !== []) {
                $lastIdx = count($claudeMessages) - 1;
                $userText = $claudeMessages[$lastIdx]['content'];
                $claudeMessages[$lastIdx]['content'] = array_merge(
                    $contentBlocks,
                    [['type' => 'text', 'text' => $userText]]
                );
            }
        }

        $systemPrompt = null;
        $projectId = $conversation->getProjectId();
        if ($projectId !== null) {
            try {
                $project = $this->projectMapper->findByIdAndUser($projectId, $this->requireUserId());
                $systemPrompt = $project->getSystemPrompt();
                $paths = $this->projectPathMapper->findByProject($project->getId());
                if (!empty($paths)) {
                    $contextLines = ['Project: ' . $project->getTitle()];
                    foreach ($paths as $projectPath) {
                        $contextLines[] = '- [' . $projectPath->getPathType() . '] ' . $projectPath->getPath();
                    }
                    $systemPrompt = ($systemPrompt ? $systemPrompt . "\n\n" : '') . implode("\n", $contextLines);
                }
            } catch (DoesNotExistException $e) {
                // Project deleted — proceed without project context.
            }
        }

        // 3. Pick the path: native MCP connector (Anthropic calls servers directly)
        //    or local agentic loop (PHP dispatches tools per turn). Native is
        //    only used when the active provider supports it AND the flag is on
        //    AND we can offer at least one HTTPS-reachable server descriptor;
        //    otherwise we fall back to the provider-agnostic local loop.
        $provider = $this->resolveProvider($conversation);
        $useNativeMcp = false;
        $nativeMcpServers = [];
        if ($provider->supportsNativeMcp() && $this->nativeMcp->isEnabledForUser($this->userId)) {
            $nativeMcpServers = $provider->getId() === 'mistral'
                ? $this->nativeMcp->buildMistralConnectorTools()
                : $this->nativeMcp->buildServerDefinitions();
            if (!empty($nativeMcpServers)) {
                $useNativeMcp = true;
            }
        }

        $tools = [];
        // Replaced below unless the native connector handles the tools instead;
        // keeping it callable spares every caller a null check.
        $toolExecutor = static fn(string $_name, array $_input): array => [];
        if (!$useNativeMcp) {
            try {
                $allTools = $this->mcpClient->getAllTools();
            } catch (\Throwable $e) {
                $allTools = ['tools' => [], 'mapping' => []];
            }
            $tools = $allTools['tools'] ?? [];
            $mapping = $allTools['mapping'] ?? [];
            $mcpClient = $this->mcpClient;
            $toolExecutor = function (string $name, array $input) use ($mcpClient, $mapping): array {
                return $mcpClient->executeTool($name, $input, $mapping);
            };
        }

        // 4. Drive the streaming generator, accumulating final state.
        $accumulatedText = '';
        $finalCitations = [];
        $finalUsage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_creation_tokens' => null, 'cache_read_tokens' => null];
        $errorMessage = null;
        $startMs = (int)(microtime(true) * 1000.0);
        $options = $this->conversationOptions($conversation);

        $eventStream = $useNativeMcp
            ? $provider->chatWithNativeMcp(
                $claudeMessages,
                $nativeMcpServers,
                $systemPrompt,
                $this->userId,
                $options,
            )
            : $provider->chatWithToolsStream(
                $claudeMessages,
                $tools,
                $toolExecutor,
                $systemPrompt,
                $this->userId,
                $options,
            );

        foreach ($eventStream as $event) {
            switch ($event['type'] ?? null) {
                case 'text_delta':
                    $accumulatedText .= $event['text'] ?? '';
                    break;
                case 'done':
                    $finalCitations = $event['citations'] ?? [];
                    $finalUsage = $event['usage'] ?? $finalUsage;
                    break;
                case 'error':
                    $errorMessage = $event['error'] ?? 'Stream error';
                    if (isset($event['usage']) && is_array($event['usage'])) {
                        $finalUsage = $event['usage'];
                    }
                    break;
            }
            yield $event;
        }

        $latencyMs = (int)(microtime(true) * 1000.0) - $startMs;

        // 5. Persist assistant message — even on error, so partial output is preserved.
        $assistantContent = $accumulatedText !== ''
            ? $accumulatedText
            : ($errorMessage !== null ? 'Error: ' . $errorMessage : '');
        if ($errorMessage !== null && $accumulatedText !== '') {
            $assistantContent .= "\n\n_(stream interrupted: " . $errorMessage . ')_';
        }

        $assistantMsg = new MessageEntity();
        $assistantMsg->setConversationId($id);
        $assistantMsg->setRole('assistant');
        $assistantMsg->setContent($assistantContent);
        $assistantMsg->setInputTokens($finalUsage['input_tokens'] ?? null);
        $assistantMsg->setOutputTokens($finalUsage['output_tokens'] ?? null);
        $assistantMsg->setCacheCreationTokens($finalUsage['cache_creation_tokens'] ?? null);
        $assistantMsg->setCacheReadTokens($finalUsage['cache_read_tokens'] ?? null);
        $assistantMsg->setLatencyMs($latencyMs);
        if (!empty($finalCitations)) {
            $assistantMsg->setCitations(json_encode($finalCitations) ?: null);
            if (!empty($documentsIndex)) {
                $assistantMsg->setDocuments(json_encode($documentsIndex) ?: null);
            }
        }
        $assistantMsg->setCreatedAt(time());
        $assistantMsg = $this->messageMapper->insert($assistantMsg);

        // 6. Auto-title (mirrors message()).
        if ($conversation->getTitle() === null || $conversation->getTitle() === '') {
            $title = mb_substr($prompt, 0, 50);
            if (mb_strlen($prompt) > 50) {
                $title .= '…';
            }
            $conversation->setTitle($title);
        }
        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);

        $this->queueContextChatIndex($id);

        yield [
            'type' => 'persisted',
            'assistantMessage' => $assistantMsg->jsonSerialize(),
            'conversation' => $conversation->jsonSerialize(),
        ];
    }

    /**
     * Duplicate a conversation with all its messages and files
     *
     * @param int $id Conversation ID
     *
     * 200: The duplicated conversation
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function duplicate(int $id): JSONResponse {
        try {
            $original = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        $now = time();

        // Clone conversation
        $newConv = new Conversation();
        $newConv->setUserId($this->requireUserId());
        $newConv->setTitle(($original->getTitle() ?? '') . ' (copy)');
        $newConv->setModel($original->getModel());
        // The copy must answer like the original: carry the pinned provider and
        // the per-conversation overrides, not just the model. A pin the user is
        // no longer permitted to use is dropped rather than copied forward, so
        // the copy follows their current setting instead of carrying a denied
        // provider into a brand-new conversation.
        $originalProvider = $original->getProvider();
        $newConv->setProvider(
            $originalProvider !== null && $this->providerFactory->isAllowedForUser($originalProvider, $this->userId)
                ? $originalProvider
                : null,
        );
        $newConv->setEffort($original->getEffort());
        $newConv->setThinking($original->getThinking());
        $newConv->setProjectId($original->getProjectId());
        $newConv->setCreatedAt($now);
        $newConv->setUpdatedAt($now);
        $newConv = $this->conversationMapper->insert($newConv);

        // Clone messages and their files
        $messages = $this->messageMapper->findByConversation($id);
        foreach ($messages as $msg) {
            $newMsg = new MessageEntity();
            $newMsg->setConversationId($newConv->getId());
            $newMsg->setRole($msg->getRole());
            $newMsg->setContent($msg->getContent());
            $newMsg->setInputTokens($msg->getInputTokens());
            $newMsg->setOutputTokens($msg->getOutputTokens());
            $newMsg->setCacheCreationTokens($msg->getCacheCreationTokens());
            $newMsg->setCacheReadTokens($msg->getCacheReadTokens());
            $newMsg->setLatencyMs($msg->getLatencyMs());
            $newMsg->setCitations($msg->getCitations());
            $newMsg->setDocuments($msg->getDocuments());
            $newMsg->setCreatedAt($msg->getCreatedAt());
            $newMsg = $this->messageMapper->insert($newMsg);

            $files = $this->messageFileMapper->findByMessage($msg->getId());
            foreach ($files as $file) {
                $newFile = new MessageFile();
                $newFile->setMessageId($newMsg->getId());
                $newFile->setFilePath($file->getFilePath());
                $newFile->setFileName($file->getFileName());
                $newFile->setMimeType($file->getMimeType());
                $newFile->setCreatedAt($file->getCreatedAt());
                $this->messageFileMapper->insert($newFile);
            }
        }

        $this->queueContextChatIndex($newConv->getId());

        return new JSONResponse($newConv->jsonSerialize());
    }

    /**
     * Search messages across all conversations
     *
     * @param string $query Search query
     * @param int $limit Max results
     * @param int $cursor Pagination cursor (message ID)
     *
     * 200: Matching messages
     *
     * @return JSONResponse<Http::STATUS_OK, list<array<string, mixed>>, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function search(string $query = '', int $limit = 20, int $cursor = 0): JSONResponse {
        if (trim($query) === '') {
            return new JSONResponse([]);
        }

        $messages = $this->messageMapper->search($this->requireUserId(), $query, $limit, $cursor);
        $result = [];
        foreach ($messages as $msg) {
            $data = $msg->jsonSerialize();
            try {
                $conv = $this->conversationMapper->findByIdAndUser($msg->getConversationId(), $this->requireUserId());
                $data['conversationTitle'] = $conv->getTitle();
            } catch (DoesNotExistException $e) {
                $data['conversationTitle'] = null;
            }
            $result[] = $data;
        }

        return new JSONResponse($result);
    }

    /**
     * Build structured Claude API content blocks from file paths.
     *
     * Images are returned as vision-compatible image blocks (optimized),
     * PDFs as document blocks, and text files as text blocks.
     *
     * @param string[] $files File paths
     * @return array Claude API content blocks (image/document/text)
     */
    /**
     * Build Anthropic content blocks for the given Nextcloud file paths.
     *
     * Returns the blocks alongside a documents index — one entry per `type:document`
     * block in the order Anthropic sees them. The documents index is what citation
     * `document_index` values resolve against, so the frontend can map a citation
     * back to a Nextcloud file path and open it.
     *
     * @param string[] $files
     * @return array{blocks: array<int, array<string, mixed>>, documents: array<int, array{index:int,path:string,title:string,mimeType:string,fileId?:string}>}
     */
    private function buildFileContentBlocks(array $files): array {
        $blocks = [];
        $documents = [];
        foreach ($files as $filePath) {
            try {
                $fileData = $this->fileService->getContent($filePath, $this->requireUserId());
                $mimeType = $fileData['mimeType'];

                if (str_starts_with($mimeType, 'image/') && $this->imageOptimizer->isSupported($mimeType)) {
                    $optimized = $this->imageOptimizer->optimize(
                        base64_decode($fileData['content']),
                        $mimeType
                    );
                    $imageBlock = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $optimized['mimeType'],
                            'data' => $optimized['data'],
                        ],
                    ];
                    $rawBytes = base64_decode($optimized['data']);
                    $fileId = $this->userId !== null
                        ? $this->filesService->getOrUploadFileId($rawBytes, $fileData['name'], $optimized['mimeType'], $this->userId)
                        : null;
                    if ($fileId !== null) {
                        $imageBlock['source'] = ['type' => 'file', 'file_id' => $fileId];
                    }
                    $blocks[] = $imageBlock;
                } elseif ($mimeType === 'application/pdf') {
                    $docBlock = [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => $fileData['content'],
                        ],
                        'title' => $fileData['name'],
                        'citations' => ['enabled' => true],
                    ];
                    $rawBytes = base64_decode($fileData['content']);
                    $fileId = $this->userId !== null
                        ? $this->filesService->getOrUploadFileId($rawBytes, $fileData['name'], 'application/pdf', $this->userId)
                        : null;
                    if ($fileId !== null) {
                        $docBlock['source'] = ['type' => 'file', 'file_id' => $fileId];
                    }
                    $entry = [
                        'index' => count($documents),
                        'path' => $filePath,
                        'title' => $fileData['name'],
                        'mimeType' => 'application/pdf',
                    ];
                    if ($fileId !== null) {
                        $entry['fileId'] = $fileId;
                    }
                    $documents[] = $entry;
                    $blocks[] = $docBlock;
                } else {
                    $blocks[] = [
                        'type' => 'text',
                        'text' => "--- File: {$fileData['name']} ({$mimeType}, {$fileData['size']} bytes) ---\n{$fileData['content']}",
                    ];
                }
            } catch (\Exception $e) {
                // This text is sent to the LLM provider, so an exception message
                // here would ship server paths off-instance. Log it, tell the
                // model only that the file was unreadable.
                $this->logger->warning(
                    'Could not attach file to conversation',
                    ['exception' => $e, 'file' => basename($filePath)],
                );
                $blocks[] = [
                    'type' => 'text',
                    'text' => "--- File: " . basename($filePath) . " (could not be read) ---",
                ];
            }
        }
        return ['blocks' => $blocks, 'documents' => $documents];
    }

    /**
     * @return JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>
     */
    private function forbiddenProvider(string $providerId): JSONResponse {
        return $this->clientError(
            Http::STATUS_FORBIDDEN,
            'You are not permitted to use this provider: ' . $providerId,
        );
    }

    /**
     * @return JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>
     */
    private function noProviderAvailable(): JSONResponse {
        return $this->clientError(
            Http::STATUS_FORBIDDEN,
            NoPermittedProviderException::USER_MESSAGE,
        );
    }

    /**
     * The provider that should serve a conversation.
     *
     * A pinned provider wins; null falls back to the user's current setting,
     * which is what every pre-existing conversation does. getProviderForUser()
     * degrades rather than fails: a pin that later stops being registered — or
     * that the admin has since blocked for this user — falls back to the user's
     * current provider instead of continuing to be honoured.
     *
     * @throws NoPermittedProviderException when every provider is blocked
     */
    private function resolveProvider(Conversation $conversation): LLMProviderInterface {
        return $this->providerFactory->getProviderForUser($this->userId, $conversation->getProvider());
    }

    /**
     * Per-conversation request options (effort / thinking overrides).
     * Unset overrides are omitted so provider-side defaults apply.
     */
    private function conversationOptions(Conversation $conversation): array {
        $options = [];
        // Only a pinned conversation overrides the model. An unpinned one
        // follows the user's setting for both provider and model, so passing
        // its snapshotted model would send e.g. a Claude model id to whichever
        // provider the user switched to.
        if ($conversation->getProvider() !== null && $conversation->getModel() !== '') {
            $options['model'] = $conversation->getModel();
        }
        if ($conversation->getEffort() !== null) {
            $options['effort'] = $conversation->getEffort();
        }
        if ($conversation->getThinking() !== null) {
            $options['thinking'] = $conversation->getThinking();
        }
        return $options;
    }

    /**
     * Call the conversation's LLM provider with MCP tool support if available
     */
    private function callClaude(array $messages, ?string $systemPrompt = null, array $options = [], ?Conversation $conversation = null): array {
        $provider = $conversation !== null
            ? $this->resolveProvider($conversation)
            : $this->providerFactory->getProvider($this->userId);

        if ($provider->supportsNativeMcp() && $this->nativeMcp->isEnabledForUser($this->userId)) {
            $mcpServers = $provider->getId() === 'mistral'
                ? $this->nativeMcp->buildMistralConnectorTools()
                : $this->nativeMcp->buildServerDefinitions();
            if (!empty($mcpServers)) {
                return $provider->chatWithNativeMcpCollect(
                    $messages,
                    $mcpServers,
                    $systemPrompt,
                    $this->userId,
                    $options,
                );
            }
        }

        try {
            $allTools = $this->mcpClient->getAllTools();
        } catch (\Throwable $e) {
            $allTools = ['tools' => [], 'mapping' => []];
        }

        if (!empty($allTools['tools'])) {
            $mapping = $allTools['mapping'];
            $mcpClient = $this->mcpClient;
            return $provider->chatWithTools(
                $messages,
                $allTools['tools'],
                function (string $name, array $input) use ($mcpClient, $mapping): array {
                    return $mcpClient->executeTool($name, $input, $mapping);
                },
                $systemPrompt,
                $this->userId,
                $options,
            );
        }

        return $provider->chat($messages, $systemPrompt, $this->userId, $options);
    }

    /**
     * Serialize a message entity with its file entities
     */
    private function serializeMessage(MessageEntity $msg, array $files): array {
        $data = $msg->jsonSerialize();
        $data['files'] = array_map(fn(MessageFile $f) => $f->jsonSerialize(), $files);
        return $data;
    }
}
