<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\InstructionsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Instance instructions: admin text placed in front of the system prompt for
 * every user, on the surfaces the admin enables. Admin-only; the framework
 * refuses both routes to other users.
 */
class InstructionsController extends Controller {
    use ErrorResponseTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private InstructionsService $instructions,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get the instance instructions and where they apply
     *
     * 200: Instructions, scope toggles and the length limit
     *
     * @return JSONResponse<Http::STATUS_OK, array{instructions: string, scopes: array{chat: bool, talk: bool, coworkers: bool, tasks: bool}, maxLength: int}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function get(): JSONResponse {
        return new JSONResponse($this->state());
    }

    /**
     * Save the instance instructions and where they apply
     *
     * @param string|null $instructions Text for every user ('' clears, null keeps it)
     * @param array<string, bool>|null $scopes Where it applies: chat, talk, coworkers, tasks (omitted keys keep their value)
     *
     * 200: The saved state
     * 400: Instructions are too long
     *
     * @return JSONResponse<Http::STATUS_OK, array{instructions: string, scopes: array{chat: bool, talk: bool, coworkers: bool, tasks: bool}, maxLength: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function update(?string $instructions = null, ?array $scopes = null): JSONResponse {
        $error = InstructionsService::tooLong($instructions, InstructionsService::MAX_LENGTH, 'Instructions');
        if ($error !== null) {
            return $this->clientError(400, $error);
        }
        if ($instructions !== null) {
            $this->instructions->setInstanceInstructions($instructions);
        }
        if ($scopes !== null) {
            $flags = [];
            foreach ($scopes as $scope => $enabled) {
                $flags[(string)$scope] = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            }
            $this->instructions->setScopes($flags);
        }
        return new JSONResponse($this->state());
    }

    /**
     * @return array{instructions: string, scopes: array{chat: bool, talk: bool, coworkers: bool, tasks: bool}, maxLength: int}
     */
    private function state(): array {
        return [
            'instructions' => $this->instructions->getInstanceInstructions(),
            'scopes' => $this->instructions->getScopes(),
            'maxLength' => InstructionsService::MAX_LENGTH,
        ];
    }
}
