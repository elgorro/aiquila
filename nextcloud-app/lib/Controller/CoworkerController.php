<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\CoworkerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * REST API for cowork tasks — persistent scheduled AI jobs.
 *
 * Consumed by the Cowork UI and by the MCP server (Basic Auth), hence
 * NoCSRFRequired alongside NoAdminRequired.
 */
class CoworkerController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CoworkerService $service,
        private readonly CoworkerTaskRegistry $registry,
        private readonly ?string $userId,
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
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function show(int $id): JSONResponse {
        try {
            $coworker = $this->service->findForUser($id, (string)$this->userId);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Coworker not found'], 404);
        }
        return new JSONResponse($coworker->jsonSerialize());
    }

    /**
     * Create a new coworker
     *
     * 200: The created coworker
     * 400: Invalid coworker configuration
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function create(): JSONResponse {
        try {
            $coworker = $this->service->create((string)$this->userId, $this->bodyData());
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
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
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function update(int $id): JSONResponse {
        try {
            $coworker = $this->service->update($id, (string)$this->userId, $this->bodyData());
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Coworker not found'], 404);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
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
     * @return JSONResponse<Http::STATUS_OK, array{deleted: bool}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function destroy(int $id): JSONResponse {
        try {
            $this->service->delete($id, (string)$this->userId);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Coworker not found'], 404);
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
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
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
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
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
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
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
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
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
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function run(int $id): JSONResponse {
        try {
            $run = $this->service->runNow($id, (string)$this->userId);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Coworker not found'], 404);
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
     * @return JSONResponse<Http::STATUS_OK, list<array<string, mixed>>, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function runs(int $id, int $limit = 20): JSONResponse {
        try {
            $runs = $this->service->listRuns($id, (string)$this->userId, $limit);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Coworker not found'], 404);
        }
        return new JSONResponse(array_map(fn(CoworkerRun $r) => $r->jsonSerialize(), $runs));
    }

    /**
     * List built-in coworker templates and available task types
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
            'templates' => $this->service->getTemplates(),
            'taskTypes' => $this->registry->describe(),
        ]);
    }

    /**
     * Create a coworker from a built-in template
     *
     * @param string $templateId Template identifier
     *
     * 200: The created coworker
     * 400: Invalid coworker configuration
     * 404: Unknown template
     *
     * @return JSONResponse<Http::STATUS_OK, array<string, mixed>, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
     *        |JSONResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function createFromTemplate(string $templateId = ''): JSONResponse {
        $template = null;
        foreach ($this->service->getTemplates() as $candidate) {
            if (($candidate['id'] ?? null) === $templateId) {
                $template = $candidate;
                break;
            }
        }
        if ($template === null) {
            return new JSONResponse(['error' => 'Unknown template: ' . $templateId], 404);
        }

        // Allow the caller to override fields (e.g. input_path) at creation time.
        $data = array_merge($template, $this->bodyData());
        unset($data['id']);
        try {
            $coworker = $this->service->create((string)$this->userId, $data);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
        return new JSONResponse($coworker->jsonSerialize());
    }

    private function togglePaused(int $id, bool $paused): JSONResponse {
        try {
            $coworker = $this->service->setPaused($id, (string)$this->userId, $paused);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Coworker not found'], 404);
        }
        return new JSONResponse($coworker->jsonSerialize());
    }

    private function toggleActive(int $id, bool $active): JSONResponse {
        try {
            $coworker = $this->service->setActive($id, (string)$this->userId, $active);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'Coworker not found'], 404);
        }
        return new JSONResponse($coworker->jsonSerialize());
    }

    /**
     * Whitelisted request body fields for create/update.
     *
     * @return array<string, mixed>
     */
    private function bodyData(): array {
        $params = $this->request->getParams();
        $allowed = [
            'title', 'description', 'model', 'task_type', 'cron_schedule',
            'input_type', 'input_path', 'output_type', 'output_path',
            'is_active', 'paused', 'options',
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
