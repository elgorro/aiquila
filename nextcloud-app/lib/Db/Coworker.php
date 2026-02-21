<?php

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
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
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
class Coworker extends Entity {
    protected string $userId = '';
    protected string $title = '';
    protected ?string $description = null;
    protected ?int $promptId = null;
    protected ?string $customPrompt = null;
    protected ?string $model = null;
    protected string $cronSchedule = '';
    protected string $inputType = '';
    protected ?string $inputPath = null;
    protected string $outputType = '';
    protected ?string $outputPath = null;
    protected bool $isActive = true;
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
        $this->addType('cronSchedule', 'string');
        $this->addType('inputType', 'string');
        $this->addType('inputPath', 'string');
        $this->addType('outputType', 'string');
        $this->addType('outputPath', 'string');
        $this->addType('isActive', 'boolean');
        $this->addType('lastRunAt', 'integer');
        $this->addType('nextRunAt', 'integer');
        $this->addType('lastStatus', 'string');
        $this->addType('lastError', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
