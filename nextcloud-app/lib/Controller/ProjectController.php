<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\Project;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPath;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Service\InstructionsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ProjectController extends Controller {
    use ErrorResponseTrait;
    use RequiresUserIdTrait;

    private ProjectMapper $projectMapper;
    private ProjectPathMapper $pathMapper;
    private ConversationMapper $conversationMapper;
    private ?string $userId;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ProjectMapper $projectMapper,
        ProjectPathMapper $pathMapper,
        ConversationMapper $conversationMapper,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->projectMapper = $projectMapper;
        $this->pathMapper = $pathMapper;
        $this->conversationMapper = $conversationMapper;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    /**
     * List all projects for the current user
     *
     * 200: List of projects with their paths
     *
     * @return JSONResponse<Http::STATUS_OK, list<array<string, mixed>>, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function index(): JSONResponse {
        $projects = $this->projectMapper->findAllByUser($this->requireUserId());
        $result = [];
        foreach ($projects as $project) {
            $data = $project->jsonSerialize();
            $data['paths'] = array_map(
                fn(ProjectPath $p) => $p->jsonSerialize(),
                $this->pathMapper->findByProject($project->getId())
            );
            $result[] = $data;
        }
        return new JSONResponse($result);
    }

    /**
     * Create a new project
     *
     * @param string $title Project title
     * @param string $description Project description
     * @param string $systemPrompt System prompt for the project
     *
     * 200: The created project
     * 400: Title is missing, or a field is too long
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function create(string $title, string $description = '', string $systemPrompt = ''): JSONResponse {
        if (trim($title) === '') {
            return $this->clientError(400, 'Title is required');
        }
        $error = self::validate($title, $description, $systemPrompt);
        if ($error !== null) {
            return $this->clientError(400, $error);
        }

        $now = time();
        $project = new Project();
        $project->setUserId($this->requireUserId());
        $project->setTitle(trim($title));
        $project->setDescription($description ?: null);
        $project->setSystemPrompt($systemPrompt ?: null);
        $project->setCreatedAt($now);
        $project->setUpdatedAt($now);

        $project = $this->projectMapper->insert($project);

        $data = $project->jsonSerialize();
        $data['paths'] = [];
        return new JSONResponse($data);
    }

    /**
     * Get a project with its paths
     *
     * @param int $id Project ID
     *
     * 200: Project with paths
     * 404: Project not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function show(int $id): JSONResponse {
        try {
            $project = $this->projectMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Project not found');
        }

        $data = $project->jsonSerialize();
        $data['paths'] = array_map(
            fn(ProjectPath $p) => $p->jsonSerialize(),
            $this->pathMapper->findByProject($id)
        );
        return new JSONResponse($data);
    }

    /**
     * Update a project
     *
     * Omitted fields stay as they are, so renaming a project does not clear its
     * description or system prompt.
     *
     * @param int $id Project ID
     * @param string|null $title New title (null keeps it)
     * @param string|null $description New description ('' clears, null keeps it)
     * @param string|null $systemPrompt New system prompt ('' clears, null keeps it)
     *
     * 200: Updated project
     * 400: Empty title, or a field is too long
     * 404: Project not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function update(int $id, ?string $title = null, ?string $description = null, ?string $systemPrompt = null): JSONResponse {
        try {
            $project = $this->projectMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Project not found');
        }

        if ($title !== null && trim($title) === '') {
            return $this->clientError(400, 'Title cannot be empty');
        }
        $error = self::validate($title, $description, $systemPrompt);
        if ($error !== null) {
            return $this->clientError(400, $error);
        }

        if ($title !== null) {
            $project->setTitle(trim($title));
        }
        if ($description !== null) {
            $project->setDescription($description !== '' ? $description : null);
        }
        if ($systemPrompt !== null) {
            $project->setSystemPrompt($systemPrompt !== '' ? $systemPrompt : null);
        }
        $project->setUpdatedAt(time());

        $this->projectMapper->update($project);

        $data = $project->jsonSerialize();
        $data['paths'] = array_map(
            fn(ProjectPath $p) => $p->jsonSerialize(),
            $this->pathMapper->findByProject($id)
        );
        return new JSONResponse($data);
    }

    /**
     * Delete a project and all its paths
     *
     * @param int $id Project ID
     *
     * 200: Deletion confirmed
     * 404: Project not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function destroy(int $id): JSONResponse {
        try {
            $project = $this->projectMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Project not found');
        }

        // Null out project_id on any conversations referencing this project
        $conversations = $this->conversationMapper->findAllByUser($this->requireUserId());
        foreach ($conversations as $conv) {
            if ($conv->getProjectId() === $id) {
                $conv->setProjectId(null);
                $this->conversationMapper->update($conv);
            }
        }

        $this->pathMapper->deleteByProject($id);
        $this->projectMapper->delete($project);

        return new JSONResponse(['deleted' => true]);
    }

    /**
     * Add a path to a project
     *
     * @param int $id Project ID
     * @param string $path File or directory path
     * @param string $pathType Type: 'file' or 'directory'
     *
     * 200: The created path
     * 400: Invalid path type
     * 404: Project not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function addPath(int $id, string $path, string $pathType = 'file'): JSONResponse {
        if (!in_array($pathType, ['file', 'directory'], true)) {
            return $this->clientError(400, 'pathType must be "file" or "directory"');
        }

        if (trim($path) === '') {
            return $this->clientError(400, 'Path is required');
        }

        try {
            $this->projectMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Project not found');
        }

        $projectPath = new ProjectPath();
        $projectPath->setProjectId($id);
        $projectPath->setPath(trim($path));
        $projectPath->setPathType($pathType);
        $projectPath->setCreatedAt(time());

        $projectPath = $this->pathMapper->insert($projectPath);
        return new JSONResponse($projectPath->jsonSerialize());
    }

    /**
     * Remove a path from a project
     *
     * @param int $id Project ID
     * @param int $pathId Path ID
     *
     * 200: Deletion confirmed
     * 404: Project or path not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function removePath(int $id, int $pathId): JSONResponse {
        try {
            $this->projectMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Project not found');
        }

        $paths = $this->pathMapper->findByProject($id);
        $target = null;
        foreach ($paths as $p) {
            if ($p->getId() === $pathId) {
                $target = $p;
                break;
            }
        }

        if ($target === null) {
            return $this->clientError(404, 'Path not found');
        }

        $this->pathMapper->delete($target);
        return new JSONResponse(['deleted' => true]);
    }

    private static function validate(?string $title, ?string $description, ?string $systemPrompt): ?string {
        return InstructionsService::tooLong($title, InstructionsService::MAX_TITLE_LENGTH, 'Title')
            ?? InstructionsService::tooLong($description, InstructionsService::MAX_DESCRIPTION_LENGTH, 'Description')
            ?? InstructionsService::tooLong($systemPrompt, InstructionsService::MAX_LENGTH, 'System prompt');
    }
}
