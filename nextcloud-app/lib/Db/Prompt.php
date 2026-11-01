<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A reusable prompt from the prompt library.
 *
 * `scope` is `user` (only the owner sees it) or `instance` (published by an
 * admin, read-only for everyone). `userId` is the author either way.
 *
 * @method int getId()
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method int getIsActive()
 * @method void setIsActive(int $isActive)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Prompt extends Entity implements \JsonSerializable {
    use InsertsFullRow;

    public const SCOPE_USER = 'user';
    public const SCOPE_INSTANCE = 'instance';

    protected string $scope = self::SCOPE_USER;
    protected string $userId = '';
    protected string $title = '';
    protected ?string $description = null;
    protected string $content = '';
    protected int $isActive = 1;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('scope', 'string');
        $this->addType('userId', 'string');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('content', 'string');
        $this->addType('isActive', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->markFieldsForInsert();
    }

    /**
     * @return array{id: int, scope: string, title: string, description: ?string, content: string, createdAt: int, updatedAt: int}
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'scope' => $this->getScope(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'content' => $this->getContent(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
