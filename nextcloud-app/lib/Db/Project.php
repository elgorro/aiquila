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
 * @method string|null getSystemPrompt()
 * @method void setSystemPrompt(?string $systemPrompt)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Project extends Entity implements \JsonSerializable {
    protected string $userId = '';
    protected string $title = '';
    protected ?string $description = null;
    protected ?string $systemPrompt = null;
    protected bool $isActive = true;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('userId', 'string');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('systemPrompt', 'string');
        $this->addType('isActive', 'boolean');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'systemPrompt' => $this->getSystemPrompt(),
            'isActive' => $this->getIsActive(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
