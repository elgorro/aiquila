<?php

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Anthropic\Messages\Batches\MessageBatch;
use Anthropic\Messages\Batches\MessageBatchIndividualResponse;
use Anthropic\Messages\Batches\MessageBatchRequestCounts;
use Anthropic\Messages\Batches\MessageBatchSucceededResult;
use Anthropic\Messages\Message;
use Anthropic\Messages\Usage;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Subclass for ClaudeSDKService::summarizeViaBatch() coverage.
 * Stubs callBatchCreate / callBatchRetrieve / callBatchResults and
 * suppresses the inter-poll sleep so the test runs in milliseconds.
 */
class BatchTestableService extends ClaudeSDKService {
    /** @var list<string> processingStatus values returned in order across retrieve calls */
    public array $retrieveStatuses = ['ended'];
    public string $resultText = 'a concise summary';
    public int $createCalls = 0;
    public int $retrieveCalls = 0;
    public int $sleepCalls = 0;
    public ?array $lastBatchRequests = null;

    protected function getClient(?string $userId = null): Client {
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }

    protected function callBatchCreate(Client $client, array $requests): MessageBatch {
        $this->createCalls++;
        $this->lastBatchRequests = $requests;
        return $this->makeBatch('in_progress');
    }

    protected function callBatchRetrieve(Client $client, string $batchId): MessageBatch {
        $idx = min($this->retrieveCalls, count($this->retrieveStatuses) - 1);
        $status = $this->retrieveStatuses[$idx];
        $this->retrieveCalls++;
        return $this->makeBatch($status);
    }

    protected function callBatchResults(Client $client, string $batchId): iterable {
        $customId = $this->lastBatchRequests[0]['custom_id'];
        yield $this->makeIndividualResponse($customId, $this->resultText);
    }

    protected function sleepBetweenBatchPolls(): void {
        $this->sleepCalls++;
    }

    private function makeBatch(string $status): MessageBatch {
        $counts = MessageBatchRequestCounts::with(
            canceled: 0, errored: 0, expired: 0,
            processing: $status === 'ended' ? 0 : 1,
            succeeded: $status === 'ended' ? 1 : 0,
        );
        return MessageBatch::with(
            id: 'batch_test',
            archivedAt: null,
            cancelInitiatedAt: null,
            createdAt: new \DateTime(),
            endedAt: $status === 'ended' ? new \DateTime() : null,
            expiresAt: new \DateTime('+24 hours'),
            processingStatus: $status,
            requestCounts: $counts,
            resultsURL: $status === 'ended' ? 'https://example.invalid/r' : null,
        );
    }

    private function makeIndividualResponse(string $customId, string $text): MessageBatchIndividualResponse {
        $msg = (new \ReflectionClass(Message::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionClass($msg);

        $textObj = new \stdClass();
        $textObj->type = 'text';
        $textObj->text = $text;

        foreach (['content' => [$textObj], 'stopReason' => 'end_turn'] as $prop => $val) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($msg, $val);
        }
        $usage = Usage::with(null, null, null, null, 5, 7, null, null, null);
        $up = $ref->getProperty('usage');
        $up->setAccessible(true);
        $up->setValue($msg, $usage);

        return MessageBatchIndividualResponse::with(
            customID: $customId,
            result: MessageBatchSucceededResult::with(message: $msg),
        );
    }
}

class ClaudeSDKServiceBatchTest extends TestCase {
    private function makeService(): BatchTestableService {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default) => $default);
        $config->method('getUserValue')->willReturnCallback(fn ($u, $a, $k, $d) => $d);
        $logger = $this->createMock(LoggerInterface::class);
        $credentials = $this->createMock(CredentialService::class);
        $credentials->method('getApiKey')->willReturn('test-key');
        $cache = $this->createMock(ICache::class);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);
        return new BatchTestableService($config, $logger, $credentials, $cacheFactory);
    }

    public function testBatchSummarySucceedsOnFirstPoll(): void {
        $svc = $this->makeService();
        $svc->retrieveStatuses = ['ended'];

        $progress = [];
        $result = $svc->summarizeViaBatch('long content here', null, function ($p) use (&$progress) {
            $progress[] = $p;
        });

        $this->assertSame('a concise summary', $result['response']);
        $this->assertSame(5, $result['usage']['input_tokens']);
        $this->assertSame(7, $result['usage']['output_tokens']);
        $this->assertSame([], $result['citations']);
        $this->assertSame(1, $svc->createCalls);
        $this->assertSame(1, $svc->retrieveCalls);
        $this->assertSame(0, $svc->sleepCalls);
        $this->assertNotEmpty($progress);
        $this->assertGreaterThanOrEqual(0.9, end($progress));
    }

    public function testBatchSummaryPollsUntilEnded(): void {
        $svc = $this->makeService();
        $svc->retrieveStatuses = ['in_progress', 'in_progress', 'ended'];

        $result = $svc->summarizeViaBatch('content', null, null);

        $this->assertSame('a concise summary', $result['response']);
        $this->assertSame(3, $svc->retrieveCalls);
        $this->assertSame(2, $svc->sleepCalls); // two waits between three polls
    }

    public function testBatchRequestShapeMatchesSummaryPrompt(): void {
        $svc = $this->makeService();
        $svc->summarizeViaBatch('document body', null, null);

        $this->assertCount(1, $svc->lastBatchRequests);
        $req = $svc->lastBatchRequests[0];
        $this->assertStringStartsWith('aiquila-summary-', $req['custom_id']);
        $this->assertArrayHasKey('maxTokens', $req['params']);
        $this->assertArrayHasKey('messages', $req['params']);
        $this->assertSame('user', $req['params']['messages'][0]['role']);
        $this->assertStringContainsString('Summarize', $req['params']['messages'][0]['content']);
        $this->assertStringContainsString('document body', $req['params']['messages'][0]['content']);
    }
}
