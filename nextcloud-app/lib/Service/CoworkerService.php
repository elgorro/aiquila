<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Cowork\CronSchedule;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\CoworkerRunMapper;
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
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Built-in coworker templates offered as one-click setups in the UI / MCP.
     *
     * @return list<array<string, mixed>>
     */
    public function getTemplates(): array {
        $shared = [
            'task_type' => 'vision:classify',
            'cron_schedule' => '0 3 * * *',
            'input_type' => 'folder',
            'input_path' => '/Photos',
            'output_type' => 'system_tags',
            'options' => ['maxTags' => 8, 'recursive' => true],
        ];
        return [
            array_merge($shared, [
                'id' => 'classify-images-claude',
                'title' => 'Classify images — Claude vision',
                'description' => 'Tag images in a folder using Claude vision, nightly.',
                'model' => 'anthropic',
            ]),
            array_merge($shared, [
                'id' => 'classify-images-pixtral',
                'title' => 'Classify images — Pixtral',
                'description' => 'Tag images in a folder using Mistral Pixtral, nightly.',
                'model' => 'mistral',
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws DoesNotExistException
     */
    public function findForUser(int $id, string $userId): Coworker {
        return $this->mapper->findByIdAndUser($id, $userId);
    }

    /**
     * @return Coworker[]
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
     * @return CoworkerRun[]
     * @throws DoesNotExistException
     */
    public function listRuns(int $id, string $userId, int $limit = 20): array {
        $this->mapper->findByIdAndUser($id, $userId); // authorize
        return $this->runMapper->findByCoworker($id, $userId, $limit);
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
        $now = $this->timeFactory->getTime();
        $run = new CoworkerRun();
        $run->setCoworkerId($coworker->getId());
        $run->setUserId($coworker->getUserId());
        $run->setStatus('running');
        $run->setStartedAt($now);
        $run = $this->runMapper->insert($run);

        try {
            $taskType = $this->registry->get($coworker->getTaskType());
            $progress = function (int $processed, int $total) use ($run): void {
                $run->setItemsProcessed($processed);
                $run->setItemsTotal($total);
                $this->runMapper->update($run);
            };
            $result = $taskType->run($coworker, $run, $progress);

            $run->setItemsTotal((int)($result['itemsTotal'] ?? 0));
            $run->setItemsProcessed((int)($result['itemsProcessed'] ?? 0));
            $run->setSummary((string)($result['summary'] ?? ''));
            $run->setStatus('success');
            $coworker->setLastStatus('success');
            $coworker->setLastError(null);
        } catch (\Throwable $e) {
            $run->setStatus('error');
            $run->setError($e->getMessage());
            $coworker->setLastStatus('error');
            $coworker->setLastError($e->getMessage());
            $this->logger->error('AIquila Cowork: run failed', [
                'coworker' => $coworker->getId(),
                'error' => $e->getMessage(),
            ]);
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
            $coworker->setModel($data['model'] !== null ? (string)$data['model'] : null);
        }
        if (array_key_exists('task_type', $data)) {
            $taskType = (string)$data['task_type'];
            if (!$this->registry->has($taskType)) {
                throw new \InvalidArgumentException("Unknown task type: $taskType");
            }
            $coworker->setTaskType($taskType);
        }
        if (array_key_exists('cron_schedule', $data)) {
            $schedule = (string)$data['cron_schedule'];
            if (!CronSchedule::isValid($schedule)) {
                throw new \InvalidArgumentException("Invalid cron schedule: $schedule");
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
            $coworker->setOptions(json_encode($options));
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
