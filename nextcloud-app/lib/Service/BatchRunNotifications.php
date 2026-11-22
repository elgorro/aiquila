<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\CoworkerRun;
use OCP\Files\IRootFolder;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Tells a user that a manual batch run from Files has ended.
 *
 * A run in batch mode can end a day after it was started, long after the
 * dialog that started it was closed, so the user hears about it through a
 * Nextcloud notification ({@see \OCA\AIquila\Notifier\AIquilaNotifier} renders
 * it). Cancelled runs are not announced: the user stopped them.
 *
 * The opt-ins are the ones task notifications use, so one switch in the
 * personal settings governs both: `task_success_notifications` for a run that
 * finished, `task_failure_notifications` for one that failed.
 */
class BatchRunNotifications {
    public const SUBJECT_SUCCESS = 'batch_run_success';
    public const SUBJECT_ERROR = 'batch_run_error';
    public const OBJECT_TYPE = 'batch_run';

    public function __construct(
        private readonly INotificationManager $notificationManager,
        private readonly AppSettings $config,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Announce an ended run. Never throws: the run is already persisted. */
    public function runFinished(CoworkerRun $run): void {
        try {
            $this->notify($run);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila batch run: could not send the completion notification', [
                'run' => $run->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notify(CoworkerRun $run): void {
        $subject = match ($run->getStatus()) {
            CoworkerRun::STATUS_SUCCESS => self::SUBJECT_SUCCESS,
            CoworkerRun::STATUS_ERROR => self::SUBJECT_ERROR,
            default => null,
        };
        if ($subject === null) {
            return;
        }
        $userId = $run->getUserId();
        [$key, $default] = $subject === self::SUBJECT_SUCCESS
            ? ['task_success_notifications', '0']
            : ['task_failure_notifications', '1'];
        if ($this->config->getUserValue($userId, 'aiquila', $key, $default) !== '1') {
            return;
        }

        $notification = $this->notificationManager->createNotification();
        $notification->setApp('aiquila')
            ->setUser($userId)
            ->setDateTime(new \DateTime('@' . ($run->getFinishedAt() ?? time())))
            ->setObject(self::OBJECT_TYPE, (string)$run->getId())
            ->setSubject($subject, [
                'runId' => $run->getId(),
                'operation' => (string)$run->getTaskType(),
                'processed' => $run->getItemsProcessed(),
                'total' => $run->getItemsTotal(),
                'failed' => $run->getItemsFailed(),
                'skipped' => $run->getItemsSkipped(),
                'error' => $subject === self::SUBJECT_ERROR ? self::reason($run) : '',
            ] + $this->outputTarget($userId, $run->getDecodedOutputs()));

        $this->notificationManager->notify($notification);
    }

    /**
     * Why a failed run failed. A run whose items all failed carries only a
     * count as its error ("All 3 item(s) failed"), which the counts already
     * say, so the first item's failure is the more useful answer.
     */
    private static function reason(CoworkerRun $run): string {
        if ($run->getItemsFailed() > 0) {
            foreach ($run->getDecodedLog() as $entry) {
                if ($entry['level'] === CoworkerRun::LOG_ERROR && $entry['item'] !== null) {
                    return basename($entry['item']) . ': ' . $entry['message'];
                }
            }
        }
        return (string)$run->getError();
    }

    /**
     * Where "show the results" goes: the folder when every output landed in
     * one, otherwise the first output file. Empty when nothing was written.
     *
     * @param list<string> $outputs user paths
     * @return array{outputDir?: string, outputFileId?: int}
     */
    private function outputTarget(string $userId, array $outputs): array {
        if ($outputs === []) {
            return [];
        }
        $dirs = array_values(array_unique(array_map(self::dirOf(...), $outputs)));
        if (count($outputs) > 1 && count($dirs) === 1) {
            return ['outputDir' => $dirs[0]];
        }
        try {
            $id = $this->rootFolder->getUserFolder($userId)->get($outputs[0])->getId();
        } catch (\Throwable) {
            // Moved or deleted since: its folder is the next best thing.
            return ['outputDir' => self::dirOf($outputs[0])];
        }
        return ['outputDir' => self::dirOf($outputs[0]), 'outputFileId' => $id];
    }

    private static function dirOf(string $path): string {
        $slash = strrpos($path, '/');
        return $slash === false || $slash === 0 ? '/' : substr($path, 0, $slash);
    }
}
