<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Service\Exception\NoActiveRunException;
use OCA\AIquila\Service\Exception\RunCancelledException;
use OCA\AIquila\Service\Exception\ValidationException;
use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Cowork\ResumableCoworkerTaskType;
use OCA\AIquila\Cowork\CronSchedule;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCA\AIquila\Db\PromptMapper;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates coworkers: CRUD, scheduling (next-run computation), pause/resume,
 * and execution via the task-type registry. Used by the controller (per-user
 * actions) and the background job (due execution).
 */
class CoworkerService {

    public function __construct(
        private readonly CoworkerMapper $mapper,
        private readonly CoworkerRunMapper $runMapper,
        private readonly CoworkerTaskRegistry $registry,
        private readonly LLMProviderFactory $providerFactory,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
        private readonly ?PromptMapper $prompts = null,
    ) {
    }

    /**
     * Built-in coworker templates offered as one-click setups in the UI / MCP.
     *
     * @return list<array<string, mixed>>
     */
    public function getTemplates(): array {
        $vision = [
            'task_type' => 'vision:classify',
            'cron_schedule' => '0 3 * * *',
            'input_type' => 'folder',
            'input_path' => '/Photos',
            'output_type' => 'system_tags',
            'options' => ['maxTags' => 8, 'recursive' => true],
        ];
        // The docs family goes out through the Batch API on the Anthropic
        // provider, so these run at half price — which is why they default to
        // a nightly schedule over a whole folder rather than on demand.
        $docs = [
            'cron_schedule' => '0 2 * * *',
            'input_type' => 'folder',
            'input_path' => '/Documents',
            'output_type' => 'files',
            'provider' => 'anthropic',
        ];

        return [
            array_merge($vision, [
                'id' => 'classify-images-claude',
                'title' => 'Classify images — Claude vision',
                'description' => 'Tag images in a folder using Claude vision, nightly.',
                'provider' => 'anthropic',
                // Tagging is routine work: low effort keeps it cheap.
                'options' => $vision['options'] + ['effort' => 'low'],
            ]),
            array_merge($vision, [
                'id' => 'classify-images-mistral',
                'title' => 'Classify images — Mistral vision',
                'description' => 'Tag images in a folder using Mistral vision, nightly.',
                'provider' => 'mistral',
            ]),
            array_merge($docs, [
                'id' => 'summarize-documents-claude',
                'title' => 'Summarize documents — Claude',
                'description' => 'Write a summary beside every document in a folder, nightly.',
                'task_type' => 'docs:summarize',
                'output_path' => '/Documents/Summaries',
                'options' => ['style' => 'brief', 'recursive' => true, 'effort' => 'medium'],
            ]),
            [
                'id' => 'daily-mail-digest-claude',
                'title' => 'Daily mail digest — Claude with tools',
                'description' => 'Every weekday morning, summarise unread mail into a note. Pick the MCP tools it may use.',
                'task_type' => 'agent:run',
                'cron_schedule' => '0 7 * * 1-5',
                'input_type' => 'none',
                'output_type' => 'file',
                'output_path' => '/Notes/daily.md',
                'provider' => 'anthropic',
                'custom_prompt' => 'Summarise my unread mail from the last day as a short Markdown list: sender, subject and one line on what it needs from me. Put anything urgent first.',
                // An empty allowlist runs the prompt without tools; the owner
                // names the mail tools of their own MCP servers.
                'options' => ['tools' => [], 'effort' => 'medium'],
            ],
            array_merge($docs, [
                'id' => 'translate-documents-claude',
                'title' => 'Translate documents — Claude',
                'description' => 'Translate every document in a folder into one language, nightly.',
                'task_type' => 'docs:translate',
                'options' => ['targetLanguage' => 'German', 'recursive' => true, 'effort' => 'medium'],
            ]),
        ];
    }

    /**
     * @throws DoesNotExistException
     */
    public function findForUser(int $id, string $userId): Coworker {
        return $this->mapper->findByIdAndUser($id, $userId);
    }

    /**
     * @return list<Coworker>
     */
    public function listForUser(string $userId): array {
        return $this->mapper->findAllByUser($userId);
    }

    /**
     * @param array<string, mixed> $data
     * @param string|null $ownerApp App id when created by another app via the
     *   public ICoworkManager; null for user-owned (UI / MCP) coworkers.
     */
    public function create(string $userId, array $data, ?string $ownerApp = null): Coworker {
        $now = $this->timeFactory->getTime();
        $coworker = new Coworker();
        $coworker->setUserId($userId);
        $coworker->setOwnerApp($ownerApp);
        $coworker->setCreatedAt($now);
        $this->applyData($coworker, $data);
        $coworker->setUpdatedAt($now);
        $this->refreshNextRun($coworker);
        return $this->mapper->insert($coworker);
    }

    /**
     * @param array<string, mixed> $data
     * @throws DoesNotExistException
     */
    public function update(int $id, string $userId, array $data): Coworker {
        return $this->applyUpdate($this->mapper->findByIdAndUser($id, $userId), $data);
    }

    /**
     * @throws DoesNotExistException
     */
    public function delete(int $id, string $userId): void {
        $this->deleteEntity($this->mapper->findByIdAndUser($id, $userId));
    }

    /**
     * @throws DoesNotExistException
     */
    public function setPaused(int $id, string $userId, bool $paused): Coworker {
        $coworker = $this->mapper->findByIdAndUser($id, $userId);
        $coworker->setPaused($paused ? 1 : 0);
        return $this->persistScheduled($coworker);
    }

    /**
     * @throws DoesNotExistException
     */
    public function setActive(int $id, string $userId, bool $active): Coworker {
        $coworker = $this->mapper->findByIdAndUser($id, $userId);
        $coworker->setIsActive($active ? 1 : 0);
        return $this->persistScheduled($coworker);
    }

    /**
     * @return list<CoworkerRun>
     * @throws DoesNotExistException
     */
    public function listRuns(int $id, string $userId, int $limit = 20): array {
        $this->mapper->findByIdAndUser($id, $userId); // authorize
        return $this->runMapper->findByCoworker($id, $userId, $limit);
    }

    /**
     * One run of a coworker, log included.
     *
     * @throws DoesNotExistException when the coworker is not the user's, or the run is not this coworker's
     */
    public function getRun(int $id, int $runId, string $userId): CoworkerRun {
        $this->mapper->findByIdAndUser($id, $userId); // authorize
        $run = $this->runMapper->findByIdAndUser($runId, $userId);
        if ($run->getCoworkerId() !== $id) {
            throw new DoesNotExistException('Run ' . $runId . ' does not belong to coworker ' . $id);
        }
        return $run;
    }

    /**
     * Stop the user's coworker's run in flight. See stopEntity().
     *
     * @throws NoActiveRunException when the coworker has nothing running or pending
     * @throws DoesNotExistException when the coworker is not the user's
     */
    public function stop(int $id, string $userId): CoworkerRun {
        return $this->stopEntity($this->mapper->findByIdAndUser($id, $userId));
    }

    /**
     * Cancel the coworker's run in flight and return it, closed as
     * `cancelled` with what it had processed so far. The schedule is left
     * alone: pause or disable stop future runs, this stops the current one.
     *
     * - A `running` run is marked here; the worker executing it reads the
     *   status in its progress callback and stops at the next item boundary
     *   (see progressCallback()). A worker that died left the row `running`;
     *   marking it is all the closing it gets.
     * - A `pending` run's batch is cancelled with the provider, best effort.
     *   The poll job only resumes `pending` runs, so whatever the batch still
     *   returns is ignored. No worker will close this run, so the coworker's
     *   schedule moves on here; otherwise the overdue next run would start a
     *   fresh one on the next tick.
     *
     * @throws NoActiveRunException
     */
    public function stopEntity(Coworker $coworker): CoworkerRun {
        $run = $this->runMapper->findActiveForCoworker($coworker->getId());
        if ($run === null) {
            throw new NoActiveRunException('Coworker ' . $coworker->getId() . ' has no active run');
        }
        $wasPending = $run->getStatus() === CoworkerRun::STATUS_PENDING;
        $batchId = $run->getPendingBatchId();
        $now = $this->timeFactory->getTime();

        $run->setStatus(CoworkerRun::STATUS_CANCELLED);
        $run->setFinishedAt($now);
        $run->setState(null);
        $run->appendLog(CoworkerRun::LOG_WARN, sprintf('Cancelled after %d/%d item(s)', $run->getItemsProcessed(), $run->getItemsTotal()));
        if ($wasPending && $batchId !== null) {
            [$level, $message] = $this->cancelBatch($coworker, $batchId);
            $run->appendLog($level, $message);
        }
        $this->runMapper->update($run);

        $coworker->setLastStatus(CoworkerRun::STATUS_CANCELLED);
        $coworker->setLastError(null);
        if ($wasPending) {
            $coworker->setLastRunAt($now);
            $this->refreshNextRun($coworker, $now);
        }
        $this->mapper->update($coworker);

        $this->logger->info('AIquila Cowork: run cancelled', ['coworker' => $coworker->getId(), 'run' => $run->getId()]);
        return $run;
    }

    /**
     * Ask the provider to cancel a batch. Requests it already finished are
     * billed either way.
     *
     * @return array{0: 'info'|'warn', 1: string} a log level and message for the run
     */
    private function cancelBatch(Coworker $coworker, string $batchId): array {
        try {
            $provider = $this->providerFactory->getProviderForUser($coworker->getUserId(), $coworker->getProvider());
            if ($provider instanceof ClaudeSDKService && $provider->cancelBatch($batchId, $coworker->getUserId())) {
                return [CoworkerRun::LOG_INFO, 'Batch ' . $batchId . ' cancelled'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Cowork: could not cancel batch', ['batch' => $batchId, 'error' => $e->getMessage()]);
        }
        return [CoworkerRun::LOG_WARN, 'Batch ' . $batchId . ' could not be cancelled; its results will be ignored'];
    }

    /**
     * Run a coworker now (manual trigger). Returns the completed run row.
     *
     * @throws DoesNotExistException
     */
    public function runNow(int $id, string $userId): CoworkerRun {
        $coworker = $this->mapper->findByIdAndUser($id, $userId);
        return $this->execute($coworker);
    }

    /**
     * Apply a partial update to a resolved coworker, refresh its schedule and
     * persist. Shared by user- and app-scoped callers.
     *
     * @param array<string, mixed> $data
     */
    public function applyUpdate(Coworker $coworker, array $data): Coworker {
        $this->applyData($coworker, $data);
        return $this->persistScheduled($coworker);
    }

    /**
     * Delete a resolved coworker and its run history.
     */
    public function deleteEntity(Coworker $coworker): void {
        $this->runMapper->deleteByCoworker($coworker->getId());
        $this->mapper->delete($coworker);
    }

    /**
     * Stamp updated_at, recompute next_run_at and persist a resolved coworker.
     */
    public function persistScheduled(Coworker $coworker): Coworker {
        $coworker->setUpdatedAt($this->timeFactory->getTime());
        $this->refreshNextRun($coworker);
        return $this->mapper->update($coworker);
    }

    /**
     * Execute a coworker: record a run, dispatch to its task type, and update
     * scheduling/status fields. Never throws — failures are captured on the run.
     */
    public function execute(Coworker $coworker): CoworkerRun {
        // A coworker whose previous batch has not come back yet must not start
        // another: that would bill the same folder twice and race two runs
        // onto the same output files. Skipping is the whole handling — the
        // poll job is already watching the open run.
        $open = $this->runMapper->findPendingForCoworker($coworker->getId());
        if ($open !== null) {
            $this->logger->info('AIquila Cowork: previous run still pending, skipping', [
                'coworker' => $coworker->getId(),
                'run' => $open->getId(),
            ]);
            return $open;
        }

        $now = $this->timeFactory->getTime();
        $run = new CoworkerRun();
        $run->setCoworkerId($coworker->getId());
        $run->setUserId($coworker->getUserId());
        $run->setStatus(CoworkerRun::STATUS_RUNNING);
        $run->setStartedAt($now);
        $run = $this->runMapper->insert($run);
        // QBMapper never clears the updated-field marks, and the insert marked
        // every field. Left as is, each progress update would write the whole
        // row again, `status: running` included, over a stop() from another
        // request. Cleared, the worker writes only what it changes.
        $run->resetUpdatedFields();

        try {
            $taskType = $this->registry->get($coworker->getTaskType());
            $result = $taskType->run($coworker, $run, $this->progressCallback($run));
        } catch (RunCancelledException) {
            return $this->finishCancelled($coworker, $run);
        } catch (\Throwable $e) {
            return $this->finishRun($coworker, $run, null, $e);
        }

        return $this->finishRun($coworker, $run, $result, null);
    }

    /**
     * Continue a run that reported `pending`, and terminalise it if it is done.
     *
     * Never throws: a failure here is captured on the run exactly as it would
     * be in execute(), because the caller is a background job.
     */
    public function resumePending(CoworkerRun $run): CoworkerRun {
        try {
            $coworker = $this->mapper->findByIdAndUser($run->getCoworkerId(), $run->getUserId());
        } catch (\Throwable $e) {
            // The coworker was deleted while its batch was in flight. Nothing
            // can finish the run, so close it rather than leaving it pending
            // for the poll job to pick up forever.
            $run->setStatus(CoworkerRun::STATUS_ERROR);
            $run->setError('Coworker no longer exists');
            $run->setState(null);
            $run->setFinishedAt($this->timeFactory->getTime());
            $this->runMapper->update($run);
            return $run;
        }

        try {
            $taskType = $this->registry->get($coworker->getTaskType());
            if (!$taskType instanceof ResumableCoworkerTaskType) {
                throw new \RuntimeException(
                    'Task type ' . $coworker->getTaskType() . ' cannot resume a pending run'
                );
            }
            $result = $taskType->resume(
                $coworker,
                $run,
                $run->getDecodedState(),
                $this->progressCallback($run)
            );
        } catch (RunCancelledException) {
            return $this->finishCancelled($coworker, $run);
        } catch (\Throwable $e) {
            return $this->finishRun($coworker, $run, null, $e);
        }

        return $this->finishRun($coworker, $run, $result, null);
    }

    /**
     * Persist progress, and end the run if it was stopped meanwhile.
     *
     * The status is read fresh: stop() runs in another request and writes the
     * row, not this in-memory entity. update() writes only the fields this
     * process changed, so progress never overwrites a `cancelled` status.
     *
     * @return callable(int, int): void
     * @throws RunCancelledException from the callback, once the run is cancelled
     */
    private function progressCallback(CoworkerRun $run): callable {
        return function (int $processed, int $total) use ($run): void {
            $run->setItemsProcessed($processed);
            $run->setItemsTotal($total);
            $this->runMapper->update($run);
            if ($this->runMapper->findStatus($run->getId()) === CoworkerRun::STATUS_CANCELLED) {
                throw new RunCancelledException('Run ' . $run->getId() . ' was cancelled');
            }
        };
    }

    /**
     * Close a run whose task type stopped because it was cancelled. Keeps
     * what it processed; the schedule moves on as after any finished run.
     */
    private function finishCancelled(Coworker $coworker, CoworkerRun $run): CoworkerRun {
        $finishedAt = $this->timeFactory->getTime();
        $run->setStatus(CoworkerRun::STATUS_CANCELLED);
        $run->setState(null);
        $run->setFinishedAt($finishedAt);
        $run->appendLog(CoworkerRun::LOG_WARN, sprintf('Cancelled after %d/%d item(s)', $run->getItemsProcessed(), $run->getItemsTotal()));
        $this->runMapper->update($run);

        $coworker->setLastStatus(CoworkerRun::STATUS_CANCELLED);
        $coworker->setLastError(null);
        $coworker->setLastRunAt($finishedAt);
        $this->refreshNextRun($coworker, $finishedAt);
        $this->mapper->update($coworker);
        return $run;
    }

    /**
     * Persist the outcome of one run() or resume() call.
     *
     * A `pending` result leaves the run open — no finished_at, no last_run_at,
     * no next-run recomputation — because the work has not happened yet and
     * the coworker has nothing to report.
     *
     * @param array{itemsTotal?: int, itemsProcessed?: int, summary?: string, pending?: bool, state?: array<string, mixed>}|null $result
     */
    private function finishRun(
        Coworker $coworker,
        CoworkerRun $run,
        ?array $result,
        ?\Throwable $error,
    ): CoworkerRun {
        if ($error !== null) {
            $run->setStatus(CoworkerRun::STATUS_ERROR);
            $run->setError($error->getMessage());
            $run->setState(null);
            $run->appendLog(CoworkerRun::LOG_ERROR, 'Run failed: ' . $error->getMessage());
            $coworker->setLastStatus(CoworkerRun::STATUS_ERROR);
            $coworker->setLastError($error->getMessage());
            $this->logger->error('AIquila Cowork: run failed', [
                'coworker' => $coworker->getId(),
                'error' => $error->getMessage(),
            ]);
        } elseif (($result['pending'] ?? false) === true) {
            $run->setItemsTotal((int)($result['itemsTotal'] ?? 0));
            $run->setItemsProcessed((int)($result['itemsProcessed'] ?? 0));
            $run->setSummary((string)($result['summary'] ?? ''));
            $run->setStatus(CoworkerRun::STATUS_PENDING);
            $run->setDecodedState($result['state'] ?? []);
            $this->runMapper->update($run);
            return $run;
        } elseif ($this->runMapper->findStatus($run->getId()) === CoworkerRun::STATUS_CANCELLED) {
            // Stopped after the last progress check: the stop stands.
            $run->setItemsTotal((int)($result['itemsTotal'] ?? 0));
            $run->setItemsProcessed((int)($result['itemsProcessed'] ?? 0));
            $run->setSummary((string)($result['summary'] ?? ''));
            return $this->finishCancelled($coworker, $run);
        } else {
            $run->setItemsTotal((int)($result['itemsTotal'] ?? 0));
            $run->setItemsProcessed((int)($result['itemsProcessed'] ?? 0));
            $run->setSummary((string)($result['summary'] ?? ''));
            $run->setStatus(CoworkerRun::STATUS_SUCCESS);
            $run->setState(null);
            $coworker->setLastStatus(CoworkerRun::STATUS_SUCCESS);
            $coworker->setLastError(null);
        }

        $finishedAt = $this->timeFactory->getTime();
        $run->setFinishedAt($finishedAt);
        $this->runMapper->update($run);

        $coworker->setLastRunAt($finishedAt);
        $this->refreshNextRun($coworker, $finishedAt);
        $this->mapper->update($coworker);

        return $run;
    }

    /**
     * Recompute next_run_at from the cron schedule, or null it out when the
     * coworker is inactive/paused so the executor skips it.
     */
    private function refreshNextRun(Coworker $coworker, ?int $from = null): void {
        if (!$coworker->getIsActive() || $coworker->getPaused()) {
            $coworker->setNextRunAt(null);
            return;
        }
        $from ??= $this->timeFactory->getTime();
        try {
            $next = (new CronSchedule($coworker->getCronSchedule()))->getNextRunTime($from);
            $coworker->setNextRunAt($next);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('AIquila Cowork: invalid cron schedule', [
                'coworker' => $coworker->getId(),
                'schedule' => $coworker->getCronSchedule(),
                'error' => $e->getMessage(),
            ]);
            $coworker->setNextRunAt(null);
        }
    }

    /**
     * Apply a partial data array onto a coworker, validating task type, schedule
     * and task options.
     *
     * @param array<string, mixed> $data
     */
    private function applyData(Coworker $coworker, array $data): void {
        if (array_key_exists('title', $data)) {
            $coworker->setTitle((string)$data['title']);
        }
        if (array_key_exists('description', $data)) {
            $coworker->setDescription($data['description'] !== null ? (string)$data['description'] : null);
        }
        if (array_key_exists('model', $data)) {
            $coworker->setModel($data['model'] !== null && $data['model'] !== '' ? (string)$data['model'] : null);
        }
        if (array_key_exists('provider', $data)) {
            $provider = $data['provider'] !== null ? (string)$data['provider'] : '';
            // '' means "follow the owner's setting"; anything else must name a
            // registered provider, so a request string never reaches a config
            // lookup or an outbound call.
            if ($provider !== '' && !$this->providerFactory->isKnownProviderId($provider)) {
                throw new ValidationException("Unknown provider: $provider");
            }
            // Pinning a provider on a coworker is a provider-selecting path like
            // any other: the owner has to be permitted to use it, or a blocked
            // provider could be reached through a scheduled run.
            if ($provider !== '' && !$this->providerFactory->isAllowedForUser($provider, $coworker->getUserId())) {
                throw new ValidationException("You are not permitted to use this provider: $provider");
            }
            $coworker->setProvider($provider !== '' ? $provider : null);
        }
        if (array_key_exists('custom_prompt', $data)) {
            $prompt = $data['custom_prompt'] !== null ? trim((string)$data['custom_prompt']) : '';
            if (($tooLong = InstructionsService::tooLong($prompt, field: 'Prompt')) !== null) {
                throw new ValidationException($tooLong);
            }
            $coworker->setCustomPrompt($prompt !== '' ? $prompt : null);
        }
        if (array_key_exists('prompt_id', $data)) {
            $promptId = $data['prompt_id'];
            if ($promptId === null || $promptId === '' || $promptId === 0) {
                $coworker->setPromptId(null);
            } else {
                // Only one of the owner's own saved prompts: a scheduled run
                // must not read another user's prompt.
                try {
                    $this->prompts?->findByIdAndUser((int)$promptId, $coworker->getUserId());
                } catch (DoesNotExistException) {
                    throw new ValidationException('Prompt not found: ' . (int)$promptId);
                }
                $coworker->setPromptId((int)$promptId);
            }
        }
        if (array_key_exists('task_type', $data)) {
            $taskType = (string)$data['task_type'];
            if (!$this->registry->has($taskType)) {
                throw new ValidationException("Unknown task type: $taskType");
            }
            $coworker->setTaskType($taskType);
        }
        if (array_key_exists('cron_schedule', $data)) {
            $schedule = (string)$data['cron_schedule'];
            if (!CronSchedule::isValid($schedule)) {
                throw new ValidationException("Invalid cron schedule: $schedule");
            }
            $coworker->setCronSchedule($schedule);
        }
        if (array_key_exists('input_type', $data)) {
            $coworker->setInputType((string)$data['input_type']);
        }
        if (array_key_exists('input_path', $data)) {
            $coworker->setInputPath($data['input_path'] !== null ? (string)$data['input_path'] : null);
        }
        if (array_key_exists('output_type', $data)) {
            $coworker->setOutputType((string)$data['output_type']);
        }
        if (array_key_exists('output_path', $data)) {
            $coworker->setOutputPath($data['output_path'] !== null ? (string)$data['output_path'] : null);
        }
        if (array_key_exists('is_active', $data)) {
            $coworker->setIsActive(!empty($data['is_active']) ? 1 : 0);
        }
        if (array_key_exists('paused', $data)) {
            $coworker->setPaused(!empty($data['paused']) ? 1 : 0);
        }
        if (array_key_exists('options', $data)) {
            $options = $data['options'];
            if (is_string($options)) {
                $decoded = json_decode($options, true);
                $options = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($options)) {
                $options = [];
            }
            // Validate against the resolved task type when known.
            $taskTypeId = $coworker->getTaskType();
            if ($this->registry->has($taskTypeId)) {
                $this->registry->get($taskTypeId)->validateOptions($options);
            }
            $coworker->setOptions(json_encode($options) ?: null);
        }

        // Sensible defaults for required columns on first create.
        if ($coworker->getInputType() === '') {
            $coworker->setInputType('folder');
        }
        if ($coworker->getOutputType() === '') {
            $coworker->setOutputType('system_tags');
        }
        if ($coworker->getCronSchedule() === '') {
            $coworker->setCronSchedule('0 3 * * *');
        }
    }
}
