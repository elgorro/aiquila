<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\FileUpload;
use OCA\AIquila\Db\FileUploadMapper;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages uploads to Anthropic's Files API. Files are deduplicated
 * by sha256 per user (Anthropic's file storage is per workspace, and we
 * resolve API keys per user, so dedup must be scoped per user too).
 *
 * Every upload carries an expiry, so Anthropic deletes the file by itself
 * after TTL_SECONDS; without one a file would stay there until deleted. A
 * cached file_id is reused until REUPLOAD_MARGIN before it expires, then the
 * bytes are uploaded again, so a reference never outlives its file mid-turn.
 * CleanupAnthropicFilesJob drops the rows of expired files, and deletes the
 * remote copy of uploads made before expiries were set.
 *
 * On any upload failure the caller should fall back to inlining the
 * bytes — this service surfaces nulls instead of throwing so failures
 * stay non-fatal.
 */
class FilesService {
    /** How long Anthropic keeps an upload (30 days; the API allows 1 h – 90 d). */
    public const TTL_SECONDS = 30 * 86400;
    /** Re-upload once a cached file has less than this left. */
    public const REUPLOAD_MARGIN = 86400;

    public function __construct(
        private readonly ClaudeSDKService $claudeService,
        private readonly FileUploadMapper $mapper,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Whether a request to $provider may carry Anthropic file_ids. Claude on a
     * cloud platform cannot resolve them (the upload went to the Claude API),
     * so for those callers keep the inline payload and skip the upload.
     */
    public static function acceptsFileIds(LLMProviderInterface $provider): bool {
        return !$provider instanceof ClaudeSDKService || $provider->supportsFilesApi();
    }

    /**
     * Return a cached file_id for $bytes, uploading once if needed.
     * Returns null on any failure — caller falls back to base64 inlining.
     */
    public function getOrUploadFileId(string $bytes, string $filename, string $mimeType, string $userId): ?string {
        if (!$this->claudeService->supportsFilesApi()) {
            return null;
        }

        $sha = hash('sha256', $bytes);
        $now = time();

        $cached = $this->mapper->findByHash($userId, $sha);
        if ($cached !== null) {
            if (self::expiresAt($cached) - $now > self::REUPLOAD_MARGIN) {
                return $cached->getAnthropicFileId();
            }
            $this->retire($cached, $userId);
        }

        try {
            $upload = $this->claudeService->uploadFile($bytes, $filename, $mimeType, $userId, self::TTL_SECONDS);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Files: upload failed, falling back to base64 inline', [
                'filename' => $filename,
                'mime' => $mimeType,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $row = new FileUpload();
        $row->setUserId($userId);
        $row->setSha256($sha);
        $row->setAnthropicFileId($upload['id']);
        $row->setUploadedAt($now);
        $row->setExpiresAt($upload['expiresAt'] ?? $now + self::TTL_SECONDS);
        try {
            $this->mapper->insert($row);
        } catch (\Throwable $e) {
            // A concurrent upload may have raced us to the unique index — log and use the upload anyway.
            $this->logger->debug('AIquila Files: cache insert raced; using fresh file_id', [
                'sha256' => $sha,
                'error' => $e->getMessage(),
            ]);
        }

        return $upload['id'];
    }

    /**
     * When the row's file stops being usable. Uploads made before expiries
     * were set never expire remotely; they are treated as expiring TTL_SECONDS
     * after upload, so they are replaced (and deleted) on the same schedule.
     */
    private static function expiresAt(FileUpload $row): int {
        return $row->getExpiresAt() ?? $row->getUploadedAt() + self::TTL_SECONDS;
    }

    /**
     * Drop a cache row that is about to be replaced. A file uploaded without
     * an expiry is deleted remotely too, since nothing else ever will.
     */
    private function retire(FileUpload $row, string $userId): void {
        if ($row->getExpiresAt() === null) {
            try {
                $this->claudeService->deleteFile($row->getAnthropicFileId(), $userId);
            } catch (\Throwable $e) {
                $this->logger->info('AIquila Files: could not delete a replaced upload', [
                    'file_id' => $row->getAnthropicFileId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        try {
            $this->mapper->delete($row);
        } catch (\Throwable $e) {
            $this->logger->debug('AIquila Files: could not drop a replaced cache row', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Drop the cache row for $fileId so the next request re-uploads.
     * Returns true if a row was removed.
     */
    public function evictByFileId(string $fileId): bool {
        try {
            return $this->mapper->deleteByFileId($fileId) > 0;
        } catch (\Throwable $e) {
            $this->logger->debug('AIquila Files: eviction failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * If $e looks like a "file not found" rejection from Anthropic, return
     * the offending file_id so the caller can evict and retry. Returns null
     * when the error doesn't reference a known cached file_id.
     *
     * Anthropic surfaces these as 404 APIStatusException with the file_id
     * embedded in the message (e.g. "file_id 'file_abc' not found"). We
     * match conservatively against ids of the form `file_*`.
     */
    public function extractStaleFileIdFromError(\Throwable $e): ?string {
        $msg = $e->getMessage();
        if ($msg === '' || stripos($msg, 'file') === false) {
            return null;
        }
        if (!preg_match_all('/\b(file_[A-Za-z0-9_-]+)\b/', $msg, $matches)) {
            return null;
        }
        foreach ($matches[1] as $candidate) {
            if ($candidate !== 'file_id') {
                return $candidate;
            }
        }
        return null;
    }
}
