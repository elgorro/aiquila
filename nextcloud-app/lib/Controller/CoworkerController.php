<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\Exception\ValidationException;
use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\CoworkerService;
use OCA\AIquila\Service\CoworkerTemplates;
use OCA\AIquila\Service\CoworkerTriggerPolicy;
use OCA\AIquila\Service\CoworkerTriggerService;
use OCA\AIquila\Service\InstructionsService;
use OCA\AIquila\Service\McpClientService;
use OCP\AppFramework\Controller;
use OCA\AIquila\Service\Exception\NoActiveRunException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCA\AIquila\Service\AppSettings;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * REST API for cowork tasks — persistent scheduled AI jobs.
 *
 * Consumed by the Cowork UI and by the MCP server (Basic Auth), hence
 * NoCSRFRequired alongside NoAdminRequired.
 */
class CoworkerController extends Controller {
    use ErrorResponseTrait;
    use RequiresUserIdTrait;


    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CoworkerService $service,
        private readonly CoworkerTaskRegistry $registry,
        private readonly AppSettings $config,
        private readonly ?string $userId,
        private readonly LoggerInterface $logger,
        private readonly CoworkerTemplates $instanceTemplates,
        private readonly ?McpClientService $mcpClient = null,
        private readonly ?CoworkerTriggerService $triggers = null,
        private readonly ?CoworkerTriggerPolicy $triggerPolicy = null,
        private readonly ?IURLGenerator $urlGenerator = null,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List all coworkers for the current user
     *
     * 200: List of coworkers
     *
     * @return JSONResponse<Http::STATUS_OK, list<array<string, mixed>>, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function index(): JSONResponse {
        $coworkers = $this->service->listForUser((string)$this->userId);
        return new JSONResponse(array_map(fn(Coworker $c) => $c->jsonSerialize(), $coworkers));
    }

    /**
     * Get a single coworker
     *
     * @param int $id Coworker ID
     *
     * 200: The coworker
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function show(int $id): JSONResponse {
        try {
            $coworker = $this->service->findForUser($id, (string)$this->userId);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        }
        return new JSONResponse($coworker->jsonSerialize());
    }

    /**
     * Create a new coworker
     *
     * 200: The created coworker
     * 400: Invalid coworker configuration
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function create(): JSONResponse {
        try {
            $coworker = $this->service->create((string)$this->userId, $this->bodyData());
        } catch (ValidationException $e) {
            // ValidationException messages are written by this app and safe to show.
            return $this->clientError(400, $e->getMessage());
        }
        return new JSONResponse($coworker->jsonSerialize());
    }

    /**
     * Update a coworker
     *
     * @param int $id Coworker ID
     *
     * 200: The updated coworker
     * 400: Invalid coworker configuration
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function update(int $id): JSONResponse {
        try {
            $coworker = $this->service->update($id, (string)$this->userId, $this->bodyData());
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        } catch (ValidationException $e) {
            // ValidationException messages are written by this app and safe to show.
            return $this->clientError(400, $e->getMessage());
        }
        return new JSONResponse($coworker->jsonSerialize());
    }

    /**
     * Delete a coworker and its run history
     *
     * @param int $id Coworker ID
     *
     * 200: Coworker deleted
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: bool}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function destroy(int $id): JSONResponse {
        try {
            $this->service->delete($id, (string)$this->userId);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        }
        return new JSONResponse(['deleted' => true]);
    }

    /**
     * Pause a coworker
     *
     * @param int $id Coworker ID
     *
     * 200: The updated coworker
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function pause(int $id): JSONResponse {
        return $this->togglePaused($id, true);
    }

    /**
     * Resume a paused coworker
     *
     * @param int $id Coworker ID
     *
     * 200: The updated coworker
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function resume(int $id): JSONResponse {
        return $this->togglePaused($id, false);
    }

    /**
     * Enable a coworker
     *
     * @param int $id Coworker ID
     *
     * 200: The updated coworker
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function enable(int $id): JSONResponse {
        return $this->toggleActive($id, true);
    }

    /**
     * Disable a coworker
     *
     * @param int $id Coworker ID
     *
     * 200: The updated coworker
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function disable(int $id): JSONResponse {
        return $this->toggleActive($id, false);
    }

    /**
     * Run a coworker now
     *
     * @param int $id Coworker ID
     *
     * 200: The completed run
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    // Each run bills an LLM call, so it is metered per user and per IP.
    #[UserRateLimit(limit: 20, period: 60)]
    #[AnonRateLimit(limit: 20, period: 60)]
    #[OpenAPI]
    public function run(int $id): JSONResponse {
        try {
            $run = $this->service->runNow($id, (string)$this->userId);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        }
        return new JSONResponse($run->jsonSerialize());
    }

    /**
     * Stop a coworker's run in flight
     *
     * Cancels the run that is `running` or `pending` (a submitted batch).
     * The run is closed as `cancelled` and keeps what it processed so far; a
     * running one stops at its next item. The schedule is unchanged: pause or
     * disable the coworker to stop future runs.
     *
     * @param int $id Coworker ID
     *
     * 200: The cancelled run
     * 404: Coworker not found, or it has no run in flight
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    #[AnonRateLimit(limit: 20, period: 60)]
    #[OpenAPI]
    public function stop(int $id): JSONResponse {
        try {
            $run = $this->service->stop($id, (string)$this->userId);
        } catch (NoActiveRunException) {
            return $this->clientError(404, 'Coworker has no active run');
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        }
        return new JSONResponse($run->jsonSerialize());
    }

    /**
     * Get the run history for a coworker
     *
     * @param int $id Coworker ID
     * @param int $limit Maximum number of runs to return
     *
     * 200: List of runs, newest first
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, list<array<string, mixed>>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function runs(int $id, int $limit = 20): JSONResponse {
        try {
            $runs = $this->service->listRuns($id, (string)$this->userId, $limit);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        }
        return new JSONResponse(array_map(fn(CoworkerRun $r) => $r->jsonSerialize(), $runs));
    }

    /**
     * Get one run of a coworker, with its per-item log
     *
     * The run list's shape plus `log`: entries oldest first, at most the
     * newest 500. `item` is the file or object an entry is about, as a path
     * in the user's Files, or null.
     *
     * @param int $id Coworker ID
     * @param int $runId Run ID
     *
     * 200: The run with its log
     * 404: Coworker or run not found, or the run belongs to another coworker
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, coworkerId: int, userId: string, status: string, itemsTotal: int, itemsProcessed: int, itemsFailed: int, summary: ?string, error: ?string, startedAt: int, finishedAt: ?int, pendingBatchId: ?string, log: list<array{ts: int, level: string, message: string, item: ?string}>}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function runDetail(int $id, int $runId): JSONResponse {
        try {
            $run = $this->service->getRun($id, $runId, (string)$this->userId);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Run not found');
        }
        return new JSONResponse($run->jsonSerializeDetail());
    }

    /**
     * List the MCP tools an agent coworker may be allowed to call
     *
     * The tools of the instance's enabled MCP servers, which the chat offers
     * every user too. Only names and descriptions; a server that cannot be
     * reached is left out.
     *
     * 200: Tools, by name
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{name: string, description: string}>, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function tools(): JSONResponse {
        $all = $this->mcpClient?->getAllTools() ?? ['tools' => []];
        return new JSONResponse(array_map(
            static fn (array $t): array => ['name' => (string)($t['name'] ?? ''), 'description' => (string)($t['description'] ?? '')],
            array_values($all['tools']),
        ));
    }

    /**
     * List coworker templates and available task types
     *
     * Built-in templates first (`scope: builtin`), then the ones an admin
     * published for the instance (`scope: instance`).
     *
     * 200: Templates and task types
     *
     * @return JSONResponse<Http::STATUS_OK, array{templates: list<array<string, mixed>>, taskTypes: list<array{id: string, label: string, family: string}>}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function templates(): JSONResponse {
        return new JSONResponse([
            'templates' => $this->allTemplates(),
            'taskTypes' => $this->registry->describe(),
        ]);
    }

    /**
     * Create a coworker from a built-in or instance template
     *
     * @param string $templateId Template identifier
     *
     * 200: The created coworker
     * 400: Invalid coworker configuration
     * 404: Unknown template
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function createFromTemplate(string $templateId = ''): JSONResponse {
        $template = null;
        foreach ($this->allTemplates() as $candidate) {
            if (($candidate['id'] ?? null) === $templateId) {
                $template = $candidate;
                break;
            }
        }
        if ($template === null) {
            return $this->clientError(404, 'Unknown template: ' . $templateId);
        }

        // Allow the caller to override fields (e.g. input_path) at creation time.
        $data = array_merge($template, $this->bodyData());
        unset($data['id'], $data['scope']);
        try {
            $coworker = $this->service->create((string)$this->userId, $data);
        } catch (ValidationException $e) {
            // ValidationException messages are written by this app and safe to show.
            return $this->clientError(400, $e->getMessage());
        }
        return new JSONResponse($coworker->jsonSerialize());
    }

    /**
     * Get the coworker selected for the dashboard output widget
     *
     * 200: The selected coworker id (null when none chosen)
     *
     * @return JSONResponse<Http::STATUS_OK, array{coworkerId: int|null}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function getDashboardCoworker(): JSONResponse {
        $value = $this->config->getUserValue((string)$this->userId, 'aiquila', 'dashboard_coworker', '');
        return new JSONResponse(['coworkerId' => $value === '' ? null : (int)$value]);
    }

    /**
     * Set the coworker shown by the dashboard output widget
     *
     * @param int|null $coworkerId Coworker to display, or null to clear
     *
     * 200: The stored selection
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{coworkerId: int|null}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function setDashboardCoworker(?int $coworkerId = null): JSONResponse {
        if ($coworkerId === null) {
            $this->config->deleteUserValue((string)$this->userId, 'aiquila', 'dashboard_coworker');
            return new JSONResponse(['coworkerId' => null]);
        }
        try {
            $this->service->findForUser($coworkerId, (string)$this->userId);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        }
        $this->config->setUserValue((string)$this->userId, 'aiquila', 'dashboard_coworker', (string)$coworkerId);
        return new JSONResponse(['coworkerId' => $coworkerId]);
    }

    /**
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    private function togglePaused(int $id, bool $paused): JSONResponse {
        try {
            $coworker = $this->service->setPaused($id, (string)$this->userId, $paused);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        }
        return new JSONResponse($coworker->jsonSerialize());
    }

    /**
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    private function toggleActive(int $id, bool $active): JSONResponse {
        try {
            $coworker = $this->service->setActive($id, (string)$this->userId, $active);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        }
        return new JSONResponse($coworker->jsonSerialize());
    }

    /**
     * Whether the current user may use coworker triggers
     *
     * Triggers start a coworker when files in its input folder change, or
     * when its trigger URL is called. An administrator turns them on and
     * chooses who may use them.
     *
     * 200: The policy for the current user
     *
     * @return JSONResponse<Http::STATUS_OK, array{allowed: bool, debounceSeconds: int, maxRunsPerHour: int}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function triggerPolicy(): JSONResponse {
        return new JSONResponse([
            'allowed' => $this->triggerPolicy?->isAllowed((string)$this->userId) ?? false,
            'debounceSeconds' => CoworkerTriggerService::DEBOUNCE_SECONDS,
            'maxRunsPerHour' => CoworkerTriggerService::MAX_TRIGGERED_RUNS_PER_HOUR,
        ]);
    }

    /**
     * Create a trigger URL for a coworker
     *
     * Returns the URL and its secret. The secret is shown this once; only a
     * hash of it is stored. Calling this again replaces the secret, so the old
     * one stops working. Call the URL with `POST` and the secret as a bearer
     * token: `Authorization: Bearer <secret>`.
     *
     * @param int $id Coworker ID
     *
     * 200: The trigger URL and its secret
     * 403: Coworker triggers are not allowed for this account
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{url: string, token: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 10, period: 300)]
    #[OpenAPI]
    public function createTriggerUrl(int $id): JSONResponse {
        if ($this->triggers === null) {
            return $this->clientError(403, 'Coworker triggers are not available');
        }
        try {
            $coworker = $this->service->findForUser($id, (string)$this->userId);
            $this->service->assertTriggersAllowed($coworker);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        } catch (ValidationException $e) {
            return $this->clientError(403, $e->getMessage());
        }
        $token = $this->triggers->createToken($coworker);
        return new JSONResponse(['url' => $this->triggerUrl($id), 'token' => $token]);
    }

    /**
     * Remove a coworker's trigger URL
     *
     * @param int $id Coworker ID
     *
     * 200: The coworker, now without a trigger URL
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function deleteTriggerUrl(int $id): JSONResponse {
        try {
            $coworker = $this->service->findForUser($id, (string)$this->userId);
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Coworker not found');
        }
        $this->triggers?->revokeToken($coworker);
        return new JSONResponse($coworker->jsonSerialize());
    }

    private function triggerUrl(int $id): string {
        $path = '/apps/aiquila/api/coworkers/' . $id . '/trigger';
        return $this->urlGenerator === null
            ? $path
            : $this->urlGenerator->linkToRouteAbsolute('aiquila.coworker_trigger.trigger', ['id' => $id]);
    }

    /**
     * Get the coworker trigger policy
     *
     * 200: Whether triggers are on, and for whom
     *
     * @return JSONResponse<Http::STATUS_OK, array{enabled: bool, users: list<string>, groups: list<string>}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminTriggerPolicy(): JSONResponse {
        return new JSONResponse($this->triggerPolicy?->get() ?? ['enabled' => false, 'users' => [], 'groups' => []]);
    }

    /**
     * Set the coworker trigger policy
     *
     * Triggers are off until turned on. Once on, they are open to everyone
     * when both lists are empty, and otherwise to the users named and the
     * members of the groups named. Settings left out are kept.
     *
     * @param bool|null $enabled Whether coworker triggers are on
     * @param list<string>|null $users User IDs allowed to use them
     * @param list<string>|null $groups Group IDs whose members may use them
     *
     * 200: The saved policy
     *
     * @return JSONResponse<Http::STATUS_OK, array{enabled: bool, users: list<string>, groups: list<string>}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function setAdminTriggerPolicy(?bool $enabled = null, ?array $users = null, ?array $groups = null): JSONResponse {
        if ($this->triggerPolicy === null) {
            return new JSONResponse(['enabled' => false, 'users' => [], 'groups' => []]);
        }
        $this->triggerPolicy->set($enabled, $users, $groups);
        return new JSONResponse($this->triggerPolicy->get());
    }

    /**
     * List the coworker templates published for the instance
     *
     * 200: Instance templates
     *
     * @return JSONResponse<Http::STATUS_OK, list<array<string, mixed>>, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminTemplates(): JSONResponse {
        return new JSONResponse($this->instanceTemplates->list());
    }

    /**
     * Publish one of the admin's own coworkers as an instance template
     *
     * The template copies the coworker's task, schedule, input, output,
     * provider, model and options. Coworkers later made from it are copies too.
     *
     * @param int $coworkerId Coworker to publish (one of the caller's own)
     * @param string|null $title Template title (default: the coworker's)
     * @param string|null $description Template description (default: the coworker's)
     *
     * 200: The published template
     * 400: A field is too long
     * 404: Coworker not found
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function publishTemplate(int $coworkerId, ?string $title = null, ?string $description = null): JSONResponse {
        $error = InstructionsService::tooLong($title, InstructionsService::MAX_TITLE_LENGTH, 'Title')
            ?? InstructionsService::tooLong($description, InstructionsService::MAX_DESCRIPTION_LENGTH, 'Description');
        if ($error !== null) {
            return $this->clientError(400, $error);
        }
        try {
            $coworker = $this->service->findForUser($coworkerId, (string)$this->userId);
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Coworker not found');
        }
        return new JSONResponse($this->instanceTemplates->publish($coworker, $title, $description));
    }

    /**
     * Delete an instance coworker template
     *
     * Coworkers already made from it are not touched.
     *
     * @param string $templateId Template ID
     *
     * 200: Deleted
     * 404: Template not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function deleteTemplate(string $templateId): JSONResponse {
        if (!$this->instanceTemplates->delete($templateId)) {
            return $this->clientError(404, 'Template not found');
        }
        return new JSONResponse(['deleted' => true]);
    }

    /**
     * Built-in templates, then the instance ones.
     *
     * @return list<array<string, mixed>>
     */
    private function allTemplates(): array {
        $builtin = array_map(static fn (array $t): array => ['scope' => 'builtin'] + $t, $this->service->getTemplates());
        return array_merge($builtin, $this->instanceTemplates->list());
    }

    /**
     * Whitelisted request body fields for create/update.
     *
     * @return array<string, mixed>
     */
    private function bodyData(): array {
        $params = $this->request->getParams();
        $allowed = [
            'title', 'description', 'provider', 'model', 'task_type', 'cron_schedule',
            'input_type', 'input_path', 'output_type', 'output_path',
            'is_active', 'paused', 'options', 'custom_prompt', 'prompt_id', 'teamId',
            'scheduled', 'trigger_files',
        ];
        $data = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $params)) {
                $data[$key] = $params[$key];
            }
        }
        return $data;
    }
}
