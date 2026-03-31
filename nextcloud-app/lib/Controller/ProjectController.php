<?php

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\Project;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPath;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Db\ConversationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ProjectController extends Controller {
    private ProjectMapper $projectMapper;
    private ProjectPathMapper $pathMapper;
    private ConversationMapper $conversationMapper;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        ProjectMapper $projectMapper,
        ProjectPathMapper $pathMapper,
        ConversationMapper $conversationMapper,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->projectMapper = $projectMapper;
        $this->pathMapper = $pathMapper;
        $this->conversationMapper = $conversationMapper;
        $this->userId = $userId;
    }

    /**
     * List all projects for the current user
     *
     * 200: List of projects with their paths
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function index(): JSONResponse {
        $projects = $this->projectMapper->findAllByUser($this->userId);
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
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function create(string $title, string $description = '', string $systemPrompt = ''): JSONResponse {
        if (trim($title) === '') {
            return new JSONResponse(['error' => 'Title is required'], 400);
        }

        $now = time();
        $project = new Project();
        $project->setUserId($this->userId);
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
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function show(int $id): JSONResponse {
        try {
            $project = $this->projectMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Project not found'], 404);
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
     * @param int $id Project ID
     * @param string $title New title
     * @param string $description New description
     * @param string $systemPrompt New system prompt
     *
     * 200: Updated project
     * 404: Project not found
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function update(int $id, string $title = '', string $description = '', string $systemPrompt = ''): JSONResponse {
        try {
            $project = $this->projectMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Project not found'], 404);
        }

        if ($title !== '') {
            $project->setTitle(trim($title));
        }
        $project->setDescription($description ?: null);
        $project->setSystemPrompt($systemPrompt ?: null);
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
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function destroy(int $id): JSONResponse {
        try {
            $project = $this->projectMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Project not found'], 404);
        }

        // Null out project_id on any conversations referencing this project
        $conversations = $this->conversationMapper->findAllByUser($this->userId);
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
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function addPath(int $id, string $path, string $pathType = 'file'): JSONResponse {
        if (!in_array($pathType, ['file', 'directory'], true)) {
            return new JSONResponse(['error' => 'pathType must be "file" or "directory"'], 400);
        }

        if (trim($path) === '') {
            return new JSONResponse(['error' => 'Path is required'], 400);
        }

        try {
            $this->projectMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Project not found'], 404);
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
     * @return JSONResponse
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function removePath(int $id, int $pathId): JSONResponse {
        try {
            $this->projectMapper->findByIdAndUser($id, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Project not found'], 404);
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
            return new JSONResponse(['error' => 'Path not found'], 404);
        }

        $this->pathMapper->delete($target);
        return new JSONResponse(['deleted' => true]);
    }
}
