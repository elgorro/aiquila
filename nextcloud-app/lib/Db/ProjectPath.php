<?php

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method string getPathType()
 * @method void setPathType(string $pathType)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class ProjectPath extends Entity implements \JsonSerializable {
    protected int $projectId = 0;
    protected string $path = '';
    protected string $pathType = 'file';
    protected int $createdAt = 0;

    public function __construct() {
        $this->addType('projectId', 'integer');
        $this->addType('path', 'string');
        $this->addType('pathType', 'string');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'projectId' => $this->getProjectId(),
            'path' => $this->getPath(),
            'pathType' => $this->getPathType(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
