<?php

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getModel()
 * @method void setModel(string $model)
 * @method int getInputTokens()
 * @method void setInputTokens(int $inputTokens)
 * @method int getOutputTokens()
 * @method void setOutputTokens(int $outputTokens)
 * @method string getRequestType()
 * @method void setRequestType(string $requestType)
 * @method int|null getConversationId()
 * @method void setConversationId(?int $conversationId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class UsageStat extends Entity {
    protected string $userId = '';
    protected string $model = '';
    protected int $inputTokens = 0;
    protected int $outputTokens = 0;
    protected string $requestType = '';
    protected ?int $conversationId = null;
    protected int $createdAt = 0;

    public function __construct() {
        $this->addType('userId', 'string');
        $this->addType('model', 'string');
        $this->addType('inputTokens', 'integer');
        $this->addType('outputTokens', 'integer');
        $this->addType('requestType', 'string');
        $this->addType('conversationId', 'integer');
        $this->addType('createdAt', 'integer');
    }
}
