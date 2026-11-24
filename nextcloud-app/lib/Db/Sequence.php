<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A sequence: a named chain of MCP tool calls the MCP server runs in one call.
 *
 * `scope` is `user` (only the owner sees it) or `instance` (published by an
 * admin, read-only for everyone). `userId` is the owner ('' for instance
 * rows); `authorId` who wrote it. `definition` is JSON `{inputs, steps}`.
 *
 * @method int getId()
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getAuthorId()
 * @method void setAuthorId(string $authorId)
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getDefinition()
 * @method void setDefinition(string $definition)
 * @method int getPinned()
 * @method void setPinned(int $pinned)
 * @method int getEnabled()
 * @method void setEnabled(int $enabled)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 *
 * @psalm-import-type AIquilaSequence from \OCA\AIquila\ResponseDefinitions
 * @psalm-import-type AIquilaSequenceDefinition from \OCA\AIquila\ResponseDefinitions
 */
class Sequence extends Entity implements \JsonSerializable {
    use InsertsFullRow;

    public const SCOPE_USER = 'user';
    public const SCOPE_INSTANCE = 'instance';

    protected string $scope = self::SCOPE_USER;
    protected string $userId = '';
    protected string $authorId = '';
    protected string $slug = '';
    protected string $title = '';
    protected ?string $description = null;
    protected string $definition = '{"inputs":[],"steps":[]}';
    protected int $pinned = 0;
    protected int $enabled = 1;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('scope', 'string');
        $this->addType('userId', 'string');
        $this->addType('authorId', 'string');
        $this->addType('slug', 'string');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('definition', 'string');
        $this->addType('pinned', 'integer');
        $this->addType('enabled', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->markFieldsForInsert();
    }

    /**
     * The stored definition as the API returns it. It was validated on write
     * (SequenceValidator); arguments are an object even when empty.
     *
     * @return AIquilaSequenceDefinition
     */
    public function getDefinitionArray(): array {
        $decoded = json_decode($this->getDefinition(), true);
        $inputs = is_array($decoded) && is_array($decoded['inputs'] ?? null) ? array_values($decoded['inputs']) : [];
        $steps = is_array($decoded) && is_array($decoded['steps'] ?? null) ? array_values($decoded['steps']) : [];
        $steps = array_map(static function (mixed $step): mixed {
            if (is_array($step) && ($step['arguments'] ?? []) === []) {
                $step['arguments'] = new \stdClass();
            }
            return $step;
        }, $steps);
        /** @var AIquilaSequenceDefinition */
        return ['inputs' => $inputs, 'steps' => $steps];
    }

    /**
     * @param array{inputs: list<array<string, mixed>>, steps: list<array<string, mixed>>} $definition
     */
    public function setDefinitionArray(array $definition): void {
        foreach ($definition['steps'] as $i => $step) {
            if (($step['arguments'] ?? []) === []) {
                $definition['steps'][$i]['arguments'] = new \stdClass();
            }
        }
        $this->setDefinition(json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return AIquilaSequence
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'scope' => $this->getScope() === self::SCOPE_INSTANCE ? self::SCOPE_INSTANCE : self::SCOPE_USER,
            'slug' => $this->getSlug(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'definition' => $this->getDefinitionArray(),
            'pinned' => $this->getPinned() === 1,
            'enabled' => $this->getEnabled() === 1,
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
