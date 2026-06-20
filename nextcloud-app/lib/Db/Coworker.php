<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int|null getPromptId()
 * @method void setPromptId(?int $promptId)
 * @method string|null getCustomPrompt()
 * @method void setCustomPrompt(?string $customPrompt)
 * @method string|null getModel()
 * @method void setModel(?string $model)
 * @method string|null getOwnerApp()
 * @method void setOwnerApp(?string $ownerApp)
 * @method string getTaskType()
 * @method void setTaskType(string $taskType)
 * @method int getPaused()
 * @method void setPaused(int $paused)
 * @method string|null getOptions()
 * @method void setOptions(?string $options)
 * @method string getCronSchedule()
 * @method void setCronSchedule(string $cronSchedule)
 * @method string getInputType()
 * @method void setInputType(string $inputType)
 * @method string|null getInputPath()
 * @method void setInputPath(?string $inputPath)
 * @method string getOutputType()
 * @method void setOutputType(string $outputType)
 * @method string|null getOutputPath()
 * @method void setOutputPath(?string $outputPath)
 * @method int getIsActive()
 * @method void setIsActive(int $isActive)
 * @method int|null getLastRunAt()
 * @method void setLastRunAt(?int $lastRunAt)
 * @method int|null getNextRunAt()
 * @method void setNextRunAt(?int $nextRunAt)
 * @method string|null getLastStatus()
 * @method void setLastStatus(?string $lastStatus)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Coworker extends Entity implements \JsonSerializable {
    protected string $userId = '';
    protected string $title = '';
    protected ?string $description = null;
    protected ?int $promptId = null;
    protected ?string $customPrompt = null;
    protected ?string $model = null;
    protected ?string $ownerApp = null;
    protected string $taskType = 'vision:classify';
    protected int $paused = 0;
    protected ?string $options = null;
    protected string $cronSchedule = '';
    protected string $inputType = '';
    protected ?string $inputPath = null;
    protected string $outputType = '';
    protected ?string $outputPath = null;
    protected int $isActive = 1;
    protected ?int $lastRunAt = null;
    protected ?int $nextRunAt = null;
    protected ?string $lastStatus = null;
    protected ?string $lastError = null;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('userId', 'string');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('promptId', 'integer');
        $this->addType('customPrompt', 'string');
        $this->addType('model', 'string');
        $this->addType('ownerApp', 'string');
        $this->addType('taskType', 'string');
        $this->addType('paused', 'integer');
        $this->addType('options', 'string');
        $this->addType('cronSchedule', 'string');
        $this->addType('inputType', 'string');
        $this->addType('inputPath', 'string');
        $this->addType('outputType', 'string');
        $this->addType('outputPath', 'string');
        $this->addType('isActive', 'integer');
        $this->addType('lastRunAt', 'integer');
        $this->addType('nextRunAt', 'integer');
        $this->addType('lastStatus', 'string');
        $this->addType('lastError', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    public function jsonSerialize(): array {
        $options = json_decode($this->getOptions() ?? '', true);
        if (!is_array($options)) {
            $options = [];
        }
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'promptId' => $this->getPromptId(),
            'customPrompt' => $this->getCustomPrompt(),
            'model' => $this->getModel(),
            'ownerApp' => $this->getOwnerApp(),
            'taskType' => $this->getTaskType(),
            'paused' => (bool)$this->getPaused(),
            'options' => $options,
            'cronSchedule' => $this->getCronSchedule(),
            'inputType' => $this->getInputType(),
            'inputPath' => $this->getInputPath(),
            'outputType' => $this->getOutputType(),
            'outputPath' => $this->getOutputPath(),
            'isActive' => (bool)$this->getIsActive(),
            'lastRunAt' => $this->getLastRunAt(),
            'nextRunAt' => $this->getNextRunAt(),
            'lastStatus' => $this->getLastStatus(),
            'lastError' => $this->getLastError(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
