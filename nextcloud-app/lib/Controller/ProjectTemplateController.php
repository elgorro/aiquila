<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\Project;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPath;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Db\ProjectTemplate;
use OCA\AIquila\Db\ProjectTemplateMapper;
use OCA\AIquila\Db\Prompt;
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
 * Project templates: the title, description, system prompt and path
 * suggestions a new project is copied from.
 *
 * A project made from a template is a copy. Editing or deleting the template
 * later leaves it alone. `/api/project-templates` holds the caller's own
 * templates and lists the instance ones; `/api/admin/project-templates` is
 * where admins publish instance templates.
 */
class ProjectTemplateController extends Controller {
    use ErrorResponseTrait;
    use RequiresUserIdTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private ProjectTemplateMapper $mapper,
        private ProjectMapper $projectMapper,
        private ProjectPathMapper $pathMapper,
        private ?string $userId,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List the caller's templates and the instance templates
     *
     * 200: Instance templates first, then the caller's own, each sorted by title
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{id: int, scope: string, title: string, description: ?string, systemPrompt: ?string, paths: list<array{path: string, pathType: 'file'|'directory'}>, createdAt: int, updatedAt: int}>, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function index(): JSONResponse {
        $templates = array_merge($this->mapper->findAllInstance(), $this->mapper->findAllByUser($this->requireUserId()));
        return new JSONResponse(array_map(static fn (ProjectTemplate $t): array => $t->jsonSerialize(), $templates));
    }

    /**
     * Create a personal template
     *
     * With `projectId`, the template starts as a copy of that project (one of
     * the caller's own); the other parameters then override its fields.
     *
     * @param string|null $title Template title (required without projectId)
     * @param string|null $description Description
     * @param string|null $systemPrompt System prompt copied into new projects
     * @param list<array{path: string, pathType?: string}>|null $paths Path suggestions copied into new projects
     * @param int|null $projectId Save this project as a template
     *
     * 200: The created template
     * 400: Missing title, or a field is too long
     * 404: Project not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, systemPrompt: ?string, paths: list<array{path: string, pathType: 'file'|'directory'}>, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function create(?string $title = null, ?string $description = null, ?string $systemPrompt = null, ?array $paths = null, ?int $projectId = null): JSONResponse {
        return $this->createInScope(Prompt::SCOPE_USER, $title, $description, $systemPrompt, $paths, $projectId);
    }

    /**
     * Update a personal template
     *
     * Omitted fields stay as they are.
     *
     * @param int $id Template ID
     * @param string|null $title New title (null keeps it)
     * @param string|null $description New description ('' clears, null keeps it)
     * @param string|null $systemPrompt New system prompt ('' clears, null keeps it)
     * @param list<array{path: string, pathType?: string}>|null $paths New path suggestions (null keeps them)
     *
     * 200: The updated template
     * 400: Empty title, or a field is too long
     * 404: Template not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, systemPrompt: ?string, paths: list<array{path: string, pathType: 'file'|'directory'}>, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function update(int $id, ?string $title = null, ?string $description = null, ?string $systemPrompt = null, ?array $paths = null): JSONResponse {
        try {
            $template = $this->mapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException) {
            return $this->clientError(Http::STATUS_NOT_FOUND, 'Template not found');
        }
        $error = $this->applyFields($template, $title, $description, $systemPrompt, $paths);
        if ($error !== null) {
            return $this->clientError(Http::STATUS_BAD_REQUEST, $error);
        }
        $this->mapper->update($template);
        return new JSONResponse($template->jsonSerialize());
    }

    /**
     * Delete a personal template
     *
     * Projects already made from it are not touched.
     *
     * @param int $id Template ID
     *
     * 200: Deleted
     * 404: Template not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function destroy(int $id): JSONResponse {
        try {
            $template = $this->mapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException) {
            return $this->clientError(Http::STATUS_NOT_FOUND, 'Template not found');
        }
        $this->mapper->delete($template);
        return new JSONResponse(['deleted' => true]);
    }

    /**
     * Create a project from a template
     *
     * Copies the template's title, description, system prompt and paths into a
     * new project owned by the caller. Works for the caller's own templates and
     * for instance templates.
     *
     * @param int $id Template ID
     * @param string|null $title Title for the new project (default: the template's)
     *
     * 200: The created project with its paths
     * 400: A field is too long
     * 404: Template not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function createProject(int $id, ?string $title = null): JSONResponse {
        $template = $this->findVisible($id);
        if ($template === null) {
            return $this->clientError(Http::STATUS_NOT_FOUND, 'Template not found');
        }
        $title = $title !== null && trim($title) !== '' ? trim($title) : $template->getTitle();
        $error = InstructionsService::tooLong($title, InstructionsService::MAX_TITLE_LENGTH, 'Title');
        if ($error !== null) {
            return $this->clientError(Http::STATUS_BAD_REQUEST, $error);
        }

        $now = time();
        $project = new Project();
        $project->setUserId($this->requireUserId());
        $project->setTitle($title);
        $project->setDescription($template->getDescription());
        $project->setSystemPrompt($template->getSystemPrompt());
        $project->setCreatedAt($now);
        $project->setUpdatedAt($now);
        $project = $this->projectMapper->insert($project);

        $paths = [];
        foreach ($template->getPathList() as $entry) {
            $path = new ProjectPath();
            $path->setProjectId($project->getId());
            $path->setPath($entry['path']);
            $path->setPathType($entry['pathType']);
            $path->setCreatedAt($now);
            $paths[] = $this->pathMapper->insert($path)->jsonSerialize();
        }

        $data = $project->jsonSerialize();
        $data['paths'] = $paths;
        return new JSONResponse($data);
    }

    /**
     * List the instance templates
     *
     * 200: Instance templates sorted by title
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{id: int, scope: string, title: string, description: ?string, systemPrompt: ?string, paths: list<array{path: string, pathType: 'file'|'directory'}>, createdAt: int, updatedAt: int}>, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminIndex(): JSONResponse {
        return new JSONResponse(array_map(static fn (ProjectTemplate $t): array => $t->jsonSerialize(), $this->mapper->findAllInstance()));
    }

    /**
     * Publish a project template to every user
     *
     * With `projectId`, the template starts as a copy of one of the admin's own
     * projects.
     *
     * @param string|null $title Template title (required without projectId)
     * @param string|null $description Description
     * @param string|null $systemPrompt System prompt copied into new projects
     * @param list<array{path: string, pathType?: string}>|null $paths Path suggestions copied into new projects
     * @param int|null $projectId Publish this project as a template
     *
     * 200: The created template
     * 400: Missing title, or a field is too long
     * 404: Project not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, systemPrompt: ?string, paths: list<array{path: string, pathType: 'file'|'directory'}>, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminCreate(?string $title = null, ?string $description = null, ?string $systemPrompt = null, ?array $paths = null, ?int $projectId = null): JSONResponse {
        return $this->createInScope(Prompt::SCOPE_INSTANCE, $title, $description, $systemPrompt, $paths, $projectId);
    }

    /**
     * Update an instance template
     *
     * @param int $id Template ID
     * @param string|null $title New title (null keeps it)
     * @param string|null $description New description ('' clears, null keeps it)
     * @param string|null $systemPrompt New system prompt ('' clears, null keeps it)
     * @param list<array{path: string, pathType?: string}>|null $paths New path suggestions (null keeps them)
     *
     * 200: The updated template
     * 400: Empty title, or a field is too long
     * 404: Template not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, systemPrompt: ?string, paths: list<array{path: string, pathType: 'file'|'directory'}>, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminUpdate(int $id, ?string $title = null, ?string $description = null, ?string $systemPrompt = null, ?array $paths = null): JSONResponse {
        try {
            $template = $this->mapper->findInstanceById($id);
        } catch (DoesNotExistException) {
            return $this->clientError(Http::STATUS_NOT_FOUND, 'Template not found');
        }
        $error = $this->applyFields($template, $title, $description, $systemPrompt, $paths);
        if ($error !== null) {
            return $this->clientError(Http::STATUS_BAD_REQUEST, $error);
        }
        $this->mapper->update($template);
        return new JSONResponse($template->jsonSerialize());
    }

    /**
     * Delete an instance template
     *
     * @param int $id Template ID
     *
     * 200: Deleted
     * 404: Template not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminDestroy(int $id): JSONResponse {
        try {
            $template = $this->mapper->findInstanceById($id);
        } catch (DoesNotExistException) {
            return $this->clientError(Http::STATUS_NOT_FOUND, 'Template not found');
        }
        $this->mapper->delete($template);
        return new JSONResponse(['deleted' => true]);
    }

    /** A template the caller may use: an instance one or their own. */
    private function findVisible(int $id): ?ProjectTemplate {
        try {
            return $this->mapper->findInstanceById($id);
        } catch (DoesNotExistException) {
        }
        try {
            return $this->mapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed>|null $paths
     * @return JSONResponse<Http::STATUS_OK, array{id: int, scope: string, title: string, description: ?string, systemPrompt: ?string, paths: list<array{path: string, pathType: 'file'|'directory'}>, createdAt: int, updatedAt: int}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    private function createInScope(string $scope, ?string $title, ?string $description, ?string $systemPrompt, ?array $paths, ?int $projectId): JSONResponse {
        $template = new ProjectTemplate();

        if ($projectId !== null) {
            try {
                $project = $this->projectMapper->findByIdAndUser($projectId, $this->requireUserId());
            } catch (DoesNotExistException) {
                return $this->clientError(Http::STATUS_NOT_FOUND, 'Project not found');
            }
            $template->setTitle($project->getTitle());
            $template->setDescription($project->getDescription());
            $template->setSystemPrompt($project->getSystemPrompt());
            $template->setPathList(array_map(
                static fn (ProjectPath $p): array => ['path' => $p->getPath(), 'pathType' => $p->getPathType()],
                $this->pathMapper->findByProject($project->getId()),
            ));
        }

        if (($title === null || trim($title) === '') && $template->getTitle() === '') {
            return $this->clientError(Http::STATUS_BAD_REQUEST, 'Title is required');
        }

        $now = time();
        $template->setScope($scope);
        $template->setUserId($this->requireUserId());
        $template->setCreatedAt($now);

        $error = $this->applyFields($template, $title !== null && trim($title) !== '' ? $title : null, $description, $systemPrompt, $paths);
        if ($error !== null) {
            return $this->clientError(Http::STATUS_BAD_REQUEST, $error);
        }
        $template = $this->mapper->insert($template);
        return new JSONResponse($template->jsonSerialize());
    }

    /**
     * Apply the non-null fields to $template.
     *
     * @param array<array-key, mixed>|null $paths
     * @return string|null an error message, or null when the fields were applied
     */
    private function applyFields(ProjectTemplate $template, ?string $title, ?string $description, ?string $systemPrompt, ?array $paths): ?string {
        if ($title !== null && trim($title) === '') {
            return 'Title cannot be empty';
        }
        $error = InstructionsService::tooLong($title, InstructionsService::MAX_TITLE_LENGTH, 'Title')
            ?? InstructionsService::tooLong($description, InstructionsService::MAX_DESCRIPTION_LENGTH, 'Description')
            ?? InstructionsService::tooLong($systemPrompt, InstructionsService::MAX_LENGTH, 'System prompt');
        if ($error !== null) {
            return $error;
        }

        if ($title !== null) {
            $template->setTitle(trim($title));
        }
        if ($description !== null) {
            $template->setDescription(trim($description) !== '' ? trim($description) : null);
        }
        if ($systemPrompt !== null) {
            $template->setSystemPrompt(trim($systemPrompt) !== '' ? $systemPrompt : null);
        }
        if ($paths !== null) {
            $template->setPathList($paths);
        }
        $template->setUpdatedAt(time());
        return null;
    }
}
