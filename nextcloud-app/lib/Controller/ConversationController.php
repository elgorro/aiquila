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
use OCA\AIquila\Service\FileService;
use OCA\AIquila\Service\FilesService;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\McpClientService;
use OCA\AIquila\Service\NativeMcpService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class ConversationController extends Controller {
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
    private ?string $userId;

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
        ?string $userId
    ) {
        parent::__construct($appName, $request);
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
        $this->userId = $userId;
    }

    /**
     * List all conversations for the current user
     *
     * 200: List of conversations
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{id: int, title: ?string, model: string, createdAt: int, updatedAt: int}>, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function index(): JSONResponse {
        $conversations = $this->conversationMapper->findAllByUser($this->userId);
        return new JSONResponse(array_map(
            fn(Conversation $c) => $c->jsonSerialize(),
            $conversations
        ));
    }

    /**
     * Create a new conversation
     *
     * 200: The created conversation
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, title: ?string, model: string, createdAt: int, updatedAt: int}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function create(): JSONResponse {
        $now = time();
        $conversation = new Conversation();
        $conversation->setUserId($this->userId);
        $conversation->setModel($this->providerFactory->getProvider($this->userId)->getModel($this->userId));
        $conversation->setCreatedAt($now);
        $conversation->setUpdatedAt($now);

        $conversation = $this->conversationMapper->insert($conversation);
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
     * @return JSONResponse<Http::STATUS_OK, array{id: int, title: ?string, model: string, messages: list<array<string, mixed>>}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function show(int $id): JSONResponse {
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Conversation not found'], 404);
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
     * Update a conversation (title and/or project link)
     *
     * @param int $id Conversation ID
     * @param string $title New title
     * @param int|null $projectId Project ID to link (null to clear)
     *
     * 200: Updated conversation
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, title: ?string, model: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function update(int $id, string $title = '', ?int $projectId = null): JSONResponse {
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Conversation not found'], 404);
        }

        if ($title !== '') {
            $conversation->setTitle($title);
        }

        // Allow explicitly setting or clearing project association
        $requestParams = $this->request->getParams();
        if (array_key_exists('projectId', $requestParams)) {
            $conversation->setProjectId($projectId);
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
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function destroy(int $id): JSONResponse {
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Conversation not found'], 404);
        }

        // Delete files for each message
        $messages = $this->messageMapper->findByConversation($id);
        foreach ($messages as $msg) {
            $this->messageFileMapper->deleteByMessage($msg->getId());
        }
        $this->messageMapper->deleteByConversation($id);
        $this->conversationMapper->delete($conversation);

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
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{userMessage: array<string, mixed>, assistantMessage: array<string, mixed>, conversation: array<string, mixed>}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function message(int $id, string $prompt = '', array $files = []): JSONResponse {
        if (!$prompt && empty($files)) {
            return new JSONResponse(['error' => 'No prompt provided'], 400);
        }

        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Conversation not found'], 404);
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
                $info = $this->fileService->getInfo($filePath, $this->userId);
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
            if (!empty($contentBlocks)) {
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
        if ($conversation->getProjectId() !== null) {
            try {
                $project = $this->projectMapper->findByIdAndUser($conversation->getProjectId(), $this->userId);
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
        $startMs = (int)(microtime(true) * 1000);
        $result = $this->callClaude($claudeMessages, $systemPrompt);
        if (
            !empty($files)
            && isset($result['error'])
            && ($staleId = $this->filesService->extractStaleFileIdFromError(new \RuntimeException((string)$result['error']))) !== null
            && $this->filesService->evictByFileId($staleId)
        ) {
            $rebuilt = $this->buildFileContentBlocks($files);
            if (!empty($rebuilt['blocks'])) {
                $documentsIndex = $rebuilt['documents'];
                $lastIdx = count($claudeMessages) - 1;
                $userText = $claudeMessages[$lastIdx]['content'];
                if (is_array($userText)) {
                    $textBlock = end($userText) ?: ['type' => 'text', 'text' => ''];
                    $userText = is_array($textBlock) ? ($textBlock['text'] ?? '') : '';
                }
                $claudeMessages[$lastIdx]['content'] = array_merge(
                    $rebuilt['blocks'],
                    [['type' => 'text', 'text' => $userText]]
                );
            }
            $result = $this->callClaude($claudeMessages, $systemPrompt);
        }
        $latencyMs = (int)(microtime(true) * 1000) - $startMs;

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
            $assistantMsg->setCitations(json_encode($result['citations']));
            if (!empty($documentsIndex)) {
                $assistantMsg->setDocuments(json_encode($documentsIndex));
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
    #[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
    public function messageStream(int $id, string $prompt = '', array $files = []): Response {
        if (!$prompt && empty($files)) {
            return new JSONResponse(['error' => 'No prompt provided'], 400);
        }
        try {
            $this->conversationMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Conversation not found'], 404);
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
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->userId);
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
                $info = $this->fileService->getInfo($filePath, $this->userId);
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
            if (!empty($contentBlocks)) {
                $lastIdx = count($claudeMessages) - 1;
                $userText = $claudeMessages[$lastIdx]['content'];
                $claudeMessages[$lastIdx]['content'] = array_merge(
                    $contentBlocks,
                    [['type' => 'text', 'text' => $userText]]
                );
            }
        }

        $systemPrompt = null;
        if ($conversation->getProjectId() !== null) {
            try {
                $project = $this->projectMapper->findByIdAndUser($conversation->getProjectId(), $this->userId);
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
        $provider = $this->providerFactory->getProvider($this->userId);
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
        $startMs = (int)(microtime(true) * 1000);

        $eventStream = $useNativeMcp
            ? $provider->chatWithNativeMcp(
                $claudeMessages,
                $nativeMcpServers,
                $systemPrompt,
                $this->userId,
            )
            : $provider->chatWithToolsStream(
                $claudeMessages,
                $tools,
                $toolExecutor,
                $systemPrompt,
                $this->userId,
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

        $latencyMs = (int)(microtime(true) * 1000) - $startMs;

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
            $assistantMsg->setCitations(json_encode($finalCitations));
            if (!empty($documentsIndex)) {
                $assistantMsg->setDocuments(json_encode($documentsIndex));
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
     * @return JSONResponse<Http::STATUS_OK, array{id: int, title: ?string, model: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function duplicate(int $id): JSONResponse {
        try {
            $original = $this->conversationMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Conversation not found'], 404);
        }

        $now = time();

        // Clone conversation
        $newConv = new Conversation();
        $newConv->setUserId($this->userId);
        $newConv->setTitle(($original->getTitle() ?? '') . ' (copy)');
        $newConv->setModel($original->getModel());
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

        $messages = $this->messageMapper->search($this->userId, $query, $limit, $cursor);
        $result = [];
        foreach ($messages as $msg) {
            $data = $msg->jsonSerialize();
            try {
                $conv = $this->conversationMapper->findByIdAndUser($msg->getConversationId(), $this->userId);
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
                $fileData = $this->fileService->getContent($filePath, $this->userId);
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
                $blocks[] = [
                    'type' => 'text',
                    'text' => "--- File: " . basename($filePath) . " (could not read: {$e->getMessage()}) ---",
                ];
            }
        }
        return ['blocks' => $blocks, 'documents' => $documents];
    }

    /**
     * Call the active LLM provider with MCP tool support if available
     */
    private function callClaude(array $messages, ?string $systemPrompt = null): array {
        $provider = $this->providerFactory->getProvider($this->userId);

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
            );
        }

        return $provider->chat($messages, $systemPrompt, $this->userId);
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
