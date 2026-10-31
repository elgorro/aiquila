<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Tracks files uploaded to Anthropic's Files API so we can reuse the
 * file_id across requests instead of re-uploading the same bytes.
 *
 * Keyed (user_id, sha256) — Anthropic's file storage is per-API-key,
 * and we resolve API keys per user, so dedup must be per user too.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method string getAnthropicFileId()
 * @method void setAnthropicFileId(string $anthropicFileId)
 * @method int getUploadedAt()
 * @method void setUploadedAt(int $uploadedAt)
 * @method int|null getExpiresAt()
 * @method void setExpiresAt(?int $expiresAt)
 */
class FileUpload extends Entity {
    use InsertsFullRow;

    protected string $userId = '';
    protected string $sha256 = '';
    protected string $anthropicFileId = '';
    protected int $uploadedAt = 0;
    /** When Anthropic expires the file; null for uploads made without an expiry. */
    protected ?int $expiresAt = null;

    public function __construct() {
        $this->addType('userId', 'string');
        $this->addType('sha256', 'string');
        $this->addType('anthropicFileId', 'string');
        $this->addType('uploadedAt', 'integer');
        $this->addType('expiresAt', 'integer');
        $this->markFieldsForInsert();
    }
}
