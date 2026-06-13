<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCoworkerId()
 * @method void setCoworkerId(int $coworkerId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getItemsTotal()
 * @method void setItemsTotal(int $itemsTotal)
 * @method int getItemsProcessed()
 * @method void setItemsProcessed(int $itemsProcessed)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method int|null getFinishedAt()
 * @method void setFinishedAt(?int $finishedAt)
 */
class CoworkerRun extends Entity {
    protected int $coworkerId = 0;
    protected string $userId = '';
    protected string $status = 'running';
    protected int $itemsTotal = 0;
    protected int $itemsProcessed = 0;
    protected ?string $summary = null;
    protected ?string $error = null;
    protected int $startedAt = 0;
    protected ?int $finishedAt = null;

    public function __construct() {
        $this->addType('coworkerId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('status', 'string');
        $this->addType('itemsTotal', 'integer');
        $this->addType('itemsProcessed', 'integer');
        $this->addType('summary', 'string');
        $this->addType('error', 'string');
        $this->addType('startedAt', 'integer');
        $this->addType('finishedAt', 'integer');
    }
}
