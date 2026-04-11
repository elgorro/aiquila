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
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\FileService;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\McpClientService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ConversationController extends Controller {
    private ConversationMapper $conversationMapper;
    private MessageMapper $messageMapper;
    private MessageFileMapper $messageFileMapper;
    private ProjectMapper $projectMapper;
    private ProjectPathMapper $projectPathMapper;
    private ClaudeSDKService $claudeService;
    private FileService $fileService;
    private ImageOptimizer $imageOptimizer;
    private McpClientService $mcpClient;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        MessageFileMapper $messageFileMapper,
        ProjectMapper $projectMapper,
        ProjectPathMapper $projectPathMapper,
        ClaudeSDKService $claudeService,
        FileService $fileService,
        ImageOptimizer $imageOptimizer,
        McpClientService $mcpClient,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->conversationMapper = $conversationMapper;
        $this->messageMapper = $messageMapper;
        $this->messageFileMapper = $messageFileMapper;
        $this->projectMapper = $projectMapper;
        $this->projectPathMapper = $projectPathMapper;
        $this->claudeService = $claudeService;
        $this->fileService = $fileService;
        $this->imageOptimizer = $imageOptimizer;
        $this->mcpClient = $mcpClient;
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
        $conversation->setModel($this->claudeService->getModel($this->userId));
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
        if (!empty($files)) {
            $contentBlocks = $this->buildFileContentBlocks($files);
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

        // 6. Call Claude (with MCP tools if available)
        $startMs = (int)(microtime(true) * 1000);
        $result = $this->callClaude($claudeMessages, $systemPrompt);
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
    private function buildFileContentBlocks(array $files): array {
        $blocks = [];
        foreach ($files as $filePath) {
            try {
                $fileData = $this->fileService->getContent($filePath, $this->userId);
                $mimeType = $fileData['mimeType'];

                if (str_starts_with($mimeType, 'image/') && $this->imageOptimizer->isSupported($mimeType)) {
                    $optimized = $this->imageOptimizer->optimize(
                        base64_decode($fileData['content']),
                        $mimeType
                    );
                    $blocks[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $optimized['mimeType'],
                            'data' => $optimized['data'],
                        ],
                    ];
                } elseif ($mimeType === 'application/pdf') {
                    $blocks[] = [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => $fileData['content'],
                        ],
                        'title' => $fileData['name'],
                    ];
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
        return $blocks;
    }

    /**
     * Call Claude with MCP tool support if available
     */
    private function callClaude(array $messages, ?string $systemPrompt = null): array {
        try {
            $allTools = $this->mcpClient->getAllTools();
        } catch (\Throwable $e) {
            $allTools = ['tools' => [], 'mapping' => []];
        }

        if (!empty($allTools['tools'])) {
            $mapping = $allTools['mapping'];
            $mcpClient = $this->mcpClient;
            return $this->claudeService->chatWithTools(
                $messages,
                $allTools['tools'],
                function (string $name, array $input) use ($mcpClient, $mapping): array {
                    return $mcpClient->executeTool($name, $input, $mapping);
                },
                $systemPrompt,
                $this->userId,
            );
        }

        return $this->claudeService->chat($messages, $systemPrompt, $this->userId);
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
