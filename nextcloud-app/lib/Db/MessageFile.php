<?php

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getMessageId()
 * @method void setMessageId(int $messageId)
 * @method string getFilePath()
 * @method void setFilePath(string $filePath)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string|null getMimeType()
 * @method void setMimeType(?string $mimeType)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class MessageFile extends Entity {
    protected int $messageId = 0;
    protected string $filePath = '';
    protected string $fileName = '';
    protected ?string $mimeType = null;
    protected int $createdAt = 0;

    public function __construct() {
        $this->addType('messageId', 'integer');
        $this->addType('filePath', 'string');
        $this->addType('fileName', 'string');
        $this->addType('mimeType', 'string');
        $this->addType('createdAt', 'integer');
    }
}
