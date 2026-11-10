<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Starts coworkers on something other than their schedule: a file change in
 * their input folder, or a call to their trigger URL.
 *
 * A trigger never runs a coworker itself. It marks a run as due and pulls
 * `next_run_at` forward, and CoworkerRunJob starts it on its next tick, so:
 *
 *  - nothing runs inside the request that changed the file or called the URL;
 *  - any number of triggers before that tick make one run (de-duplication),
 *    and file triggers wait DEBOUNCE_SECONDS so an upload of many files is
 *    one run, not one per file;
 *  - a run that is already open is never doubled (CoworkerService::execute);
 *  - at most MAX_TRIGGERED_RUNS_PER_HOUR triggered runs start per coworker per
 *    hour, so an event storm, or two coworkers writing into each other's
 *    folders, cannot queue unbounded runs.
 *
 * The files a coworker's own run writes do not trigger it: the run writes them
 * from the same PHP process, which marks the coworker as running here.
 */
class CoworkerTriggerService {
    public const DEBOUNCE_SECONDS = 60;
    public const MAX_TRIGGERED_RUNS_PER_HOUR = 12;
    /** Length of a trigger URL secret; 64 characters from a 62-letter alphabet is ~381 bits. */
    private const TOKEN_LENGTH = 64;

    /** @var array<int, int> coworker id => nesting depth, for runs in this process */
    private static array $runningHere = [];

    public function __construct(
        private readonly CoworkerMapper $mapper,
        private readonly CoworkerRunMapper $runMapper,
        private readonly CoworkerTriggerPolicy $policy,
        private readonly IRootFolder $rootFolder,
        private readonly ISecureRandom $random,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Called around a run, so the files it writes do not trigger it again. */
    public static function enterRun(int $coworkerId): void {
        self::$runningHere[$coworkerId] = (self::$runningHere[$coworkerId] ?? 0) + 1;
    }

    public static function leaveRun(int $coworkerId): void {
        if (!isset(self::$runningHere[$coworkerId])) {
            return;
        }
        if (--self::$runningHere[$coworkerId] <= 0) {
            unset(self::$runningHere[$coworkerId]);
        }
    }

    public static function isRunningHere(int $coworkerId): bool {
        return isset(self::$runningHere[$coworkerId]);
    }

    /**
     * Ask for a run of $coworker. Returns whether one is now due; false when
     * the coworker cannot run, the policy does not allow its account, or it is
     * over its hourly limit.
     *
     * @param CoworkerRun::STARTED_BY_FILES|CoworkerRun::STARTED_BY_URL $source
     */
    public function request(Coworker $coworker, string $source): bool {
        if (!$coworker->getIsActive() || $coworker->getPaused()) {
            return false;
        }
        if (!$this->policy->isAllowed($coworker->getUserId())) {
            return false;
        }

        $now = $this->timeFactory->getTime();
        $started = $this->runMapper->countStartedBySince(
            $coworker->getId(),
            [CoworkerRun::STARTED_BY_FILES, CoworkerRun::STARTED_BY_URL],
            $now - 3600,
        );
        if ($started >= self::MAX_TRIGGERED_RUNS_PER_HOUR) {
            $this->logger->warning('AIquila Cowork: trigger ignored, hourly limit reached', [
                'coworker' => $coworker->getId(),
                'source' => $source,
                'limit' => self::MAX_TRIGGERED_RUNS_PER_HOUR,
            ]);
            return false;
        }

        $due = $coworker->getTriggerDueAt();
        if ($due === null) {
            $due = $source === CoworkerRun::STARTED_BY_FILES ? $now + self::DEBOUNCE_SECONDS : $now;
            $coworker->setTriggerDueAt($due);
            $coworker->setTriggerSource($source);
        } elseif ($source === CoworkerRun::STARTED_BY_URL && $due > $now) {
            // A URL call is not debounced; it brings a waiting file run forward,
            // and that run is then the one the URL started.
            $due = $now;
            $coworker->setTriggerDueAt($due);
            $coworker->setTriggerSource($source);
        }
        $next = $coworker->getNextRunAt();
        if ($next === null || $next > $due) {
            $coworker->setNextRunAt($due);
        }
        $this->mapper->update($coworker);
        return true;
    }

    /** Whether the account the coworker runs as may use triggers now. */
    public function isAllowedFor(Coworker $coworker): bool {
        return $this->policy->isAllowed($coworker->getUserId());
    }

    /**
     * A file was added or changed: ask for a run of every file-triggered
     * coworker whose input folder holds it.
     */
    public function fileChanged(Node $node): void {
        if (!$node instanceof File || !$this->policy->isEnabled() || self::isIgnoredName($node->getName())) {
            return;
        }
        $candidates = $this->mapper->findFileTriggered();
        if ($candidates === []) {
            return;
        }

        // File ids are the same for every account that sees the file, shared
        // or not, so the folders above it identify it across users.
        $ancestors = [];
        try {
            $parent = $node->getParent();
            for ($depth = 0; $depth < 64; $depth++) {
                $ancestors[$parent->getId()] = true;
                $next = $parent->getParent();
                if ($next->getId() === $parent->getId()) {
                    break;
                }
                $parent = $next;
            }
        } catch (\Throwable) {
            // Reached the top of what this account can see.
        }
        if ($ancestors === []) {
            return;
        }

        foreach ($candidates as $coworker) {
            if (self::isRunningHere($coworker->getId())) {
                continue;
            }
            try {
                if (!$this->holds($coworker, $ancestors)) {
                    continue;
                }
                $this->request($coworker, CoworkerRun::STARTED_BY_FILES);
            } catch (\Throwable $e) {
                $this->logger->warning('AIquila Cowork: file trigger failed', [
                    'coworker' => $coworker->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Whether the file under $ancestors is inside the coworker's input folder
     * and outside its output folder.
     *
     * @param array<int, true> $ancestors
     */
    private function holds(Coworker $coworker, array $ancestors): bool {
        if ($coworker->getInputType() === 'none') {
            return false;
        }
        $userFolder = $this->rootFolder->getUserFolder($coworker->getUserId());
        $input = $userFolder->get($coworker->getInputPath() ?: '/');
        if (!$input instanceof Folder || !isset($ancestors[$input->getId()])) {
            return false;
        }
        $outputPath = $coworker->getOutputPath();
        if ($outputPath !== null && $outputPath !== '') {
            try {
                $output = $userFolder->get($outputPath);
                if ($output instanceof Folder && isset($ancestors[$output->getId()])) {
                    return false;
                }
            } catch (\Throwable) {
                // No output folder yet: nothing to exclude.
            }
        }
        return true;
    }

    /** Upload chunks and hidden files are not content a coworker works on. */
    private static function isIgnoredName(string $name): bool {
        return str_starts_with($name, '.') || str_ends_with($name, '.part');
    }

    /**
     * Give the coworker a new trigger URL secret, replacing any earlier one.
     * The secret is returned once and only its hash is stored.
     */
    public function createToken(Coworker $coworker): string {
        $token = $this->random->generate(
            self::TOKEN_LENGTH,
            ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS,
        );
        $coworker->setTriggerTokenHash(hash('sha256', $token));
        $this->mapper->update($coworker);
        return $token;
    }

    public function revokeToken(Coworker $coworker): void {
        $coworker->setTriggerTokenHash(null);
        $this->mapper->update($coworker);
    }

    /** Constant-time check of a presented secret against the stored hash. */
    public static function tokenMatches(Coworker $coworker, string $token): bool {
        $hash = $coworker->getTriggerTokenHash();
        return $hash !== null && $token !== '' && hash_equals($hash, hash('sha256', $token));
    }
}
