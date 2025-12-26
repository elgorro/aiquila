<?php

namespace OCA\AIquila\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\AIquila\Service\ClaudeService;

class ChatController extends Controller {
    private ClaudeService $claudeService;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, ClaudeService $claudeService, ?string $userId) {
        parent::__construct($appName, $request);
        $this->claudeService = $claudeService;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function ask(): JSONResponse {
        $prompt = $this->request->getParam('prompt', '');
        $context = $this->request->getParam('context', '');

        if (!$prompt) {
            return new JSONResponse(['error' => 'No prompt provided'], 400);
        }

        $result = $this->claudeService->ask($prompt, $context, $this->userId);
        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
     */
    public function summarize(): JSONResponse {
        $content = $this->request->getParam('content', '');

        if (!$content) {
            return new JSONResponse(['error' => 'No content provided'], 400);
        }

        $result = $this->claudeService->summarize($content, $this->userId);
        return new JSONResponse($result);
    }
}
