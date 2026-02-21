<?php

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getConversationId()
 * @method void setConversationId(int $conversationId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method int|null getInputTokens()
 * @method void setInputTokens(?int $inputTokens)
 * @method int|null getOutputTokens()
 * @method void setOutputTokens(?int $outputTokens)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Message extends Entity {
    protected int $conversationId = 0;
    protected string $role = '';
    protected string $content = '';
    protected ?int $inputTokens = null;
    protected ?int $outputTokens = null;
    protected int $createdAt = 0;

    public function __construct() {
        $this->addType('conversationId', 'integer');
        $this->addType('role', 'string');
        $this->addType('content', 'string');
        $this->addType('inputTokens', 'integer');
        $this->addType('outputTokens', 'integer');
        $this->addType('createdAt', 'integer');
    }
}
