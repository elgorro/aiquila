<?php

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Prompt extends Entity {
    protected string $userId = '';
    protected string $title = '';
    protected ?string $description = null;
    protected string $content = '';
    protected bool $isActive = true;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('userId', 'string');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('content', 'string');
        $this->addType('isActive', 'boolean');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
