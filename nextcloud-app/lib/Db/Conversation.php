<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string getModel()
 * @method void setModel(string $model)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getProjectId()
 * @method void setProjectId(?int $projectId)
 * @method string|null getEffort()
 * @method void setEffort(?string $effort)
 * @method bool|null getThinking()
 * @method void setThinking(?bool $thinking)
 */
class Conversation extends Entity implements \JsonSerializable {
    protected string $userId = '';
    protected ?string $title = null;
    protected string $model = '';
    protected int $createdAt = 0;
    protected int $updatedAt = 0;
    protected ?int $projectId = null;
    protected ?string $effort = null;
    protected ?bool $thinking = null;

    public function __construct() {
        $this->addType('userId', 'string');
        $this->addType('title', 'string');
        $this->addType('model', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('projectId', 'integer');
        $this->addType('effort', 'string');
        $this->addType('thinking', 'boolean');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'title' => $this->getTitle(),
            'model' => $this->getModel(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
            'projectId' => $this->getProjectId(),
            'effort' => $this->getEffort(),
            'thinking' => $this->getThinking(),
        ];
    }
}
