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
 * @method string|null getLog()
 * @method void setLog(?string $log)
 *
 * @psalm-type CoworkerRunLogEntry = array{ts: int, level: string, message: string, item: string|null}
 */
class CoworkerRun extends Entity implements \JsonSerializable {
    use InsertsFullRow;

    public const STATUS_RUNNING = 'running';
    /** Submitted, waiting on work that finishes outside this process. */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR   = 'error';

    public const LOG_INFO = 'info';
    public const LOG_WARN = 'warn';
    public const LOG_ERROR = 'error';
    /** Entries kept per run; older ones are dropped first. */
    public const LOG_LIMIT = 500;
    /** Longest message kept, in characters, so a run row stays bounded. */
    private const LOG_MESSAGE_MAX = 500;

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
    /** JSON list of log entries; see appendLog(). Never sent by the run list. */
    protected ?string $log = null;

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
        $this->addType('log', 'string');
        $this->markFieldsForInsert();
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

    /**
     * Add an entry to the run's log. Persisted with the next update of the
     * run row — the progress callback and the end of the run both write it.
     * Keeps the newest LOG_LIMIT entries.
     *
     * @param 'info'|'warn'|'error' $level one of the LOG_INFO, LOG_WARN, LOG_ERROR constants
     * @param string|null $item the file or object the entry is about, as the user sees its path
     */
    public function appendLog(string $level, string $message, ?string $item = null, ?int $ts = null): void {
        $log = $this->getDecodedLog();
        $log[] = [
            'ts' => $ts ?? time(),
            'level' => $level,
            'message' => mb_strlen($message) > self::LOG_MESSAGE_MAX ? mb_substr($message, 0, self::LOG_MESSAGE_MAX - 1) . '…' : $message,
            'item' => $item,
        ];
        if (count($log) > self::LOG_LIMIT) {
            $log = array_slice($log, -self::LOG_LIMIT);
        }
        $encoded = json_encode($log);
        $this->setLog($encoded === false ? null : $encoded);
    }

    /** @return list<CoworkerRunLogEntry> */
    public function getDecodedLog(): array {
        $raw = $this->getLog();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !is_string($entry['message'] ?? null)) {
                continue;
            }
            $entries[] = [
                'ts' => (int)($entry['ts'] ?? 0),
                'level' => (string)($entry['level'] ?? self::LOG_INFO),
                'message' => $entry['message'],
                'item' => is_string($entry['item'] ?? null) ? $entry['item'] : null,
            ];
        }
        return $entries;
    }

    /**
     * One run in full, for GET …/runs/{runId}: the list shape plus its log.
     *
     * @return array<string, mixed>
     */
    public function jsonSerializeDetail(): array {
        return $this->jsonSerialize() + ['log' => $this->getDecodedLog()];
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
