<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\FileUpload;
use OCA\AIquila\Db\FileUploadMapper;
use Psr\Log\LoggerInterface;

/**
 * Manages uploads to Anthropic's beta Files API. Files are deduplicated
 * by sha256 per user (Anthropic's file storage is per-API-key, and we
 * resolve API keys per user, so dedup must be scoped per user too).
 *
 * On any upload failure the caller should fall back to inlining the
 * bytes — this service surfaces nulls instead of throwing so failures
 * stay non-fatal.
 */
class FilesService {
    public function __construct(
        private readonly ClaudeSDKService $claudeService,
        private readonly FileUploadMapper $mapper,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Return a cached file_id for $bytes, uploading once if needed.
     * Returns null on any failure — caller falls back to base64 inlining.
     */
    public function getOrUploadFileId(string $bytes, string $filename, string $mimeType, string $userId): ?string {
        $sha = hash('sha256', $bytes);

        $cached = $this->mapper->findByHash($userId, $sha);
        if ($cached !== null) {
            return $cached->getAnthropicFileId();
        }

        try {
            $fileId = $this->claudeService->uploadFile($bytes, $filename, $mimeType, $userId);
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
        $row->setAnthropicFileId($fileId);
        $row->setUploadedAt(time());
        try {
            $this->mapper->insert($row);
        } catch (\Throwable $e) {
            // A concurrent upload may have raced us to the unique index — log and use the upload anyway.
            $this->logger->debug('AIquila Files: cache insert raced; using fresh file_id', [
                'sha256' => $sha,
                'error' => $e->getMessage(),
            ]);
        }

        return $fileId;
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
