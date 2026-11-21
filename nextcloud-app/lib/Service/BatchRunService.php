<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\BackgroundJob\BatchRunJob;
use OCA\AIquila\Batch\BatchContext;
use OCA\AIquila\Batch\BatchDocumentRunner;
use OCA\AIquila\Batch\BatchOperation;
use OCA\AIquila\Batch\SummarizeOperation;
use OCA\AIquila\Batch\TranslateOperation;
use OCA\AIquila\Cowork\UserPath;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCA\AIquila\Service\Exception\BatchRunLimitException;
use OCA\AIquila\Service\Exception\NoActiveRunException;
use OCA\AIquila\Service\Exception\RunCancelledException;
use OCA\AIquila\Service\Exception\ValidationException;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Manual batch runs: one operation over files and folders a user picked, run
 * once, with no coworker behind it.
 *
 * A manual run is a row on `aiquila_coworker_runs` with `coworker_id` 0 and
 * `started_by` `selection`. It shares the run log, the resume state and the
 * cancel semantics with coworker runs, and the pipeline itself with the docs
 * coworkers ({@see BatchDocumentRunner}).
 *
 * Lifecycle: {@see start()} checks the input and queues the run →
 * {@see BatchRunJob} calls {@see execute()}, which either works through the
 * files (`immediate`) or submits a batch and leaves the run `pending` →
 * {@see \OCA\AIquila\BackgroundJob\CoworkerBatchPollJob} calls {@see resume()}
 * until the batch has ended.
 *
 * @psalm-import-type BatchRunResult from BatchDocumentRunner
 * @psalm-import-type BatchSkip from BatchDocumentRunner
 * @psalm-import-type AIquilaBatchRun from \OCA\AIquila\ResponseDefinitions
 */
class BatchRunService {
    /** Files per run: the same cap as a coworker run. */
    public const MAX_ITEMS = BatchDocumentRunner::MAX_ITEMS_PER_RUN;

    /** Manual runs a user may start per hour: the coworker trigger cap. */
    public const MAX_RUNS_PER_HOUR = CoworkerTriggerService::MAX_TRIGGERED_RUNS_PER_HOUR;

    public const MODE_BATCH = 'batch';
    public const MODE_IMMEDIATE = 'immediate';
    public const MODES = [self::MODE_BATCH, self::MODE_IMMEDIATE];

    /**
     * Skipped files logged one by one; past this the log gets one line with
     * the rest of the count, so a folder of 5,000 photos does not push every
     * other entry out of the bounded run log.
     */
    private const SKIP_LOG_LIMIT = 100;

    /** Suggested seconds between status polls, per status; null once a run has ended. */
    private const POLL_INTERVALS = [
        CoworkerRun::STATUS_QUEUED => 30,
        CoworkerRun::STATUS_RUNNING => 10,
        // The poll job looks at a pending batch every five minutes; polling
        // the run faster than that cannot show anything new.
        CoworkerRun::STATUS_PENDING => 300,
    ];

    public function __construct(
        private readonly CoworkerRunMapper $runMapper,
        private readonly BatchDocumentRunner $runner,
        private readonly IRootFolder $rootFolder,
        private readonly LLMProviderFactory $providerFactory,
        private readonly IJobList $jobList,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
        private readonly ?InstructionsService $instructions = null,
        private readonly ?BatchRunNotifications $notifications = null,
    ) {
    }

    /** @return array<string, BatchOperation> id => operation */
    public function operations(): array {
        $ops = [];
        foreach ([new SummarizeOperation(), new TranslateOperation()] as $op) {
            $ops[$op->getId()] = $op;
        }
        return $ops;
    }

    /** Whether the user's provider has a batch API, so `batch` mode means half price. */
    public function batchAvailable(string $userId): bool {
        try {
            $provider = $this->providerFactory->getProviderForUser($userId, null);
        } catch (\Throwable) {
            return false;
        }
        return $provider instanceof ClaudeSDKService && $provider->supportsBatch();
    }

    /**
     * Check a request and queue the run. Returns the queued run at once; the
     * work starts on the next background-job tick.
     *
     * @param array<string, mixed> $options
     * @param array<array-key, mixed> $fileIds
     * @param array<array-key, mixed> $folderIds
     * @throws ValidationException for input the caller got wrong (400)
     * @throws NotFoundException for an id the user cannot read (404)
     * @throws BatchRunLimitException over the item or hourly cap (400 / 429)
     */
    public function start(string $userId, string $operation, array $options, ?string $mode, array $fileIds, array $folderIds): CoworkerRun {
        $op = $this->operations()[$operation] ?? null;
        if ($op === null) {
            throw new ValidationException('operation must be one of: ' . implode(', ', array_keys($this->operations())));
        }
        try {
            BatchDocumentRunner::validateOptions($options);
            $op->validateOptions($options);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        }
        if ($mode !== null && !in_array($mode, self::MODES, true)) {
            throw new ValidationException('mode must be one of: ' . implode(', ', self::MODES));
        }
        $mode ??= $this->batchAvailable($userId) ? self::MODE_BATCH : self::MODE_IMMEDIATE;

        $fileIds = self::ids($fileIds, 'fileIds');
        $folderIds = self::ids($folderIds, 'folderIds');
        if ($fileIds === [] && $folderIds === []) {
            throw new ValidationException('Select at least one file or folder');
        }
        foreach (['fileIds' => $fileIds, 'folderIds' => $folderIds] as $field => $ids) {
            if (count($ids) > self::MAX_ITEMS) {
                throw new BatchRunLimitException(
                    sprintf('%d entries in %s; a run takes at most %d files', count($ids), $field, self::MAX_ITEMS),
                    BatchRunLimitException::ITEMS,
                    self::MAX_ITEMS,
                );
            }
        }

        // Every input must be readable by this user, checked now so the caller
        // hears about it rather than finding a failed run later.
        foreach ($fileIds as $id) {
            $this->assertReadable($userId, $id, File::class);
        }
        foreach ($folderIds as $id) {
            $this->assertReadable($userId, $id, Folder::class);
        }
        if (isset($options['outputFolder'])) {
            $this->assertWritableOutputFolder($userId, (string)$options['outputFolder']);
        }

        $now = $this->timeFactory->getTime();
        $started = $this->runMapper->countManualSince($userId, $now - 3600);
        if ($started >= self::MAX_RUNS_PER_HOUR) {
            throw new BatchRunLimitException(
                sprintf('You started %d runs in the last hour; the limit is %d per hour', $started, self::MAX_RUNS_PER_HOUR),
                BatchRunLimitException::RATE,
                self::MAX_RUNS_PER_HOUR,
            );
        }

        $run = new CoworkerRun();
        $run->setCoworkerId(0);
        $run->setUserId($userId);
        $run->setStatus(CoworkerRun::STATUS_QUEUED);
        $run->setStartedAt($now);
        $run->setStartedBy(CoworkerRun::STARTED_BY_SELECTION);
        $run->setTaskType($op->getId());
        $run->setDecodedState([
            'queued' => [
                'options' => $options,
                'mode' => $mode,
                'fileIds' => $fileIds,
                'folderIds' => $folderIds,
            ],
        ]);
        $run->appendLog(CoworkerRun::LOG_INFO, sprintf(
            'Queued: %s over %d file(s) and %d folder(s), %s mode',
            strtolower($op->getLabel()),
            count($fileIds),
            count($folderIds),
            $mode,
        ));
        $run = $this->runMapper->insert($run);

        $this->jobList->add(BatchRunJob::class, ['runId' => $run->getId()]);
        return $run;
    }

    /** @throws DoesNotExistException when it is not one of the user's manual runs */
    public function get(int $id, string $userId): CoworkerRun {
        return $this->runMapper->findManual($id, $userId);
    }

    /** @return list<CoworkerRun> newest first */
    public function list(string $userId, int $limit = 20): array {
        return $this->runMapper->findManualByUser($userId, $limit);
    }

    /**
     * Cancel a manual run that has not ended. A queued run never starts, a
     * running one stops at its next file, a pending one has its batch
     * cancelled with the provider (best effort; finished requests still bill).
     *
     * @throws DoesNotExistException
     * @throws NoActiveRunException when the run has already ended
     */
    public function cancel(int $id, string $userId): CoworkerRun {
        $run = $this->runMapper->findManual($id, $userId);
        $this->cancelEntity($run);
        return $run;
    }

    private function cancelEntity(CoworkerRun $run): void {
        $status = $run->getStatus();
        if (!isset(self::POLL_INTERVALS[$status])) {
            throw new NoActiveRunException('Run ' . $run->getId() . ' has already ended');
        }
        $batchId = $status === CoworkerRun::STATUS_PENDING ? $run->getPendingBatchId() : null;

        $run->setStatus(CoworkerRun::STATUS_CANCELLED);
        $run->setFinishedAt($this->timeFactory->getTime());
        $run->setState(null);
        $run->appendLog(CoworkerRun::LOG_WARN, sprintf('Cancelled after %d/%d item(s)', $run->getItemsProcessed(), $run->getItemsTotal()));
        if ($batchId !== null) {
            [$level, $message] = $this->cancelBatch($run->getUserId(), $batchId);
            $run->appendLog($level, $message);
        }
        $this->runMapper->update($run);
    }

    /**
     * @return array{0: 'info'|'warn', 1: string}
     */
    private function cancelBatch(string $userId, string $batchId): array {
        try {
            $provider = $this->providerFactory->getProviderForUser($userId, null);
            if ($provider instanceof ClaudeSDKService && $provider->cancelBatch($batchId, $userId)) {
                return [CoworkerRun::LOG_INFO, 'Batch ' . $batchId . ' cancelled'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila batch run: could not cancel batch', ['batch' => $batchId, 'error' => $e->getMessage()]);
        }
        return [CoworkerRun::LOG_WARN, 'Batch ' . $batchId . ' could not be cancelled; its results will be ignored'];
    }

    /**
     * Run a queued run: collect its files, then submit a batch or work
     * through them. Never throws for the run's own failures; they end up on
     * the run. A run that is no longer queued (cancelled, or taken by another
     * worker) is left alone.
     */
    public function execute(int $runId): void {
        if (!$this->runMapper->claimQueued($runId)) {
            return;
        }
        $run = $this->runMapper->findById($runId);

        try {
            $op = $this->operationOf($run);
            $queued = $run->getDecodedState()['queued'] ?? null;
            if (!is_array($queued)) {
                throw new \RuntimeException('The queued run carries no input');
            }
            /** @var array<string, mixed> $options */
            $options = is_array($queued['options'] ?? null) ? $queued['options'] : [];
            $mode = ($queued['mode'] ?? self::MODE_BATCH) === self::MODE_IMMEDIATE ? self::MODE_IMMEDIATE : self::MODE_BATCH;
            $ctx = $this->context($run->getUserId(), $op, $options, $mode === self::MODE_BATCH);

            [$files, $folders, $skipped] = $this->resolveInputs($run, $queued);
            $selection = $this->runner->collectSelection($ctx, $files, $folders);
            foreach ($selection['skipped'] as $i => $skip) {
                if ($i < self::SKIP_LOG_LIMIT) {
                    $run->appendLog(CoworkerRun::LOG_WARN, 'Skipped: ' . $skip['reason'], $skip['item']);
                }
                $skipped[] = $skip;
            }
            if (count($selection['skipped']) > self::SKIP_LOG_LIMIT) {
                $run->appendLog(CoworkerRun::LOG_WARN, sprintf(
                    'Skipped %d more file(s) that are not text documents or cannot be written beside',
                    count($selection['skipped']) - self::SKIP_LOG_LIMIT,
                ));
            }
            if ($selection['capped']) {
                $run->appendLog(CoworkerRun::LOG_WARN, sprintf(
                    'Stopped at the cap of %d files. Run it again for the rest: finished files are skipped.',
                    self::MAX_ITEMS,
                ));
            }
            $progress = $this->progressCallback($run);
            $progress(0, count($selection['files']));

            if ($selection['files'] === []) {
                $result = [
                    'itemsTotal' => 0,
                    'itemsProcessed' => 0,
                    'itemsSkipped' => count($skipped),
                    'summary' => 'Nothing to do: no text documents that still need work in the selection.'
                        . BatchDocumentRunner::describeSkips($skipped),
                ];
            } else {
                $result = $this->runner->process($ctx, $run, $selection['files'], $progress, $skipped);
            }
        } catch (RunCancelledException) {
            $this->finishCancelled($run);
            return;
        } catch (\Throwable $e) {
            $this->finish($run, null, $e);
            return;
        }
        $this->finish($run, $result, null);
    }

    /**
     * Continue a manual run that is waiting on a batch. Never throws: a
     * failure is captured on the run, because the caller is a background job.
     */
    public function resume(CoworkerRun $run): CoworkerRun {
        try {
            $state = $run->getDecodedState();
            /** @var array<string, mixed> $options */
            $options = is_array($state['options'] ?? null) ? $state['options'] : [];
            $ctx = $this->context($run->getUserId(), $this->operationOf($run), $options, true);
            $result = $this->runner->resume($ctx, $run, $state, $this->progressCallback($run));
        } catch (RunCancelledException) {
            return $this->finishCancelled($run);
        } catch (\Throwable $e) {
            return $this->finish($run, null, $e);
        }
        return $this->finish($run, $result, null);
    }

    /** Cancel a user's open manual runs, before the user is deleted. */
    public function stopForUser(string $userId): void {
        foreach ($this->runMapper->findOpenManualByUser($userId) as $run) {
            try {
                $this->cancelEntity($run);
            } catch (\Throwable $e) {
                $this->logger->warning('AIquila batch run: could not cancel a run of a deleted user', [
                    'run' => $run->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function removeForUser(string $userId): void {
        $this->runMapper->deleteManualByUser($userId);
    }

    /**
     * The API shape of a manual run.
     *
     * @return AIquilaBatchRun
     */
    public static function serialize(CoworkerRun $run): array {
        $status = $run->getStatus();
        if (!in_array($status, ['queued', 'running', 'pending', 'success', 'error', 'cancelled'], true)) {
            $status = 'error';
        }
        return [
            'id' => $run->getId(),
            'operation' => (string)$run->getTaskType(),
            'status' => $status,
            'itemsTotal' => $run->getItemsTotal(),
            'itemsProcessed' => $run->getItemsProcessed(),
            'itemsFailed' => $run->getItemsFailed(),
            'itemsSkipped' => $run->getItemsSkipped(),
            'summary' => $run->getSummary(),
            'error' => $run->getError(),
            'startedAt' => $run->getStartedAt(),
            'finishedAt' => $run->getFinishedAt(),
            'pendingBatchId' => $run->getPendingBatchId(),
            'outputs' => $run->getDecodedOutputs(),
            'pollIntervalSeconds' => self::POLL_INTERVALS[$status] ?? null,
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * @param array<array-key, mixed> $ids
     * @return list<int>
     */
    private static function ids(array $ids, string $field): array {
        $clean = [];
        foreach ($ids as $id) {
            if (is_string($id) && ctype_digit($id)) {
                $id = (int)$id;
            }
            if (!is_int($id) || $id <= 0) {
                throw new ValidationException($field . ' must be a list of positive file ids');
            }
            $clean[$id] = $id;
        }
        return array_values($clean);
    }

    /**
     * Check that the user can read a node of the expected kind.
     *
     * @param class-string<File>|class-string<Folder> $kind
     * @throws NotFoundException naming the id, for a node the user cannot see or read
     * @throws ValidationException for a file passed as a folder or the other way round
     */
    private function assertReadable(string $userId, int $id, string $kind): void {
        $node = $this->nodeById($userId, $id);
        if ($node === null || !$node->isReadable()) {
            // One answer for "does not exist" and "not yours": no probing.
            throw new NotFoundException(sprintf('%s %d not found', $kind === File::class ? 'File' : 'Folder', $id));
        }
        if (!$node instanceof $kind) {
            throw new ValidationException($kind === File::class
                ? sprintf('%d is a folder; pass it in folderIds', $id)
                : sprintf('%d is a file; pass it in fileIds', $id));
        }
    }

    private function nodeById(string $userId, int $id): ?Node {
        $nodes = $this->rootFolder->getUserFolder($userId)->getById($id);
        return $nodes[0] ?? null;
    }

    /**
     * The output folder must be one the user can create files in. It is
     * created when it does not exist yet, so that is checked now too.
     *
     * @throws ValidationException
     */
    private function assertWritableOutputFolder(string $userId, string $path): void {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        try {
            $node = $userFolder->get($path);
        } catch (NotFoundException) {
            try {
                $userFolder->newFolder($path);
                return;
            } catch (\Throwable) {
                throw new ValidationException('outputFolder ' . $path . ' cannot be created');
            }
        }
        if (!$node instanceof Folder || !$node->isCreatable()) {
            throw new ValidationException('outputFolder ' . $path . ' is not a folder you can write to');
        }
    }

    /**
     * The queued input, resolved again: a file can have been deleted or
     * unshared since the run was started. Each one that is gone is logged
     * and returned as a skip.
     *
     * @param array<array-key, mixed> $queued
     * @return array{0: list<File>, 1: list<Folder>, 2: list<BatchSkip>}
     */
    private function resolveInputs(CoworkerRun $run, array $queued): array {
        $files = [];
        $folders = [];
        $gone = [];
        foreach (['fileIds' => File::class, 'folderIds' => Folder::class] as $field => $kind) {
            $ids = is_array($queued[$field] ?? null) ? $queued[$field] : [];
            foreach ($ids as $id) {
                $node = $this->nodeById($run->getUserId(), (int)$id);
                if ($node instanceof File && $kind === File::class && $node->isReadable()) {
                    $files[] = $node;
                } elseif ($node instanceof Folder && $kind === Folder::class && $node->isReadable()) {
                    $folders[] = $node;
                } else {
                    $skip = ['item' => ($kind === File::class ? 'file ' : 'folder ') . (int)$id, 'reason' => 'No longer available'];
                    $run->appendLog(CoworkerRun::LOG_WARN, 'Skipped: ' . $skip['reason'], $skip['item']);
                    $gone[] = $skip;
                }
            }
        }
        return [$files, $folders, $gone];
    }

    private function operationOf(CoworkerRun $run): BatchOperation {
        $op = $this->operations()[(string)$run->getTaskType()] ?? null;
        if ($op === null) {
            throw new \RuntimeException('Unknown operation ' . (string)$run->getTaskType());
        }
        return $op;
    }

    /** @param array<string, mixed> $options */
    private function context(string $userId, BatchOperation $op, array $options, bool $allowBatch): BatchContext {
        return new BatchContext(
            userId: $userId,
            operation: $op,
            options: $options,
            requestOptions: function (LLMProviderInterface $_provider) use ($userId): array {
                $request = LLMProviderInterface::TASK_OPTIONS;
                // Background work like a coworker's: the same instance
                // instructions scope applies.
                $system = $this->instructions?->withInstance(InstructionsService::SCOPE_COWORKERS, $userId, null);
                if ($system !== null && $system !== '') {
                    $request['system'] = $system;
                }
                return $request;
            },
            providerId: null,
            outputFolder: (string)($options['outputFolder'] ?? ''),
            logContext: ['user' => $userId],
            allowBatch: $allowBatch,
            requireWritableOutput: true,
        );
    }

    /**
     * Persist progress, and stop once the run was cancelled from another
     * request. Same contract as CoworkerService's: the status is read fresh.
     *
     * @return callable(int, int): void
     */
    private function progressCallback(CoworkerRun $run): callable {
        return function (int $processed, int $total) use ($run): void {
            $run->setItemsProcessed($processed);
            $run->setItemsTotal($total);
            $this->runMapper->update($run);
            $status = $this->runMapper->findStatus($run->getId());
            if ($status === CoworkerRun::STATUS_CANCELLED || $status === null) {
                throw new RunCancelledException('Run ' . $run->getId() . ' was cancelled');
            }
        };
    }

    private function finishCancelled(CoworkerRun $run): CoworkerRun {
        $run->setStatus(CoworkerRun::STATUS_CANCELLED);
        $run->setState(null);
        $run->setFinishedAt($this->timeFactory->getTime());
        $run->appendLog(CoworkerRun::LOG_WARN, sprintf('Cancelled after %d/%d item(s)', $run->getItemsProcessed(), $run->getItemsTotal()));
        $this->runMapper->update($run);
        return $run;
    }

    /**
     * Persist the outcome of one execute() or resume() step, as
     * CoworkerService::finishRun() does for coworker runs.
     *
     * @param BatchRunResult|null $result
     */
    private function finish(CoworkerRun $run, ?array $result, ?\Throwable $error): CoworkerRun {
        if ($error !== null) {
            $run->setStatus(CoworkerRun::STATUS_ERROR);
            $run->setError($error->getMessage());
            $run->setState(null);
            $run->appendLog(CoworkerRun::LOG_ERROR, 'Run failed: ' . $error->getMessage());
            $this->logger->error('AIquila batch run: failed', ['run' => $run->getId(), 'error' => $error->getMessage()]);
        } elseif ($result !== null && ($result['pending'] ?? false) === true) {
            $this->recordCounts($run, $result);
            $run->setStatus(CoworkerRun::STATUS_PENDING);
            $run->setDecodedState($result['state'] ?? []);
            $this->runMapper->update($run);
            return $run;
        } elseif ($this->runMapper->findStatus($run->getId()) === CoworkerRun::STATUS_CANCELLED) {
            $this->recordCounts($run, $result);
            return $this->finishCancelled($run);
        } else {
            $this->recordCounts($run, $result);
            $failed = $run->getItemsFailed();
            $status = $failed > 0 && $run->getItemsProcessed() === 0
                ? CoworkerRun::STATUS_ERROR
                : CoworkerRun::STATUS_SUCCESS;
            $message = match (true) {
                $failed === 0 => null,
                $status === CoworkerRun::STATUS_ERROR => sprintf('All %d item(s) failed', $failed),
                default => sprintf('%d of %d item(s) failed', $failed, $run->getItemsTotal()),
            };
            $run->setStatus($status);
            $run->setError($message);
            $run->setState(null);
            if ($message !== null) {
                $run->appendLog($status === CoworkerRun::STATUS_ERROR ? CoworkerRun::LOG_ERROR : CoworkerRun::LOG_WARN, $message);
            }
        }

        $run->setFinishedAt($this->timeFactory->getTime());
        $this->runMapper->update($run);
        $this->notifications?->runFinished($run);
        return $run;
    }

    /** @param BatchRunResult|null $result */
    private function recordCounts(CoworkerRun $run, ?array $result): void {
        $run->setItemsTotal((int)($result['itemsTotal'] ?? 0));
        $run->setItemsProcessed((int)($result['itemsProcessed'] ?? 0));
        $run->setItemsFailed((int)($result['itemsFailed'] ?? 0));
        $run->setItemsSkipped((int)($result['itemsSkipped'] ?? 0));
        $run->setSummary((string)($result['summary'] ?? ''));
        if (isset($result['outputs']) && $result['outputs'] !== []) {
            $run->setDecodedOutputs($result['outputs']);
        }
    }
}
