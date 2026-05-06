<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

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
 * @method int|null getCacheCreationTokens()
 * @method void setCacheCreationTokens(?int $cacheCreationTokens)
 * @method int|null getCacheReadTokens()
 * @method void setCacheReadTokens(?int $cacheReadTokens)
 * @method int|null getLatencyMs()
 * @method void setLatencyMs(?int $latencyMs)
 * @method string|null getCitations()
 * @method void setCitations(?string $citations)
 */
class Message extends Entity implements \JsonSerializable {
    protected int $conversationId = 0;
    protected string $role = '';
    protected string $content = '';
    protected ?int $inputTokens = null;
    protected ?int $outputTokens = null;
    protected int $createdAt = 0;
    protected ?int $cacheCreationTokens = null;
    protected ?int $cacheReadTokens = null;
    protected ?int $latencyMs = null;
    protected ?string $citations = null;

    public function __construct() {
        $this->addType('conversationId', 'integer');
        $this->addType('role', 'string');
        $this->addType('content', 'string');
        $this->addType('inputTokens', 'integer');
        $this->addType('outputTokens', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('cacheCreationTokens', 'integer');
        $this->addType('cacheReadTokens', 'integer');
        $this->addType('latencyMs', 'integer');
        $this->addType('citations', 'string');
    }

    public function jsonSerialize(): array {
        $citationsJson = $this->getCitations();
        $citations = null;
        if ($citationsJson !== null && $citationsJson !== '') {
            $decoded = json_decode($citationsJson, true);
            $citations = is_array($decoded) ? $decoded : null;
        }
        return [
            'id' => $this->getId(),
            'conversationId' => $this->getConversationId(),
            'role' => $this->getRole(),
            'content' => $this->getContent(),
            'inputTokens' => $this->getInputTokens(),
            'outputTokens' => $this->getOutputTokens(),
            'cacheCreationTokens' => $this->getCacheCreationTokens(),
            'cacheReadTokens' => $this->getCacheReadTokens(),
            'latencyMs' => $this->getLatencyMs(),
            'citations' => $citations,
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
