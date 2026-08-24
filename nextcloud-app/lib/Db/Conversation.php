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
 * @method string|null getProvider()
 * @method void setProvider(?string $provider)
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
 * @method int|null getThinkingBudget()
 * @method void setThinkingBudget(?int $thinkingBudget)
 */
class Conversation extends Entity implements \JsonSerializable {
    protected string $userId = '';
    protected ?string $title = null;
    protected string $model = '';
    /** Pinned provider id; null means "follow the user's current setting". */
    protected ?string $provider = null;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;
    protected ?int $projectId = null;
    protected ?string $effort = null;
    protected ?bool $thinking = null;
    /** Explicit thinking budget in tokens; null keeps thinking adaptive. */
    protected ?int $thinkingBudget = null;

    public function __construct() {
        $this->addType('userId', 'string');
        $this->addType('title', 'string');
        $this->addType('model', 'string');
        $this->addType('provider', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('projectId', 'integer');
        $this->addType('effort', 'string');
        $this->addType('thinking', 'boolean');
        $this->addType('thinkingBudget', 'integer');
    }

    /**
     * @return array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool, thinkingBudget: ?int}
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'title' => $this->getTitle(),
            'model' => $this->getModel(),
            'provider' => $this->getProvider(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
            'projectId' => $this->getProjectId(),
            'effort' => $this->getEffort(),
            'thinking' => $this->getThinking(),
            'thinkingBudget' => $this->getThinkingBudget(),
        ];
    }
}
