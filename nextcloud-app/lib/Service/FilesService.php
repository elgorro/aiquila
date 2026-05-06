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
}
