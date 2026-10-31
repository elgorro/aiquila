<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\BackgroundJob;

use OCA\AIquila\Db\FileUploadMapper;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\FilesService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily cleanup of the Anthropic Files API cache.
 *
 * Uploads carry an expiry (FilesService::TTL_SECONDS), after which Anthropic
 * deletes the file and its row here only points at nothing; those rows are
 * dropped. Anthropic keeps a file uploaded without an expiry until it is
 * deleted, so for rows that predate expiries the remote file is deleted with
 * the uploading user's key once it is as old as the TTL. When the admin has
 * switched the Files API off, every remaining upload is deleted that way.
 *
 * Remote deletes are capped per run to stay well inside the Files API's rate
 * limit (about 500 requests per minute); a backlog drains over several days.
 */
class CleanupAnthropicFilesJob extends TimedJob {
    /** Most remote deletes per run. */
    private const DELETE_BATCH = 200;

    public function __construct(
        ITimeFactory $time,
        private readonly FileUploadMapper $mapper,
        private readonly ClaudeSDKService $claudeService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }

    protected function run($argument): void {
        $now = $this->time->getTime();
        try {
            $expired = $this->mapper->deleteExpired($now);
            $deleted = $this->deleteRemote($now);
            if ($expired > 0 || $deleted > 0) {
                $this->logger->info('AIquila Files: cleaned up the Anthropic file cache', [
                    'expired_rows' => $expired,
                    'deleted_files' => $deleted,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Files: cleanup job failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Delete remote files nothing else will delete; returns how many went. */
    private function deleteRemote(int $now): int {
        $rows = $this->mapper->findToDelete(
            $now - FilesService::TTL_SECONDS,
            !$this->claudeService->supportsFilesApi(),
            self::DELETE_BATCH,
        );

        $deleted = 0;
        foreach ($rows as $row) {
            try {
                $this->claudeService->deleteFile($row->getAnthropicFileId(), $row->getUserId());
            } catch (\Throwable $e) {
                // Keep the row so the next run retries — up to a point: a row
                // that can never be deleted (the user's key is gone) would
                // otherwise head every batch for good.
                $givingUp = $row->getUploadedAt() < $now - 2 * FilesService::TTL_SECONDS;
                $this->logger->warning('AIquila Files: could not delete an uploaded file' . ($givingUp ? '; giving up' : ''), [
                    'file_id' => $row->getAnthropicFileId(),
                    'error' => $e->getMessage(),
                ]);
                if (!$givingUp) {
                    continue;
                }
            }
            $this->mapper->delete($row);
            $deleted++;
        }
        return $deleted;
    }
}
