<?php

declare(strict_types=1);

namespace OCA\AIquila\Controller;

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
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        IInitialState $initialState,
        ClaudeSDKService $claudeService,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->initialState = $initialState;
        $this->claudeService = $claudeService;
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
            'model' => $config['model'],
            'max_tokens' => $config['max_tokens'],
            'has_api_key' => !empty($config['api_key']),
        ]);

        // Load main script
        Util::addScript('aiquila', 'aiquila-main');

        return new TemplateResponse('aiquila', 'main');
    }
}
