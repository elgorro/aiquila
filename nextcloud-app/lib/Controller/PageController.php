<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

/**
 * Page Controller for AIquila main interface
 */
class PageController extends Controller {
    use RequiresUserIdTrait;


    private IInitialState $initialState;
    private LLMProviderFactory $providerFactory;
    private ConversationMapper $conversationMapper;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        IInitialState $initialState,
        LLMProviderFactory $providerFactory,
        ConversationMapper $conversationMapper,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->initialState = $initialState;
        $this->providerFactory = $providerFactory;
        $this->conversationMapper = $conversationMapper;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        // Inject initial state for JavaScript, reflecting the active provider.
        // An admin can block every provider for a user; the page still has to
        // render, so it reports the empty state rather than failing, and the
        // frontend shows the message instead of a chat box.
        try {
            $provider = $this->providerFactory->getProvider($this->userId);
            $config = $provider->getConfiguration();

            $this->initialState->provideInitialState('config', [
                'provider'    => $provider->getId(),
                'model'       => $provider->getModel($this->userId),
                'max_tokens'  => $config['max_tokens'],
                'has_api_key' => $provider->isConfigured($this->userId),
            ]);
        } catch (NoPermittedProviderException $e) {
            $this->initialState->provideInitialState('config', [
                'provider'    => '',
                'model'       => '',
                'max_tokens'  => 0,
                'has_api_key' => false,
                'no_provider' => NoPermittedProviderException::USER_MESSAGE,
            ]);
        }

        $this->initialState->provideInitialState('conversations',
            array_map(
                fn(Conversation $c) => $c->jsonSerialize(),
                $this->conversationMapper->findAllByUser($this->requireUserId())
            )
        );

        // Entry script is loaded as an ES module from templates/main.php.
        return new TemplateResponse('aiquila', 'main');
    }
}
