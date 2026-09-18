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
 * @method string|null getState()
 * @method void setState(?string $state)
 */
class CoworkerRun extends Entity implements \JsonSerializable {
    public const STATUS_RUNNING = 'running';
    /** Submitted, waiting on work that finishes outside this process. */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR   = 'error';

    protected int $coworkerId = 0;
    protected string $userId = '';
    protected string $status = self::STATUS_RUNNING;
    protected int $itemsTotal = 0;
    protected int $itemsProcessed = 0;
    protected ?string $summary = null;
    protected ?string $error = null;
    protected int $startedAt = 0;
    protected ?int $finishedAt = null;
    /**
     * JSON scratch space for a run that has to be resumed on a later tick —
     * see the Version0014 migration. Opaque to everything but the task type
     * that wrote it.
     */
    protected ?string $state = null;

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
        $this->addType('state', 'string');
    }

    /**
     * Decoded resume state, or an empty array when there is none.
     *
     * @return array<string, mixed>
     */
    public function getDecodedState(): array {
        $raw = $this->getState();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    public function setDecodedState(array $state): void {
        if ($state === []) {
            $this->setState(null);
            return;
        }
        $encoded = json_encode($state);
        $this->setState($encoded === false ? null : $encoded);
    }

    /** The batch this run is waiting on, if it is waiting on one. */
    public function getPendingBatchId(): ?string {
        $id = $this->getDecodedState()['batch_id'] ?? null;
        return is_string($id) ? $id : null;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'coworkerId' => $this->getCoworkerId(),
            'userId' => $this->getUserId(),
            'status' => $this->getStatus(),
            'itemsTotal' => $this->getItemsTotal(),
            'itemsProcessed' => $this->getItemsProcessed(),
            'summary' => $this->getSummary(),
            'error' => $this->getError(),
            'startedAt' => $this->getStartedAt(),
            'finishedAt' => $this->getFinishedAt(),
            // The blob itself stays server-side: run history is user-facing,
            // and nothing in the UI has any use for a custom_id map.
            'pendingBatchId' => $this->getPendingBatchId(),
        ];
    }
}
