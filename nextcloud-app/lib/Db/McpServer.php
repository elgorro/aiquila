<?php

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getAuthType()
 * @method void setAuthType(string $authType)
 * @method string|null getAuthToken()
 * @method void setAuthToken(?string $authToken)
 * @method bool getIsEnabled()
 * @method void setIsEnabled(bool $isEnabled)
 * @method string|null getLastStatus()
 * @method void setLastStatus(?string $lastStatus)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method int|null getToolCount()
 * @method void setToolCount(?int $toolCount)
 * @method int|null getLastConnectedAt()
 * @method void setLastConnectedAt(?int $lastConnectedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class McpServer extends Entity {
    protected string $displayName = '';
    protected string $url = '';
    protected string $authType = 'none';
    protected ?string $authToken = null;
    protected bool $isEnabled = true;
    protected ?string $lastStatus = null;
    protected ?string $lastError = null;
    protected ?int $toolCount = null;
    protected ?int $lastConnectedAt = null;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('displayName', 'string');
        $this->addType('url', 'string');
        $this->addType('authType', 'string');
        $this->addType('authToken', 'string');
        $this->addType('isEnabled', 'boolean');
        $this->addType('lastStatus', 'string');
        $this->addType('lastError', 'string');
        $this->addType('toolCount', 'integer');
        $this->addType('lastConnectedAt', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
