<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\PromptMapper;
use OCA\AIquila\Service\InstructionsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The prompt library: reusable snippets a user inserts into a message or a
 * project's system prompt.
 *
 * `/api/prompts` manages the caller's own prompts and lists the instance ones
 * read-only. `/api/admin/prompts` is the admin side, for instance prompts
 * only; the framework refuses those routes to non-admins.
 */
class PromptController extends Controller {
    use ErrorResponseTrait;
    use RequiresUserIdTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private PromptMapper $mapper,
        private ?string $userId,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List the caller's prompts and the instance prompts
     *
     * 200: Instance prompts first, then the caller's own, each sorted by title
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{id: int, scope: string, title: string, description: ?string, content: string, createdAt: int, updatedAt: int}>, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function index(): JSONResponse {
        $prompts = array_merge($this->mapper->findAllInstance(), $this->mapper->findAllByUser($this->requireUserId()));
        return new JSONResponse(array_map(static fn (Prompt $p): array => $p->jsonSerialize(), $prompts));
    }

    /**
     * Create a personal prompt
     *
     * @param string $title Title shown in the picker
     * @param string $content Prompt text
     * @param string|null $description Optional description
     *
     * 200: The created prompt
     * 400: Missing title or content, or a field is too long
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, content: string, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function create(string $title, string $content, ?string $description = null): JSONResponse {
        return $this->createInScope(Prompt::SCOPE_USER, $title, $content, $description);
    }

    /**
     * Update a personal prompt
     *
     * Omitted fields stay as they are.
     *
     * @param int $id Prompt ID
     * @param string|null $title New title (null keeps it)
     * @param string|null $content New text (null keeps it)
     * @param string|null $description New description ('' clears, null keeps it)
     *
     * 200: The updated prompt
     * 400: A field is empty or too long
     * 404: Prompt not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, content: string, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function update(int $id, ?string $title = null, ?string $content = null, ?string $description = null): JSONResponse {
        try {
            $prompt = $this->mapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Prompt not found');
        }
        return $this->applyUpdate($prompt, $title, $content, $description);
    }

    /**
     * Delete a personal prompt
     *
     * @param int $id Prompt ID
     *
     * 200: Deleted
     * 404: Prompt not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function destroy(int $id): JSONResponse {
        try {
            $prompt = $this->mapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Prompt not found');
        }
        $this->mapper->delete($prompt);
        return new JSONResponse(['deleted' => true]);
    }

    /**
     * List the instance prompts
     *
     * 200: Instance prompts sorted by title
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{id: int, scope: string, title: string, description: ?string, content: string, createdAt: int, updatedAt: int}>, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminIndex(): JSONResponse {
        return new JSONResponse(array_map(static fn (Prompt $p): array => $p->jsonSerialize(), $this->mapper->findAllInstance()));
    }

    /**
     * Publish a prompt to every user
     *
     * @param string $title Title shown in the picker
     * @param string $content Prompt text
     * @param string|null $description Optional description
     *
     * 200: The created prompt
     * 400: Missing title or content, or a field is too long
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, content: string, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminCreate(string $title, string $content, ?string $description = null): JSONResponse {
        return $this->createInScope(Prompt::SCOPE_INSTANCE, $title, $content, $description);
    }

    /**
     * Update an instance prompt
     *
     * @param int $id Prompt ID
     * @param string|null $title New title (null keeps it)
     * @param string|null $content New text (null keeps it)
     * @param string|null $description New description ('' clears, null keeps it)
     *
     * 200: The updated prompt
     * 400: A field is empty or too long
     * 404: Prompt not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, content: string, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminUpdate(int $id, ?string $title = null, ?string $content = null, ?string $description = null): JSONResponse {
        try {
            $prompt = $this->mapper->findInstanceById($id);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Prompt not found');
        }
        return $this->applyUpdate($prompt, $title, $content, $description);
    }

    /**
     * Delete an instance prompt
     *
     * @param int $id Prompt ID
     *
     * 200: Deleted
     * 404: Prompt not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminDestroy(int $id): JSONResponse {
        try {
            $prompt = $this->mapper->findInstanceById($id);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Prompt not found');
        }
        $this->mapper->delete($prompt);
        return new JSONResponse(['deleted' => true]);
    }

    /**
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, content: string, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    private function createInScope(string $scope, string $title, string $content, ?string $description): JSONResponse {
        $title = trim($title);
        if ($title === '' || trim($content) === '') {
            return $this->clientError(400, 'Title and content are required');
        }
        $error = self::validate($title, $content, $description);
        if ($error !== null) {
            return $this->clientError(400, $error);
        }

        $now = time();
        $prompt = new Prompt();
        $prompt->setScope($scope);
        $prompt->setUserId($this->requireUserId());
        $prompt->setTitle($title);
        $prompt->setContent($content);
        $prompt->setDescription(self::orNull($description));
        $prompt->setCreatedAt($now);
        $prompt->setUpdatedAt($now);
        $prompt = $this->mapper->insert($prompt);

        return new JSONResponse($prompt->jsonSerialize());
    }

    /**
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, content: string, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    private function applyUpdate(Prompt $prompt, ?string $title, ?string $content, ?string $description): JSONResponse {
        if (($title !== null && trim($title) === '') || ($content !== null && trim($content) === '')) {
            return $this->clientError(400, 'Title and content cannot be empty');
        }
        $error = self::validate($title, $content, $description);
        if ($error !== null) {
            return $this->clientError(400, $error);
        }

        if ($title !== null) {
            $prompt->setTitle(trim($title));
        }
        if ($content !== null) {
            $prompt->setContent($content);
        }
        if ($description !== null) {
            $prompt->setDescription(self::orNull($description));
        }
        $prompt->setUpdatedAt(time());
        $this->mapper->update($prompt);

        return new JSONResponse($prompt->jsonSerialize());
    }

    private static function validate(?string $title, ?string $content, ?string $description): ?string {
        return InstructionsService::tooLong($title, InstructionsService::MAX_TITLE_LENGTH, 'Title')
            ?? InstructionsService::tooLong($description, InstructionsService::MAX_DESCRIPTION_LENGTH, 'Description')
            ?? InstructionsService::tooLong($content, InstructionsService::MAX_LENGTH, 'Content');
    }

    private static function orNull(?string $value): ?string {
        $value = $value !== null ? trim($value) : null;
        return $value === '' ? null : $value;
    }
}
