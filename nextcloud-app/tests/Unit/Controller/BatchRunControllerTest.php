<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Controller;

use OCA\AIquila\Controller\BatchRunController;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\BatchRunService;
use OCA\AIquila\Service\Exception\BatchRunLimitException;
use OCA\AIquila\Service\Exception\NoActiveRunException;
use OCA\AIquila\Service\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** How each outcome of the service reaches the client. */
class BatchRunControllerTest extends TestCase {
    private function controller(BatchRunService $service): BatchRunController {
        return new BatchRunController('aiquila', $this->createStub(IRequest::class), $service, 'alice', $this->createStub(LoggerInterface::class));
    }

    private function queuedRun(string $status = CoworkerRun::STATUS_QUEUED): CoworkerRun {
        $run = new CoworkerRun();
        $run->setId(5);
        $run->setStatus($status);
        $run->setTaskType('summarize');
        $run->appendLog(CoworkerRun::LOG_INFO, 'Queued');
        return $run;
    }

    private function serviceThrowingOnStart(\Throwable $e): BatchRunService {
        $service = $this->createStub(BatchRunService::class);
        $service->method('start')->willThrowException($e);
        return $service;
    }

    public function testCreateAnswers202WithTheRun(): void {
        $service = $this->createMock(BatchRunService::class);
        $service->expects($this->once())->method('start')
            ->with('alice', 'translate', ['targetLanguage' => 'German'], 'immediate', [1], [2])
            ->willReturn($this->queuedRun());

        $response = $this->controller($service)->create('translate', ['targetLanguage' => 'German'], 'immediate', [1], [2]);

        $this->assertSame(202, $response->getStatus());
        $this->assertSame(5, $response->getData()['id']);
        $this->assertSame('queued', $response->getData()['status']);
        $this->assertSame(30, $response->getData()['pollIntervalSeconds']);
    }

    public function testOverTheItemCapIs400NamingTheLimit(): void {
        $response = $this->controller($this->serviceThrowingOnStart(
            new BatchRunLimitException('201 entries in fileIds; a run takes at most 200 files', BatchRunLimitException::ITEMS, 200)
        ))->create('summarize', [], null, [1]);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame(200, $response->getData()['limit']);
        $this->assertStringContainsString('at most 200', $response->getData()['error']);
    }

    public function testOverTheHourlyCapIs429(): void {
        $response = $this->controller($this->serviceThrowingOnStart(
            new BatchRunLimitException('limit 12', BatchRunLimitException::RATE, 12)
        ))->create('summarize', [], null, [1]);

        $this->assertSame(429, $response->getStatus());
        $this->assertSame(12, $response->getData()['limit']);
    }

    public function testInvalidInputIs400AndAnUnreadableIdIs404(): void {
        $bad = $this->controller($this->serviceThrowingOnStart(new ValidationException('mode must be one of: batch, immediate')))
            ->create('summarize', [], 'later', [1]);
        $this->assertSame(400, $bad->getStatus());
        $this->assertSame('mode must be one of: batch, immediate', $bad->getData()['error']);

        $missing = $this->controller($this->serviceThrowingOnStart(new NotFoundException('File 9 not found')))
            ->create('summarize', [], null, [9]);
        $this->assertSame(404, $missing->getStatus());
        $this->assertSame('File 9 not found', $missing->getData()['error']);
    }

    public function testShowIncludesTheLogAnd404sForSomeoneElsesRun(): void {
        $service = $this->createStub(BatchRunService::class);
        $service->method('get')->willReturnCallback(fn (int $id) => $id === 5 ? $this->queuedRun() : throw new DoesNotExistException('no'));

        $ok = $this->controller($service)->show(5);
        $this->assertSame(200, $ok->getStatus());
        $this->assertSame('Queued', $ok->getData()['log'][0]['message']);

        $this->assertSame(404, $this->controller($service)->show(6)->getStatus());
    }

    public function testCancellingAnEndedRunIs409(): void {
        $service = $this->createStub(BatchRunService::class);
        $service->method('cancel')->willThrowException(new NoActiveRunException('ended'));

        $this->assertSame(409, $this->controller($service)->cancel(5)->getStatus());
    }
}
