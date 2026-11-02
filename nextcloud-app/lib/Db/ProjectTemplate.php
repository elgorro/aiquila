<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The fields a new project is copied from.
 *
 * Creating a project from a template copies them; later edits to the template
 * never reach projects made from it. `scope` is `user` or `instance`, as on
 * Prompt. `paths` is a JSON list of `{path, pathType}` suggestions.
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
 * @method string|null getSystemPrompt()
 * @method void setSystemPrompt(?string $systemPrompt)
 * @method string|null getPaths()
 * @method void setPaths(?string $paths)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 *
 * @psalm-type TTemplatePath = array{path: string, pathType: 'file'|'directory'}
 */
class ProjectTemplate extends Entity implements \JsonSerializable {
    use InsertsFullRow;

    protected string $scope = Prompt::SCOPE_USER;
    protected string $userId = '';
    protected string $title = '';
    protected ?string $description = null;
    protected ?string $systemPrompt = null;
    protected ?string $paths = null;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('scope', 'string');
        $this->addType('userId', 'string');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('systemPrompt', 'string');
        $this->addType('paths', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->markFieldsForInsert();
    }

    /**
     * The stored path suggestions; malformed entries are dropped.
     *
     * @return list<array{path: string, pathType: 'file'|'directory'}>
     */
    public function getPathList(): array {
        $raw = $this->getPaths();
        $decoded = $raw !== null && $raw !== '' ? json_decode($raw, true) : null;
        return self::normalizePaths(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<array-key, mixed> $paths
     */
    public function setPathList(array $paths): void {
        $list = self::normalizePaths($paths);
        $this->setPaths($list === [] ? null : (json_encode($list) ?: null));
    }

    /**
     * Keep entries that carry a non-empty path; anything but `directory` is a file.
     *
     * @param array<array-key, mixed> $paths
     * @return list<array{path: string, pathType: 'file'|'directory'}>
     */
    public static function normalizePaths(array $paths): array {
        $out = [];
        foreach ($paths as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || trim($entry['path']) === '') {
                continue;
            }
            $out[] = [
                'path' => trim($entry['path']),
                'pathType' => ($entry['pathType'] ?? 'file') === 'directory' ? 'directory' : 'file',
            ];
        }
        return $out;
    }

    /**
     * @return array{id: int, scope: string, title: string, description: ?string, systemPrompt: ?string, paths: list<array{path: string, pathType: 'file'|'directory'}>, createdAt: int, updatedAt: int}
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'scope' => $this->getScope(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'systemPrompt' => $this->getSystemPrompt(),
            'paths' => $this->getPathList(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
