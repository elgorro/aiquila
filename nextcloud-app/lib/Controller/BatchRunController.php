<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\BatchRunService;
use OCA\AIquila\Service\Exception\BatchRunLimitException;
use OCA\AIquila\Service\Exception\NoActiveRunException;
use OCA\AIquila\Service\Exception\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Manual batch runs: one operation over files and folders the user picked.
 *
 * Used by the "Process with AIquila…" action in Files and by the MCP server
 * (app password, hence NoCSRFRequired alongside NoAdminRequired).
 *
 * @psalm-import-type AIquilaBatchRun from \OCA\AIquila\ResponseDefinitions
 * @psalm-import-type AIquilaBatchRunDetail from \OCA\AIquila\ResponseDefinitions
 * @psalm-import-type AIquilaBatchOperation from \OCA\AIquila\ResponseDefinitions
 */
class BatchRunController extends Controller {
    use ErrorResponseTrait;
    use RequiresUserIdTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly BatchRunService $service,
        private readonly ?string $userId,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * What a manual run can do, and its limits
     *
     * `batchAvailable` is whether the user's provider has a batch API: only
     * then does `batch` mode cost half and take up to 24 hours. Without one,
     * every run is immediate.
     *
     * 200: Operations, modes and limits
     *
     * @return JSONResponse<Http::STATUS_OK, array{operations: list<AIquilaBatchOperation>, modes: list<string>, defaultMode: string, batchAvailable: bool, maxItems: int, maxRunsPerHour: int}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function operations(): JSONResponse {
        $batch = $this->service->batchAvailable($this->requireUserId());
        $operations = [];
        foreach ($this->service->operations() as $op) {
            $operations[] = ['id' => $op->getId(), 'label' => $op->getLabel(), 'options' => $op->optionNames()];
        }
        return new JSONResponse([
            'operations' => $operations,
            'modes' => BatchRunService::MODES,
            'defaultMode' => $batch ? BatchRunService::MODE_BATCH : BatchRunService::MODE_IMMEDIATE,
            'batchAvailable' => $batch,
            'maxItems' => BatchRunService::MAX_ITEMS,
            'maxRunsPerHour' => BatchRunService::MAX_RUNS_PER_HOUR,
        ]);
    }

    /**
     * List the user's manual runs
     *
     * @param int $limit Maximum number of runs, 1–100
     *
     * 200: Runs, newest first
     *
     * @return JSONResponse<Http::STATUS_OK, list<AIquilaBatchRun>, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function index(int $limit = 20): JSONResponse {
        $runs = $this->service->list($this->requireUserId(), $limit);
        return new JSONResponse(array_map(static fn (CoworkerRun $r): array => BatchRunService::serialize($r), $runs));
    }

    /**
     * Start a manual run over files and folders
     *
     * Checks that every id is readable by the user and queues the run; the
     * work starts on the next background-job tick. Folders are walked
     * recursively unless `options.recursive` is false. Files picked one by one
     * are always processed; files found in a folder are skipped when their
     * output is already up to date, unless `options.force` is true. Outputs go
     * beside each source, or into `options.outputFolder`.
     *
     * @param string $operation `summarize` or `translate`
     * @param array<string, mixed> $options Shared options (`recursive`, `force`, `outputFolder`, `maxBytesPerFile`, `mimeTypes`) plus the operation's own (`style` for summarize, `targetLanguage` for translate)
     * @param string|null $mode `batch` (half price, up to 24 hours) or `immediate` (full price); defaults to `batch` when the provider supports it
     * @param list<int> $fileIds Files to process
     * @param list<int> $folderIds Folders whose text and PDF documents to process
     *
     * 202: The queued run
     * 400: Invalid input, or more ids than a run takes (`limit` names the cap)
     * 404: A file or folder is not found or not readable
     * 429: Too many runs started in the last hour (`limit` names the cap)
     *
     * @return JSONResponse<Http::STATUS_ACCEPTED, AIquilaBatchRun, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_TOO_MANY_REQUESTS, array{error: string, errorId: string, limit?: int}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    // Each run bills LLM calls, so starting one is metered like a coworker run.
    #[UserRateLimit(limit: 20, period: 60)]
    #[AnonRateLimit(limit: 20, period: 60)]
    #[OpenAPI]
    public function create(string $operation, array $options = [], ?string $mode = null, array $fileIds = [], array $folderIds = []): JSONResponse {
        try {
            $run = $this->service->start(
                $this->requireUserId(),
                $operation,
                $options,
                $mode,
                $fileIds,
                $folderIds,
            );
        } catch (BatchRunLimitException $e) {
            $status = $e->kind === BatchRunLimitException::RATE ? 429 : 400;
            return new JSONResponse(['error' => $e->getMessage(), 'errorId' => '', 'limit' => $e->limit], $status);
        } catch (ValidationException $e) {
            return $this->clientError(400, $e->getMessage());
        } catch (NotFoundException $e) {
            // Written by BatchRunService: "File 123 not found".
            return $this->clientError(404, $e->getMessage());
        }
        return new JSONResponse(BatchRunService::serialize($run), 202);
    }

    /**
     * Get one manual run, with its log
     *
     * `pollIntervalSeconds` suggests when to ask again; it is null once the
     * run has ended. `outputs` lists the files written, as paths in the
     * user's Files. Log entries are oldest first, at most the newest 500.
     *
     * @param int $id Run ID
     *
     * 200: The run with its log
     * 404: Run not found
     *
     * @return JSONResponse<Http::STATUS_OK, AIquilaBatchRunDetail, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI]
    public function show(int $id): JSONResponse {
        try {
            $run = $this->service->get($id, $this->requireUserId());
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Run not found');
        }
        return new JSONResponse(BatchRunService::serialize($run) + ['log' => $run->getDecodedLog()]);
    }

    /**
     * Cancel a manual run
     *
     * A queued run never starts, a running one stops at its next file, and a
     * pending one has its batch cancelled with the provider. Files already
     * written stay; requests the provider already finished are billed.
     *
     * @param int $id Run ID
     *
     * 200: The cancelled run
     * 404: Run not found
     * 409: The run has already ended
     *
     * @return JSONResponse<Http::STATUS_OK, AIquilaBatchRun, array{}>|JSONResponse<Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    #[AnonRateLimit(limit: 20, period: 60)]
    #[OpenAPI]
    public function cancel(int $id): JSONResponse {
        try {
            $run = $this->service->cancel($id, $this->requireUserId());
        } catch (NoActiveRunException) {
            // Before DoesNotExistException, which it extends.
            return $this->clientError(409, 'The run has already ended');
        } catch (DoesNotExistException) {
            return $this->clientError(404, 'Run not found');
        }
        return new JSONResponse(BatchRunService::serialize($run));
    }
}
