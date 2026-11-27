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
 * @method bool|null getSpeedFast()
 * @method void setSpeedFast(?bool $speedFast)
 * @method string|null getToolState()
 * @method void setToolState(?string $toolState)
 * @method int getToolStateVersion()
 */
class Conversation extends Entity implements \JsonSerializable {
    use InsertsFullRow;

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
    /** Fast-mode pin; null follows the instance default, false opts out of it. */
    protected ?bool $speedFast = null;
    /** Declared and offered tools, see Service\ToolChanges. Server-side only. */
    protected ?string $toolState = null;
    /** Bumped by every write of $toolState, see ConversationMapper::swapToolState(). */
    protected int $toolStateVersion = 0;

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
        $this->addType('speedFast', 'boolean');
        $this->addType('toolState', 'string');
        $this->addType('toolStateVersion', 'integer');
        $this->markFieldsForInsert();
    }

    /**
     * Take the tool state the row has now, without marking it for the next
     * update(): only ConversationMapper::swapToolState() writes it, so a
     * plain update() never puts back a state another turn has replaced.
     */
    public function adoptToolState(?string $state, int $version): void {
        $this->toolState = $state;
        $this->toolStateVersion = $version;
    }

    /**
     * @return array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool, thinkingBudget: ?int, speedFast: ?bool}
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
            'speedFast' => $this->getSpeedFast(),
        ];
    }
}
