<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Cowork\CoworkerTaskType;
use OCA\AIquila\Cowork\CronSchedule;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Public\ICoworkManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * App-facing implementation of {@see ICoworkManager}. Scopes all operations to
 * the calling app via the coworker's owner_app, and delegates scheduling /
 * execution to {@see CoworkerService} so there is a single source of truth.
 */
class CoworkManager implements ICoworkManager {

    public function __construct(
        private readonly CoworkerService $service,
        private readonly CoworkerMapper $mapper,
        private readonly CoworkerTaskRegistry $registry,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function registerTaskType(CoworkerTaskType $type): void {
        $this->registry->register($type);
    }

    public function register(string $appId, string $userId, array $config): array {
        unset($config['id']);
        return $this->service->create($userId, $config, $appId)->jsonSerialize();
    }

    public function update(string $appId, int $id, array $changes): array {
        $coworker = $this->mapper->findByIdAndApp($id, $appId);
        return $this->service->applyUpdate($coworker, $changes)->jsonSerialize();
    }

    public function deregister(string $appId, int $id): void {
        $this->service->deleteEntity($this->mapper->findByIdAndApp($id, $appId));
    }

    public function setPaused(string $appId, int $id, bool $paused): array {
        $coworker = $this->mapper->findByIdAndApp($id, $appId);
        $coworker->setPaused($paused ? 1 : 0);
        return $this->service->persistScheduled($coworker)->jsonSerialize();
    }

    public function setActive(string $appId, int $id, bool $active): array {
        $coworker = $this->mapper->findByIdAndApp($id, $appId);
        $coworker->setIsActive($active ? 1 : 0);
        return $this->service->persistScheduled($coworker)->jsonSerialize();
    }

    public function runNow(string $appId, int $id): array {
        $coworker = $this->mapper->findByIdAndApp($id, $appId);
        return $this->service->execute($coworker)->jsonSerialize();
    }

    public function get(string $appId, int $id): array {
        return $this->mapper->findByIdAndApp($id, $appId)->jsonSerialize();
    }

    public function list(string $appId, ?string $userId = null): array {
        return array_map(
            fn(Coworker $c) => $c->jsonSerialize(),
            $this->mapper->findAllByApp($appId, $userId),
        );
    }

    public function getRuns(string $appId, int $id, int $limit = 20): array {
        $coworker = $this->mapper->findByIdAndApp($id, $appId); // authorize
        return array_map(
            fn(CoworkerRun $r) => $r->jsonSerialize(),
            $this->service->listRuns($coworker->getId(), $coworker->getUserId(), $limit),
        );
    }

    public function verify(string $appId, int $id): array {
        try {
            $coworker = $this->mapper->findByIdAndApp($id, $appId);
        } catch (DoesNotExistException) {
            return ['owned' => false, 'valid' => false, 'issues' => ['not owned by app'], 'lastStatus' => null];
        }

        $issues = $this->collectIssues($coworker);

        return [
            'owned' => true,
            'valid' => $issues === [],
            'issues' => $issues,
            'lastStatus' => $coworker->getLastStatus(),
        ];
    }

    /**
     * Validate a coworker without executing it, returning human-readable issues
     * that would break (or no-op) a scheduled run.
     *
     * @return list<string>
     */
    private function collectIssues(Coworker $coworker): array {
        $issues = [];

        $taskType = $coworker->getTaskType();
        if (!$this->registry->has($taskType)) {
            $issues[] = "unknown task type: $taskType";
        }

        if (!CronSchedule::isValid($coworker->getCronSchedule())) {
            $issues[] = 'invalid cron schedule: ' . $coworker->getCronSchedule();
        }

        if ($this->registry->has($taskType)) {
            $options = json_decode((string)$coworker->getOptions(), true);
            try {
                $this->registry->get($taskType)->validateOptions(is_array($options) ? $options : []);
            } catch (\InvalidArgumentException $e) {
                $issues[] = 'invalid options: ' . $e->getMessage();
            }
        }

        $inputPath = $coworker->getInputPath();
        if ($inputPath !== null && $inputPath !== '') {
            try {
                $userFolder = $this->rootFolder->getUserFolder($coworker->getUserId());
                if (!$userFolder->nodeExists($inputPath)) {
                    $issues[] = "input path not found: $inputPath";
                }
            } catch (NotFoundException | \Throwable $e) {
                $issues[] = "input path not resolvable: $inputPath";
                $this->logger->debug('AIquila Cowork verify: input path check failed', [
                    'coworker' => $coworker->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $issues;
    }
}
