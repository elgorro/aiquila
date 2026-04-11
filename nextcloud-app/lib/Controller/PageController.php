<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Service\ClaudeSDKService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

/**
 * Page Controller for AIquila main interface
 */
class PageController extends Controller {

    private IInitialState $initialState;
    private ClaudeSDKService $claudeService;
    private ConversationMapper $conversationMapper;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        IInitialState $initialState,
        ClaudeSDKService $claudeService,
        ConversationMapper $conversationMapper,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->initialState = $initialState;
        $this->claudeService = $claudeService;
        $this->conversationMapper = $conversationMapper;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        // Inject initial state for JavaScript
        $config = $this->claudeService->getConfiguration();

        $this->initialState->provideInitialState('config', [
            'model'       => $this->claudeService->getModel($this->userId),
            'max_tokens'  => $config['max_tokens'],
            'has_api_key' => !empty($config['api_key']),
        ]);

        $this->initialState->provideInitialState('conversations',
            array_map(
                fn(Conversation $c) => $c->jsonSerialize(),
                $this->conversationMapper->findAllByUser($this->userId)
            )
        );

        // Load main script
        Util::addScript('aiquila', 'aiquila-main');

        return new TemplateResponse('aiquila', 'main');
    }
}
