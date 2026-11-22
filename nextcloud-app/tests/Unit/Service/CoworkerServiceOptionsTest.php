<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Cowork\CoworkerTaskType;
use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCA\AIquila\Service\CoworkerService;
use OCA\AIquila\Service\Exception\ValidationException;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A task type rejects bad options with InvalidArgumentException. That is the
 * caller's input, so creating or updating a coworker answers with a
 * ValidationException (a 400), not a server error.
 */
class CoworkerServiceOptionsTest extends TestCase {
    public function testATaskTypesOptionErrorIsAValidationError(): void {
        $type = $this->createStub(CoworkerTaskType::class);
        $type->method('getId')->willReturn('docs:ask');
        $type->method('validateOptions')->willThrowException(new \InvalidArgumentException('outputSuffix is required'));

        $mapper = $this->createStub(CoworkerMapper::class);
        $mapper->method('insert')->willReturnArgument(0);

        $service = new CoworkerService(
            $mapper,
            $this->createStub(CoworkerRunMapper::class),
            new CoworkerTaskRegistry([$type]),
            $this->createStub(LLMProviderFactory::class),
            $this->createStub(ITimeFactory::class),
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageIsOrContains('outputSuffix is required');
        $service->create('alice', ['title' => 'x', 'task_type' => 'docs:ask', 'input_path' => '/In', 'options' => ['outputSuffix' => '../evil']]);
    }
}
